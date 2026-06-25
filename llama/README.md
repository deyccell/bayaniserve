# Healthbot — Local System

This is a working local version of the Barangay Health Center chatbot:
a FastAPI backend that talks to your fine-tuned model through Ollama,
plus a simple chat page to test it in the browser.

```
healthbot-system/
├── backend/
│   ├── app.py            ← FastAPI server
│   └── requirements.txt
├── frontend/
│   └── index.html        ← chat interface
└── README.md
```

## 0. Prerequisites

You should already have, from the Colab work:
- `medicine-chatbot.gguf` (the converted model)
- `Modelfile` (tells Ollama how to load it)

Put both of those in one folder, e.g. `healthbot-model/`, on the same
machine you'll run this system on (your laptop for testing, your server
for the real thing).

## 1. Install Ollama (if not already installed)

Download from https://ollama.com and install for your OS. Confirm it
worked:

```bash
ollama --version
```

## 2. Create the model in Ollama

From inside the `healthbot-model/` folder (where your `.gguf` and
`Modelfile` live):

```bash
ollama create healthbot -f Modelfile
```

This only needs to be done once. Test it directly first, before
touching the backend:

```bash
ollama run healthbot
```

Try a few questions in different languages. Type `/bye` to exit when
you're done. If this step doesn't give sensible answers, fix it here
first — the backend can't make a bad model good.

## 3. Set up the backend

```bash
cd backend
pip install -r requirements.txt
uvicorn app:app --reload --port 8000
```

If `MODEL_NAME` in `app.py` doesn't match the name you used in step 2
(`healthbot`), either rename it there or pass an environment variable
instead:

```bash
HEALTHBOT_MODEL=your_model_name uvicorn app:app --reload --port 8000
```

## 4. Open the chat

Visit **http://localhost:8000** in your browser. The status pill in the
top right should turn green ("Online") within a few seconds — that
means the backend can reach Ollama and the model exists. If it says
"Ollama offline," Ollama isn't running; if it says "Model not found,"
the name in step 2 and step 3 don't match.

## 5. Actually test it

Before connecting this to anything residents will use, spend real time
here. Ask things outside your original 30 training examples:
different medicines, different barangays, mixed-language phrasing,
vague questions, edge cases ("wala akong gamot pero may sakit ako"). 
Watch for:
- Confident-sounding but wrong stock numbers or medicine names
  (the model only really "knows" what was in your 30 examples)
- Breaking character or switching to generic AI assistant phrasing
- Getting confused by code-switching between languages mid-sentence

With only 30 training examples, gaps are expected. Write down what
breaks — that's your next fine-tuning dataset.

## 6. Moving to your actual server later

Nothing about this code is Colab-specific or laptop-specific. The same
three steps (`ollama create`, `pip install -r requirements.txt`,
`uvicorn app:app`) work identically on a server. The only things to
change for production are:
- Run uvicorn behind a real process manager (e.g. `systemd`, or
  `pm2`), not just a terminal window that closes when you log out
- Tighten `allow_origins=["*"]` in `app.py` to your actual domain
  once you have one
- Consider putting it behind HTTPS (e.g. via `nginx` or `caddy`) if
  it'll be reachable from outside your local network

## Troubleshooting

**"Can't reach Ollama" error in the chat** — Ollama isn't running.
Start it with `ollama serve` (or just `ollama run healthbot` once,
which also starts the background service).

**Port 8000 already in use** — run on a different port:
`uvicorn app:app --reload --port 8001` (then visit that port instead).

**Responses are slow** — this is normal for an 8B model running on
CPU or a modest GPU. If it's painfully slow, a smaller quantization
(e.g. `q4_0` instead of `q4_k_m`) trades a little quality for speed.
