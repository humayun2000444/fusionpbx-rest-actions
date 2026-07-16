# Whisper STT — Shared Transcription Microservice (Deployment Doc)

> Hand-off doc for an implementing agent. Goal: deploy **self-hosted Whisper**
> (`faster-whisper`) as a **standalone, network-reachable STT service** that any
> system can call — live streaming (near-real-time) and post-call batch.
> Upstream audio flow (how a call's audio reaches this service) is described in
> `live-transcription-plan.md`; this doc is only about the STT service itself.

---

## 1. Why self-hosted Whisper
- **Bangla + English** accuracy: Whisper `large-v3` is strong on Bangla (usually better than cloud STTs). Auto language detection.
- **Free** per call (no per-minute billing) after hardware.
- **On-prem / privacy**: audio never leaves your network — important for the government (BTCL) deployment.
- One shared service, reusable by every client (both FusionPBX boxes, bridges, workers, dashboard).

**Trade-off to accept:** Whisper is chunk-based, not a true word-by-word streaming
model. Expect **~1–2 s latency** for "near-live" captions. Fine for transcript
capture/records/sentiment. If sub-second live captions become a hard requirement,
cloud Deepgram is the alternative (separate decision).

---

## 2. Architecture

```
                          ┌─────────────────────────────┐
                          │   Whisper STT server (GPU)  │
                          │   faster-whisper large-v3   │
                          │   API: WSS (stream) + HTTPS  │
                          │        (batch)  + API key    │
                          └──────────────┬──────────────┘
                                         │ (called by any client)
        ┌────────────────────┬───────────┴───────────┬────────────────────┐
        ▼                    ▼                       ▼                    ▼
 CCL bridge (.100)    BTCL bridge (114.x)     Post-call worker      Future apps
 live PCM → WSS       live PCM → WSS          (recordings → HTTPS)  (any client)
```

The service is decoupled from FreeSWITCH. Clients send **16 kHz mono PCM** (or a
WAV file for batch); the service returns JSON transcripts with language + timestamps.

---

## 3. Data residency decision (READ FIRST)
- **CCL** (multi-tenant, `103.95.96.x`): one shared Whisper box is fine.
- **BTCL** (`114.130.145.82`, **government**): call audio may be required to **stay
  inside BTCL's network**. If so, run a **second instance** of the *same container
  image* inside BTCL, rather than shipping audio to a CCL box.
- Decision: **one shared box** vs **one-per-site**. Same code either way; only the
  network boundary differs. Default recommendation: **one instance per site** for
  clean data residency.

---

## 4. Hardware requirements
- **GPU required** for real-time. CPU-only `large-v3` is too slow for live.
- Rough guidance (benchmark for your real load):
  - `large-v3` (best Bangla), CTranslate2 `int8_float16`: ~5–10 GB VRAM; a **T4 (16 GB)** or **A10/RTX 4000-class** handles **a few concurrent live streams**.
  - `medium` / `small`: faster, more concurrent streams, lower Bangla accuracy.
- **Size for combined peak concurrent calls** across ALL clients hitting the box.
- Add GPUs or run multiple containers behind a load balancer to scale.
- Audio bandwidth is tiny (~128 kbps/stream) — GPU is the bottleneck, not network.
- Storage: model files (~1.5–3 GB for large-v3) cached on disk.

---

## 5. Software stack
- **Engine:** `faster-whisper` (CTranslate2 backend) — fastest Whisper runtime.
- **Model:** `large-v3` (primary), `medium` (fallback for load).
- **VAD:** Silero VAD (bundled with faster-whisper) to chunk speech for near-live.
- **Streaming server option (pick one):**
  - **Ready-made:** `WhisperLive` (WebSocket streaming server) — least code.
  - **Custom:** small **FastAPI** service exposing WS + HTTP (full control; recommended so the API contract matches our bridge).
- **Container:** Docker with NVIDIA CUDA runtime (`nvidia-container-toolkit`).

---

## 6. API contract (what clients call)

### 6.1 Streaming (near-live) — WebSocket
```
wss://whisper.internal:9000/stream?api_key=<KEY>&lang=auto
```
- **Client → server:** first a JSON text frame with metadata, then binary **16 kHz
  mono PCM (L16, little-endian)** frames as audio arrives.
  ```json
  {"callUuid":"...","domain":"...","channel":0,"sampleRate":16000,"language":"auto"}
  ```
- **Server → client:** JSON messages, interim then final:
  ```json
  {"type":"interim","text":"আপনার অর্ডার","start":0.0,"end":1.2,"language":"bn"}
  {"type":"final","text":"আপনার অর্ডার কনফার্ম হয়েছে","start":0.0,"end":2.6,"language":"bn"}
  ```
- Server buffers incoming PCM, runs VAD, transcribes each speech segment, emits results. Close on hangup.

### 6.2 Batch (post-call) — HTTP
```
POST https://whisper.internal:9000/transcribe
Header: x-api-key: <KEY>
Body (multipart/form-data): file=<wav>, language=auto, model=large-v3
```
Response:
```json
{
  "language":"bn",
  "language_probability":0.98,
  "text":"...",
  "segments":[{"start":0.0,"end":2.6,"text":"...","speaker":0}]
}
```

### 6.3 Health
```
GET /healthz   →  {"status":"ok","model":"large-v3","gpu":"T4","load":0.2}
```

---

## 7. Audio format expected
- Whisper wants **16 kHz mono, float32/L16 PCM**.
- Telephony is **8 kHz** → clients (the bridge) must **resample 8k → 16k** before sending.
- For batch, accept WAV/MP3/etc. and let the service normalize.

---

## 8. Deployment (Docker)

`docker-compose.yml` (illustrative):
```yaml
services:
  whisper-stt:
    image: whisper-stt:latest          # custom image: faster-whisper + FastAPI
    restart: always
    ports:
      - "9000:9000"
    environment:
      - MODEL=large-v3
      - COMPUTE_TYPE=int8_float16
      - DEVICE=cuda
      - API_KEY=${WHISPER_API_KEY}
      - MAX_CONCURRENCY=4
    volumes:
      - ./models:/models                # cache downloaded model weights
    deploy:
      resources:
        reservations:
          devices:
            - driver: nvidia
              count: 1
              capabilities: [gpu]
```
- Requires host: NVIDIA driver + `nvidia-container-toolkit`.
- Run as a systemd-managed `docker compose up -d` unit (survives reboot).
- Pre-download the model into `./models` on first run.

---

## 9. Client integration (how each system uses it)
- **Live bridge (per call):** open a WS to `/stream`, send resampled 16k PCM,
  receive interim/final → push captions to dashboard + store finals in DB.
- **Post-call worker:** on hangup, POST the recording (`record_name`/`record_path`
  from the CDR) to `/transcribe`, store the transcript.
- All clients share the **same base URL + API key** (or per-site URL if one-per-site).

---

## 10. Security
- **Private network** only; do not expose publicly.
- **API key** on every request (header/query). Rotate periodically.
- **TLS** if traffic crosses any untrusted link (esp. between sites).
- Consider mTLS or a VPN/WireGuard tunnel for cross-site calls.
- Log minimal PII; transcripts are sensitive — protect at rest.

---

## 11. Scaling & reliability
- **Concurrency cap** per container (e.g. `MAX_CONCURRENCY`); queue or 503 beyond it.
- Scale out: multiple containers/GPUs behind a load balancer (sticky per stream).
- One long-lived STT session per live call; ensure cleanup on WS close / hangup.
- Health checks + auto-restart (systemd/compose `restart: always`).
- Monitor: GPU utilization, VRAM, queue depth, per-request latency.

---

## 12. Validation checklist (for the implementing agent)
1. Stand up the container on the GPU box; `GET /healthz` returns ok.
2. Batch test: POST a known Bangla WAV → verify text + `language:"bn"`.
3. Batch test: mixed Bangla/English WAV → verify code-switching handled.
4. Streaming test: pipe a recorded call's 16k PCM to `/stream` → verify interim+final.
5. Latency test: measure time from audio-in to final result (target ~1–2 s).
6. Concurrency test: N simultaneous streams → confirm no GPU OOM, acceptable latency.
7. Failure test: kill/restart container mid-stream → clients reconnect cleanly.

---

## 13. Open decisions (confirm before building)
- [ ] One shared instance vs one-per-site (BTCL data residency) — **default: per-site**.
- [ ] Model: `large-v3` everywhere, or `medium` where GPU is constrained.
- [ ] GPU choice + how many concurrent streams to size for (need expected peak).
- [ ] Streaming server: ready-made `WhisperLive` vs custom FastAPI (recommend custom for a matching API contract).
- [ ] Where the service runs (existing box vs new GPU box) at each site.

---

## 14. Related docs
- `live-transcription-plan.md` — how call audio is tapped from FreeSWITCH (RTP/UDP
  via SIPREC/rtpengine) and delivered to this service; also the WebSocket-fork
  alternative, and the emotion/sentiment layer.
