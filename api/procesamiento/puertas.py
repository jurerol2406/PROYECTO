"""
puertas.py
Detección de puertas/accesos en planos de empresa.

Estrategia mejorada:
  1. Inspección de franjas en cada lado del bounding box
  2. Proyección 1D + detección de gaps (zonas sin borde = apertura)
  3. Verificación de gap mínimo y posición válida
  4. Fallback geométrico inteligente según proporciones
  5. Máximo 2 puertas por oficina para planos realistas
"""

import cv2
import numpy as np
from typing import List, Dict, Tuple


def detectar_puertas(
    bbox: Tuple[int, int, int, int],
    idx_room: int,
    bordes_img: np.ndarray,
) -> List[Dict]:
    """
    Detecta puertas/accesos en el bounding box de una oficina.

    Args:
        bbox: (x, y, w, h) del bounding box en píxeles.
        idx_room: Índice de la oficina (para generar IDs únicos).
        bordes_img: Imagen binaria de bordes.

    Returns:
        Lista de dicts con keys: id, position, offset (0–100).
    """
    x, y, w, h = bbox
    img_h, img_w = bordes_img.shape[:2]

    FRANJA = max(5, min(14, min(w, h) // 8))
    puertas = []
    door_id_base = idx_room * 10 + 1

    lados = {
        "top":    _extraer_franja(bordes_img, "top",    x, y, w, h, img_w, img_h, FRANJA),
        "bottom": _extraer_franja(bordes_img, "bottom", x, y, w, h, img_w, img_h, FRANJA),
        "left":   _extraer_franja(bordes_img, "left",   x, y, w, h, img_w, img_h, FRANJA),
        "right":  _extraer_franja(bordes_img, "right",  x, y, w, h, img_w, img_h, FRANJA),
    }

    door_count = 0

    for pos_name, franja_img in lados.items():
        if franja_img is None or franja_img.size == 0:
            continue

        # Proyección 1D
        if pos_name in ("top", "bottom"):
            proyeccion = franja_img.sum(axis=0).astype(np.float64)
        else:
            proyeccion = franja_img.sum(axis=1).astype(np.float64)

        longitud = len(proyeccion)
        if longitud < 12:
            continue

        p_max = proyeccion.max()
        if p_max == 0:
            continue

        proyeccion = proyeccion / p_max

        # Suavizado para eliminar ruido
        kernel_smooth = np.ones(3) / 3
        proyeccion = np.convolve(proyeccion, kernel_smooth, mode='same')

        # Detección de gap con percentil adaptativo
        umbral = min(np.percentile(proyeccion, 30), 0.35)
        es_gap = proyeccion < umbral

        # Encontrar el mayor gap continuo
        mejor_inicio, mejor_longitud = _mayor_gap(es_gap)

        min_gap = max(int(longitud * 0.06), 4)

        if mejor_longitud >= min_gap and mejor_inicio >= 0:
            # Verificar que el gap no está en el borde extremo (artefacto)
            margen = int(longitud * 0.05)
            if mejor_inicio < margen or (mejor_inicio + mejor_longitud) > (longitud - margen):
                continue

            centro = mejor_inicio + mejor_longitud // 2
            offset = round((centro / longitud) * 100)
            offset = max(10, min(90, offset))

            puertas.append({
                "id": f"door-{door_id_base + door_count}",
                "position": pos_name,
                "offset": offset,
            })
            door_count += 1

            if door_count >= 2:
                break

    if not puertas:
        puertas = _puertas_geometricas(bbox, door_id_base)

    return puertas


def _extraer_franja(
    img: np.ndarray,
    lado: str,
    x: int, y: int, w: int, h: int,
    img_w: int, img_h: int,
    franja: int,
) -> np.ndarray:
    """Extrae la franja de píxeles del lado indicado."""
    if lado == "top":
        return img[max(0, y - franja):min(img_h, y + franja), max(0, x):min(img_w, x + w)]
    elif lado == "bottom":
        return img[max(0, y + h - franja):min(img_h, y + h + franja), max(0, x):min(img_w, x + w)]
    elif lado == "left":
        return img[max(0, y):min(img_h, y + h), max(0, x - franja):min(img_w, x + franja)]
    elif lado == "right":
        return img[max(0, y):min(img_h, y + h), max(0, x + w - franja):min(img_w, x + w + franja)]
    return np.array([])


def _mayor_gap(es_gap: np.ndarray) -> Tuple[int, int]:
    """Encuentra el gap más largo en la secuencia booleana."""
    mejor_inicio = -1
    mejor_longitud = 0
    actual_inicio = -1
    actual_longitud = 0

    for i, g in enumerate(es_gap):
        if g:
            if actual_inicio == -1:
                actual_inicio = i
            actual_longitud += 1
            if actual_longitud > mejor_longitud:
                mejor_longitud = actual_longitud
                mejor_inicio = actual_inicio
        else:
            actual_inicio = -1
            actual_longitud = 0

    return mejor_inicio, mejor_longitud


def _puertas_geometricas(
    bbox: Tuple[int, int, int, int],
    door_id_base: int,
) -> List[Dict]:
    """
    Genera puertas automáticas basadas en la geometría de la oficina.
    Se usa cuando no se detectan gaps en los bordes.
    """
    _, _, w, h = bbox
    puertas = []

    if w >= h:
        puertas.append({"id": f"door-{door_id_base}", "position": "right", "offset": 50})
        if w > h * 1.5:
            puertas.append({"id": f"door-{door_id_base + 1}", "position": "bottom", "offset": 30})
    else:
        puertas.append({"id": f"door-{door_id_base}", "position": "bottom", "offset": 50})
        if h > w * 1.5:
            puertas.append({"id": f"door-{door_id_base + 1}", "position": "right", "offset": 70})

    return puertas
