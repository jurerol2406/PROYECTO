"""
formato.py
Convierte la lista de contornos/bboxes detectados al formato JSON requerido:

{
  "house_id": "uuid",
  "name": "Empresa",
  "rooms": [
    {
      "id": "room-1",
      "name": "Oficina",
      "width": 45,
      "height": 45,
      "x": 2,
      "y": 2,
      "doors": [
        {"id": "door-1", "position": "right", "offset": 50}
      ]
    }
  ]
}

Las coordenadas se normalizan al viewport SVG 0-100.
"""

import numpy as np
from typing import List, Tuple

from procesamiento.puertas import detectar_puertas

NOMBRES_OFICINAS = [
    "Recepción",
    "Oficina A",
    "Sala de Reuniones",
    "Dirección",
    "RRHH",
    "Contabilidad",
    "TI / Sistemas",
    "Sala de Descanso",
    "Almacén",
    "Oficina B",
    "Sala de Conferencias",
    "Marketing",
    "Ventas",
    "Oficina C",
    "Seguridad",
    "Mantenimiento",
    "Archivo",
    "Oficina D",
    "Sala de Formación",
    "Pasillo",
]

MARGIN = 1.5
SCALE  = 100.0 - 2 * MARGIN


def generar_json_estructurado(
    habitaciones_data: List[Tuple],
    img_shape: Tuple[int, ...],
    bordes_img: np.ndarray,
    nombre_empresa: str = "Empresa",
) -> dict:
    h_img, w_img = img_shape[:2]
    rooms = []

    for idx, (cnt, (x, y, w, h)) in enumerate(habitaciones_data):
        nx = round((x / w_img) * SCALE + MARGIN, 2)
        ny = round((y / h_img) * SCALE + MARGIN, 2)
        nw = round((w / w_img) * SCALE, 2)
        nh = round((h / h_img) * SCALE, 2)

        nw = round(max(5.0, min(nw, 100.0 - nx - MARGIN)), 2)
        nh = round(max(5.0, min(nh, 100.0 - ny - MARGIN)), 2)

        puertas = detectar_puertas((x, y, w, h), idx, bordes_img)
        nombre  = NOMBRES_OFICINAS[idx % len(NOMBRES_OFICINAS)]

        rooms.append({
            "id":     f"room-{idx + 1}",
            "name":   nombre,
            "width":  nw,
            "height": nh,
            "x":      nx,
            "y":      ny,
            "doors":  puertas,
        })

    if not rooms:
        rooms = _plano_empresa_ejemplo()

    return {"name": nombre_empresa, "rooms": rooms}


def _plano_empresa_ejemplo() -> List[dict]:
    return [
        {
            "id": "room-1", "name": "Recepción",
            "width": 28, "height": 22, "x": 5, "y": 5,
            "doors": [
                {"id": "door-1", "position": "right",  "offset": 50},
                {"id": "door-2", "position": "bottom", "offset": 30},
            ],
        },
        {
            "id": "room-2", "name": "Oficina A",
            "width": 30, "height": 22, "x": 35, "y": 5,
            "doors": [{"id": "door-3", "position": "left", "offset": 50}],
        },
        {
            "id": "room-3", "name": "Sala de Reuniones",
            "width": 20, "height": 22, "x": 67, "y": 5,
            "doors": [{"id": "door-4", "position": "left", "offset": 50}],
        },
        {
            "id": "room-4", "name": "Dirección",
            "width": 28, "height": 30, "x": 5, "y": 32,
            "doors": [{"id": "door-5", "position": "top", "offset": 50}],
        },
        {
            "id": "room-5", "name": "RRHH",
            "width": 25, "height": 30, "x": 35, "y": 32,
            "doors": [
                {"id": "door-6", "position": "left",  "offset": 50},
                {"id": "door-7", "position": "right", "offset": 50},
            ],
        },
        {
            "id": "room-6", "name": "TI / Sistemas",
            "width": 25, "height": 30, "x": 62, "y": 32,
            "doors": [{"id": "door-8", "position": "left", "offset": 50}],
        },
        {
            "id": "room-7", "name": "Sala de Descanso",
            "width": 40, "height": 18, "x": 5, "y": 68,
            "doors": [{"id": "door-9", "position": "top", "offset": 50}],
        },
        {
            "id": "room-8", "name": "Almacén",
            "width": 40, "height": 18, "x": 50, "y": 68,
            "doors": [{"id": "door-10", "position": "top", "offset": 50}],
        },
    ]
