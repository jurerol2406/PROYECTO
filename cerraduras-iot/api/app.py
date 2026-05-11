"""
app.py – API Cerraduras IoT (Empresa)
Analiza planos arquitectónicos con OpenCV.
Detecta oficinas/departamentos y puertas. Devuelve JSON estructurado.
"""

import uuid

import cv2
import numpy as np
from fastapi import FastAPI, UploadFile, File, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse, Response

from procesamiento.limpiar import limpiar_imagen
from procesamiento.habitaciones import detectar_habitaciones
from procesamiento.formato import generar_json_estructurado

app = FastAPI(
    title="API Cerraduras IoT – Empresa",
    description="Análisis de planos de oficinas con OpenCV. Detecta departamentos y genera JSON estructurado.",
    version="3.1",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)


def _preparar_imagen(img: np.ndarray) -> np.ndarray:
    """Normaliza canales y escala la imagen a un tamaño manejable."""
    if len(img.shape) == 2:
        img = cv2.cvtColor(img, cv2.COLOR_GRAY2BGR)
    elif img.shape[2] == 4:
        img = cv2.cvtColor(img, cv2.COLOR_BGRA2BGR)

    h, w = img.shape[:2]
    max_dim = 1800
    if max(h, w) > max_dim:
        scale = max_dim / max(h, w)
        img = cv2.resize(img, (int(w * scale), int(h * scale)), interpolation=cv2.INTER_AREA)

    # Ajuste de contraste adaptativo
    lab = cv2.cvtColor(img, cv2.COLOR_BGR2LAB)
    l_ch, a_ch, b_ch = cv2.split(lab)
    clahe = cv2.createCLAHE(clipLimit=2.5, tileGridSize=(8, 8))
    l_ch = clahe.apply(l_ch)
    img = cv2.cvtColor(cv2.merge([l_ch, a_ch, b_ch]), cv2.COLOR_LAB2BGR)

    return img


def _leer_imagen(contenido: bytes) -> np.ndarray:
    """Decodifica bytes de imagen a array BGR."""
    arr = np.frombuffer(contenido, np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_UNCHANGED)
    if img is None:
        raise HTTPException(
            status_code=415,
            detail="No se pudo decodificar la imagen. Asegúrate de subir JPG o PNG.",
        )
    return img


# ─────────────────────────────────────────────────────────────
# Endpoint principal: procesar plano → JSON estructurado
# ─────────────────────────────────────────────────────────────
@app.post(
    "/procesar",
    summary="Procesa un plano de empresa y devuelve JSON estructurado",
    response_description="JSON con house_id, name, rooms[], doors[]",
)
async def procesar_plano(
    file: UploadFile = File(..., description="Imagen del plano (JPG o PNG)"),
    nombre: str = "Empresa",
):
    filename = (file.filename or "").lower()
    if not any(filename.endswith(ext) for ext in [".jpg", ".jpeg", ".png"]):
        raise HTTPException(status_code=400, detail="Formato no válido. Solo JPG o PNG.")

    contenido = await file.read()
    if len(contenido) > 20 * 1024 * 1024:
        raise HTTPException(status_code=413, detail="Imagen demasiado grande. Máximo 20 MB.")

    img = _leer_imagen(contenido)
    img_proc = _preparar_imagen(img)

    bordes = limpiar_imagen(img_proc)
    habitaciones = detectar_habitaciones(bordes)
    resultado = generar_json_estructurado(
        habitaciones, img_proc.shape, bordes, nombre_empresa=nombre
    )

    # Generar house_id único por plano
    resultado["house_id"] = str(uuid.uuid4())

    return JSONResponse(content=resultado)


# ─────────────────────────────────────────────────────────────
# Visualización: devuelve imagen con oficinas coloreadas
# ─────────────────────────────────────────────────────────────
@app.post("/visualizar", summary="Devuelve imagen JPEG con oficinas coloreadas")
async def visualizar_plano(file: UploadFile = File(...)):
    filename = (file.filename or "").lower()
    if not any(filename.endswith(ext) for ext in [".jpg", ".jpeg", ".png"]):
        raise HTTPException(status_code=400, detail="Solo JPG o PNG.")

    contenido = await file.read()
    img = _leer_imagen(contenido)
    img_proc = _preparar_imagen(img)
    bordes = limpiar_imagen(img_proc)
    habitaciones = detectar_habitaciones(bordes)

    salida = img_proc.copy()
    rng = np.random.default_rng(42)
    for i, (cnt, bbox) in enumerate(habitaciones):
        color = tuple(int(c) for c in rng.integers(80, 220, 3))
        x, y, w, h = bbox
        cv2.rectangle(salida, (x, y), (x + w, y + h), color, 2)
        cv2.putText(
            salida,
            f"Of.{i + 1}",
            (x + 5, y + 20),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.6,
            color,
            2,
        )

    _, buf = cv2.imencode(".jpg", salida, [cv2.IMWRITE_JPEG_QUALITY, 90])
    return Response(content=buf.tobytes(), media_type="image/jpeg")


# ─────────────────────────────────────────────────────────────
# Health check
# ─────────────────────────────────────────────────────────────
@app.get("/health")
def health():
    return {"status": "ok", "service": "cerraduras-api-empresa", "version": "3.1"}
