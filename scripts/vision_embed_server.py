import base64
import io
import os
from typing import List, Optional

import torch
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from PIL import Image
import open_clip

app = FastAPI()

MODEL_NAME = os.environ.get("VISION_MODEL_NAME", "ViT-B-32")
PRETRAINED = os.environ.get("VISION_MODEL_PRETRAINED", "laion2b_s34b_b79k")
DEVICE = "cuda" if torch.cuda.is_available() else "cpu"

model, _, preprocess = open_clip.create_model_and_transforms(MODEL_NAME, pretrained=PRETRAINED)
model.to(DEVICE)
model.eval()


class EmbedRequest(BaseModel):
    image_url: Optional[str] = None
    image_data: Optional[str] = None


def image_from_data_url(data_url: str) -> Image.Image:
    if "," not in data_url:
        raise ValueError("Invalid data URL.")
    _, encoded = data_url.split(",", 1)
    raw = base64.b64decode(encoded)
    return Image.open(io.BytesIO(raw)).convert("RGB")


def image_from_bytes(raw: bytes) -> Image.Image:
    return Image.open(io.BytesIO(raw)).convert("RGB")


def encode_image(img: Image.Image) -> List[float]:
    with torch.no_grad():
        image = preprocess(img).unsqueeze(0).to(DEVICE)
        embedding = model.encode_image(image)
        embedding = embedding / embedding.norm(dim=-1, keepdim=True)
        return embedding[0].cpu().tolist()


@app.post("/embed-image")
def embed_image(payload: EmbedRequest):
    if payload.image_data:
        try:
            img = image_from_data_url(payload.image_data)
        except Exception as exc:
            raise HTTPException(status_code=400, detail=str(exc))
        return {"embedding": encode_image(img)}

    if payload.image_url:
        import requests

        res = requests.get(payload.image_url, timeout=10)
        if res.status_code >= 400:
            raise HTTPException(status_code=400, detail="Failed to fetch image URL.")
        try:
            img = image_from_bytes(res.content)
        except Exception as exc:
            raise HTTPException(status_code=400, detail=str(exc))
        return {"embedding": encode_image(img)}

    raise HTTPException(status_code=400, detail="image_url or image_data is required.")

