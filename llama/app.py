"""
Healthbot backend
------------------
A small FastAPI server that sits between the chat frontend and your
local Ollama model. It does three things:

1. /api/health  - checks whether Ollama is running and the model exists
2. /api/chat    - forwards a conversation to Ollama and returns the reply
3. serves the frontend (the index.html in ../frontend) at "/"

Run it with:
    uvicorn app:app --reload --port 8000

Then open http://localhost:8000 in your browser.
"""

import os
from pathlib import Path
from typing import List

import httpx
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel

# ---------------------------------------------------------------------------
# Configuration — change these if your setup differs
# ---------------------------------------------------------------------------

OLLAMA_BASE_URL = os.environ.get("OLLAMA_BASE_URL", "http://localhost:11434")
# This MUST match the name you used in `ollama create <name> -f Modelfile`
MODEL_NAME = os.environ.get("HEALTHBOT_MODEL", "healthbot")

FRONTEND_DIR = Path(__file__).resolve().parent.parent / "frontend"

# ---------------------------------------------------------------------------

app = FastAPI(title="Healthbot API")

# Allows the frontend to call this API even if it's ever served from a
# different port/origin during development.
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)


class ChatMessage(BaseModel):
    role: str  # "user" or "assistant"
    content: str


class ChatRequest(BaseModel):
    messages: List[ChatMessage]


class ChatResponse(BaseModel):
    reply: str


@app.get("/api/health")
async def health_check():
    """
    Lets the frontend (and you) confirm Ollama is reachable and that the
    healthbot model actually exists before trying to chat.
    """
    try:
        async with httpx.AsyncClient(timeout=5.0) as client:
            resp = await client.get(f"{OLLAMA_BASE_URL}/api/tags")
            resp.raise_for_status()
            models = [m["name"] for m in resp.json().get("models", [])]
        return {
            "ollama_reachable": True,
            "model_available": any(MODEL_NAME in m for m in models),
            "models_found": models,
        }
    except Exception as e:
        return {"ollama_reachable": False, "error": str(e)}


@app.post("/api/chat", response_model=ChatResponse)
async def chat(req: ChatRequest):
    """
    Forwards the conversation to Ollama. The model's SYSTEM prompt is
    already baked into the Modelfile, so we don't need to send it here —
    just the user/assistant turns.
    """
    payload = {
        "model": MODEL_NAME,
        "messages": [m.dict() for m in req.messages],
        "stream": False,
    }

    try:
        async with httpx.AsyncClient(timeout=120.0) as client:
            resp = await client.post(f"{OLLAMA_BASE_URL}/api/chat", json=payload)
            resp.raise_for_status()
            data = resp.json()
    except httpx.ConnectError:
        raise HTTPException(
            status_code=503,
            detail=(
                "Can't reach Ollama. Make sure it's running — try `ollama serve` "
                "in a terminal, or just `ollama run healthbot` once."
            ),
        )
    except httpx.HTTPStatusError as e:
        raise HTTPException(status_code=502, detail=f"Ollama error: {e.response.text}")

    reply = data.get("message", {}).get("content", "").strip()
    if not reply:
        raise HTTPException(status_code=502, detail="Ollama returned an empty response.")

    return ChatResponse(reply=reply)


# Serve the chat frontend at "/". This must be added LAST so the /api
# routes above take priority over the static file catch-all.
app.mount("/", StaticFiles(directory=str(FRONTEND_DIR), html=True), name="frontend")
