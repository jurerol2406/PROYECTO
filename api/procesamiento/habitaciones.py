"""
habitaciones.py
Detección real de contornos de oficinas/departamentos a partir de una imagen de bordes.
Devuelve lista de (contorno, bounding_box) filtrada y deduplicada.
Estrategia mejorada:
  - Multi-pass: RETR_CCOMP + RETR_EXTERNAL
  - Filtrado inteligente por área, aspect ratio y solidez
  - Deduplicación por IoU con umbral adaptativo
  - Fallback robusto por umbralización
"""

import cv2
import numpy as np
from typing import List, Tuple


def detectar_habitaciones(
    bordes: np.ndarray,
) -> List[Tuple[np.ndarray, Tuple[int, int, int, int]]]:
    """
    Detecta oficinas/departamentos usando contornos reales sobre la imagen de bordes.

    Args:
        bordes: Imagen binaria de bordes (salida de limpiar_imagen).

    Returns:
        Lista de (contorno, (x, y, w, h)) ordenada por posición.
    """
    if bordes is None or bordes.size == 0:
        return []

    h_total, w_total = bordes.shape[:2]
    area_total = h_total * w_total

    candidatos = []

    # ── Pass 1: RETR_CCOMP ──────────────────────────────────
    contornos_ccomp, hier = cv2.findContours(bordes, cv2.RETR_CCOMP, cv2.CHAIN_APPROX_SIMPLE)
    _agregar_candidatos(contornos_ccomp, candidatos, area_total)

    # ── Pass 2: RETR_TREE ────────────────────────────────────
    contornos_tree, _ = cv2.findContours(bordes, cv2.RETR_TREE, cv2.CHAIN_APPROX_SIMPLE)
    _agregar_candidatos(contornos_tree, candidatos, area_total)

    if not candidatos:
        return _fallback_umbral(bordes)

    # Ordenar por área descendente
    candidatos.sort(key=lambda t: t[2], reverse=True)

    # Deduplicación por IoU adaptativa
    seleccionados: List[Tuple[np.ndarray, Tuple[int, int, int, int]]] = []
    bboxes_sel: List[Tuple[int, int, int, int]] = []

    for cnt, bbox, area in candidatos:
        iou_thresh = 0.5 if area < area_total * 0.05 else 0.6
        if not _solapado_con_existentes(bbox, bboxes_sel, threshold=iou_thresh):
            seleccionados.append((cnt, bbox))
            bboxes_sel.append(bbox)

    # Fallback si no se detectó nada
    if not seleccionados:
        seleccionados = _fallback_umbral(bordes)

    # Limitar a un número razonable de oficinas (máx. 20)
    seleccionados = seleccionados[:20]

    # Ordenar por posición: arriba-izq → abajo-der
    seleccionados.sort(key=lambda t: (t[1][1] // (h_total // 5)) * w_total + t[1][0])

    return seleccionados


def _agregar_candidatos(
    contornos: List[np.ndarray],
    candidatos: list,
    area_total: float,
) -> None:
    """Filtra contornos y los agrega a la lista de candidatos."""
    for cnt in contornos:
        area = cv2.contourArea(cnt)

        # Filtro de área relativa (0.4% – 85% del área total)
        if area < area_total * 0.004 or area > area_total * 0.85:
            continue

        x, y, w, h = cv2.boundingRect(cnt)

        # Dimensiones mínimas en píxeles
        if w < 25 or h < 25:
            continue

        # Aspect ratio: descartar elementos muy alargados (líneas, textos)
        aspect = max(w, h) / (min(w, h) + 1e-6)
        if aspect > 10:
            continue

        # Solidez (area / area_bbox): descartar formas muy irregulares
        area_bbox = w * h
        solidez = area / area_bbox if area_bbox > 0 else 0
        if solidez < 0.15:
            continue

        candidatos.append((cnt, (x, y, w, h), area))


def _solapado_con_existentes(
    bbox: Tuple[int, int, int, int],
    existentes: List[Tuple[int, int, int, int]],
    threshold: float,
) -> bool:
    """Devuelve True si bbox solapa con algún bbox existente por encima del umbral."""
    x1, y1, w1, h1 = bbox
    for x2, y2, w2, h2 in existentes:
        ix1 = max(x1, x2)
        iy1 = max(y1, y2)
        ix2 = min(x1 + w1, x2 + w2)
        iy2 = min(y1 + h1, y2 + h2)
        if ix2 <= ix1 or iy2 <= iy1:
            continue
        inter = (ix2 - ix1) * (iy2 - iy1)
        union = w1 * h1 + w2 * h2 - inter
        if union > 0 and inter / union > threshold:
            return True
    return False


def _fallback_umbral(
    bordes: np.ndarray,
) -> List[Tuple[np.ndarray, Tuple[int, int, int, int]]]:
    """
    Fallback robusto: detecta regiones usando umbralización y cierre morfológico.
    Se activa cuando el pipeline principal no encuentra oficinas.
    """
    h_total, w_total = bordes.shape[:2]
    area_total = h_total * w_total

    # Intentar con kernels de distintos tamaños
    for kernel_size in [15, 25, 40]:
        kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (kernel_size, kernel_size))
        closed = cv2.morphologyEx(bordes, cv2.MORPH_CLOSE, kernel)
        contornos, _ = cv2.findContours(closed, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

        resultado = []
        for cnt in contornos:
            area = cv2.contourArea(cnt)
            if area_total * 0.008 < area < area_total * 0.88:
                x, y, w, h = cv2.boundingRect(cnt)
                if w > 35 and h > 35:
                    resultado.append((cnt, (x, y, w, h)))

        if resultado:
            resultado.sort(key=lambda t: t[1][1] * w_total + t[1][0])
            return resultado[:20]

    return []
