#!/usr/bin/env python3
"""
whisper_server.py — CPU faster-whisper STT microservice (Bangla-first).

Runs on .98 (24 cores). The .100 caption worker POSTs raw WAV bytes; this
returns {"text","language"}. Model is loaded once and kept warm.

Env:
  WHISPER_MODEL   model dir or name (default: medium)  -> set to the CT2
                  Bengali fine-tune dir for best accuracy
  WHISPER_DEVICE  cpu (default)
  WHISPER_COMPUTE int8 (default) — fast CPU path
  WHISPER_THREADS cpu threads per request (default 6; caps load on the gateway)
  WHISPER_LANG    forced language (default: bn; '' = auto-detect)
  WHISPER_PORT    listen port (default 5090)
  WHISPER_KEY     shared key required as ?key= (default: whisper_ccl_key)

Endpoints:
  GET  /healthz           -> {"ok":true,"model":...}
  POST /transcribe?key=.. -> body = WAV bytes -> {"text":..,"language":..,"rtf":..}
"""
import json
import os
import tempfile
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from faster_whisper import WhisperModel

MODEL_NAME = os.environ.get("WHISPER_MODEL", "medium")
DEVICE     = os.environ.get("WHISPER_DEVICE", "cpu")
COMPUTE    = os.environ.get("WHISPER_COMPUTE", "int8")
THREADS    = int(os.environ.get("WHISPER_THREADS", "6"))
LANG       = os.environ.get("WHISPER_LANG", "bn")
BEAM       = int(os.environ.get("WHISPER_BEAM", "5"))
PORT       = int(os.environ.get("WHISPER_PORT", "5090"))
KEY        = os.environ.get("WHISPER_KEY", "whisper_ccl_key")

print(f"[whisper] loading model={MODEL_NAME} device={DEVICE} compute={COMPUTE} threads={THREADS}", flush=True)
model = WhisperModel(MODEL_NAME, device=DEVICE, compute_type=COMPUTE, cpu_threads=THREADS)
print("[whisper] model ready", flush=True)


def transcribe(wav_path):
    t0 = time.time()
    segments, info = model.transcribe(
        wav_path,
        language=(LANG or None),
        beam_size=BEAM,
        temperature=0.0,
        condition_on_previous_text=False,   # reduce hallucination loops
        vad_filter=True,
        vad_parameters=dict(min_silence_duration_ms=400),
    )
    text = " ".join(s.text.strip() for s in segments).strip()
    took = time.time() - t0
    return {
        "text": text,
        "language": info.language if info else (LANG or None),
        "language_probability": round(getattr(info, "language_probability", 0.0) or 0.0, 3),
        "rtf": round(took / max(0.1, getattr(info, "duration", 0.0) or 0.1), 2),
    }


class Handler(BaseHTTPRequestHandler):
    def _send(self, code, obj):
        body = json.dumps(obj, ensure_ascii=False).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass  # quiet

    def do_GET(self):
        if self.path.split("?")[0] == "/healthz":
            self._send(200, {"ok": True, "model": MODEL_NAME, "lang": LANG})
        else:
            self._send(404, {"ok": False, "error": "not found"})

    def do_POST(self):
        from urllib.parse import urlparse, parse_qs
        q = parse_qs(urlparse(self.path).query)
        if q.get("key", [""])[0] != KEY:
            self._send(401, {"ok": False, "error": "unauthorized"})
            return
        if urlparse(self.path).path != "/transcribe":
            self._send(404, {"ok": False, "error": "not found"})
            return
        try:
            n = int(self.headers.get("Content-Length", 0))
            data = self.rfile.read(n)
            if not data:
                self._send(400, {"ok": False, "error": "empty body"})
                return
            with tempfile.NamedTemporaryFile(suffix=".wav", delete=True) as tmp:
                tmp.write(data)
                tmp.flush()
                result = transcribe(tmp.name)
            result["ok"] = True
            self._send(200, result)
        except Exception as e:
            self._send(500, {"ok": False, "error": str(e)})


if __name__ == "__main__":
    print(f"[whisper] listening on 0.0.0.0:{PORT}", flush=True)
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
