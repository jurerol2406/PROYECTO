"""
limpiar.py
Pipeline de preprocesado de imagen para planos de empresa:
  1. Escala de grises
  2. CLAHE (contraste adaptativo, mejor para planos)
  3. GaussianBlur adaptativo
  4. Umbralización adaptativa + Canny combinados
  5. Morphological Close (cierra paredes)
  6. Dilatación controlada
  7. Eliminación de ruido de pequeños contornos
"""

import cv2
import numpy as np


def limpiar_imagen(img_bgr: np.ndarray) -> np.ndarray:
    """
    Aplica el pipeline completo de limpieza y detección de bordes
    optimizado para planos arquitectónicos de oficinas.

    Args:
        img_bgr: Imagen BGR de entrada.

    Returns:
        Imagen binaria de bordes (uint8, 0 o 255).
    """
    if img_bgr is None or img_bgr.size == 0:
        raise ValueError("Imagen vacía o inválida")

    # ── 1. Escala de grises ─────────────────────────────────
    if len(img_bgr.shape) == 2:
        gray = img_bgr.copy()
    elif img_bgr.shape[2] == 4:
        gray = cv2.cvtColor(img_bgr, cv2.COLOR_BGRA2GRAY)
    else:
        gray = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)

    # ── 2. CLAHE - mejor contraste para planos con fondo claro ──
    clahe = cv2.createCLAHE(clipLimit=3.0, tileGridSize=(8, 8))
    gray = clahe.apply(gray)

    # ── 3. Blur adaptativo según resolución ─────────────────
    h, w = gray.shape[:2]
    blur_k = 5 if max(h, w) < 1000 else 7
    blur = cv2.GaussianBlur(gray, (blur_k, blur_k), 0)

    # ── 4a. Canny con umbrales automáticos ──────────────────
    v = np.median(blur)
    sigma = 0.25
    lower = int(max(0, (1.0 - sigma) * v))
    upper = int(min(255, (1.0 + sigma) * v))
    canny = cv2.Canny(blur, lower, upper)

    # ── 4b. Umbralización adaptativa (captura paredes finas) ─
    thresh = cv2.adaptiveThreshold(
        blur,
        255,
        cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
        cv2.THRESH_BINARY_INV,
        blockSize=15,
        C=4,
    )

    # ── 4c. Combinar Canny + umbralización ──────────────────
    bordes = cv2.bitwise_or(canny, thresh)

    # ── 5. Morphological Close (cierra gaps en paredes) ─────
    # Kernel más grande para planos con paredes gruesas
    kernel_close = cv2.getStructuringElement(cv2.MORPH_RECT, (9, 9))
    bordes = cv2.morphologyEx(bordes, cv2.MORPH_CLOSE, kernel_close)

    # ── 6. Dilatación suave ──────────────────────────────────
    kernel_dil = cv2.getStructuringElement(cv2.MORPH_RECT, (3, 3))
    bordes = cv2.dilate(bordes, kernel_dil, iterations=1)

    # ── 7. Eliminación de ruido: quitar contornos pequeños ──
    bordes = _eliminar_ruido(bordes)

    return bordes


def _eliminar_ruido(bordes: np.ndarray) -> np.ndarray:
    """Elimina pequeños contornos aislados que no son paredes."""
    h, w = bordes.shape[:2]
    area_min = (h * w) * 0.0002  # 0.02% del área total

    contornos, _ = cv2.findContours(bordes, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    mask = np.zeros_like(bordes)

    for cnt in contornos:
        if cv2.contourArea(cnt) >= area_min:
            cv2.drawContours(mask, [cnt], -1, 255, thickness=cv2.FILLED)

    # Combinar con bordes originales para mantener contornos internos
    resultado = cv2.bitwise_and(bordes, bordes, mask=mask)
    return resultado
