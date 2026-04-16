from fastapi import FastAPI, File, UploadFile
from fastapi.middleware.cors import CORSMiddleware
import cv2
import numpy as np

app = FastAPI()

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

@app.post("/process")
async def process_image(file: UploadFile = File(...)):
    contents = await file.read()

    np_arr = np.frombuffer(contents, np.uint8)
    img = cv2.imdecode(np_arr, cv2.IMREAD_GRAYSCALE)

    # Preprocesado
    blur = cv2.GaussianBlur(img, (5, 5), 0)

    kernel = np.ones((5, 5), np.uint8)
    morph = cv2.morphologyEx(blur, cv2.MORPH_CLOSE, kernel)

    # Umbral
    _, thresh = cv2.threshold(morph, 150, 255, cv2.THRESH_BINARY_INV)

    # Contornos
    contours, _ = cv2.findContours(thresh, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

    rooms = 0
    doors = []

    for cnt in contours:
        area = cv2.contourArea(cnt)

        if area > 5000:
            rooms += 1

        elif 500 < area < 3000:
            x, y, w, h = cv2.boundingRect(cnt)
            doors.append({
                "x": int(x),
                "y": int(y),
                "w": int(w),
                "h": int(h)
            })

    return {
        "rooms": rooms,
        "doors": doors
    }
