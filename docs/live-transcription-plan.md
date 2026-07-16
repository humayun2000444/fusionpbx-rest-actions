# Plan: Live Call Audio → Text (real-time transcription)

> Implementation plan for real-time speech-to-text on live FreeSWITCH/FusionPBX
> calls, with language detection and transcript storage. Hand-off doc for an
> implementing agent.

## Context / environment
- **Platform:** FusionPBX + FreeSWITCH. Two boxes: CCL `103.95.96.100`, BTCL `114.130.145.82`. Media/recordings live here.
- **Gateway:** Java TelcoREST on `103.95.96.98` (`/api/v1/...`), fronted at `iptsp.cosmocom.net:8001`. Dashboard is React (`btcl-hosted-pbx`).
- **Existing patterns to reuse:** a background worker daemon (`order_confirm_worker` style), ESL/dialplan hooks, per-partner config flags, ElevenLabs API key already provisioned.
- **Languages:** mixed **Bangla + English** → STT must auto-detect language.

## Core idea
FreeSWITCH forks the live call audio to a small **bridge service** over a WebSocket; the bridge streams it to a **streaming STT provider**, gets **interim + final** transcripts, then (a) pushes live captions to the dashboard and (b) persists final segments to the DB.

```
Call answered
   │  FreeSWITCH mod_audio_stream (forks PCM per channel)
   ▼
WebSocket  ──raw L16 PCM + metadata(call_uuid,domain,dir)──►  Bridge service
   │                                                              │
   │                                        opens 1 STT stream per call
   │                                                              ▼
   │                                            Streaming STT (interim+final)
   ▼                                                              │
Dashboard  ◄──WS live captions (interim)──  Bridge  ──finals──►  DB (transcript segments)
```

## Component 1 — FreeSWITCH media fork
Use **`mod_audio_stream`** (amigniter) — lightweight, forks channel audio to a WebSocket as raw PCM. (Alternatives: `mod_audio_fork` from jambonz, or `mod_unimrcp` — heavier.)

- Compile/install the module on `.100` and `114.x`, load in `modules.conf.xml`.
- Start streaming when the call is answered, via dialplan or ESL:
  ```
  uuid_audio_stream <call_uuid> start ws://127.0.0.1:9000/stream stereo 8000 '{"callUuid":"...","domain":"...","direction":"inbound","caller":"...","callee":"..."}'
  ```
  - **`stereo`** = caller on one channel, callee on the other → clean speaker separation (no diarization guesswork).
  - **8000 Hz** = telephony rate; bridge resamples to 16k if the STT needs it.
  - The JSON metadata is how the bridge correlates the stream to a call.
- Stop on hangup: ESL `CHANNEL_HANGUP` → `uuid_audio_stream <uuid> stop`.

### How the audio is tapped + supported format (detail)

FreeSWITCH's core mechanism is a **media bug** (`switch_core_media_bug_add`) that
taps the PCM frames flowing through a live channel *after codec decode*. You never
touch RTP or codecs yourself. Two ways to expose it:

1. **WebSocket media fork — `mod_audio_stream` (recommended)**
   ```
   uuid_audio_stream <call_uuid> start ws://bridge:9000/stream <mono|mixed|stereo> <rate>
   ```
   - First WS message = JSON text frame (metadata: callUuid, domain, etc.).
   - Then continuous **binary WS frames = raw PCM**. Stop with `... stop` on hangup.
   - (`mod_audio_fork` from jambonz is equivalent.)
2. **SIPREC** (`mod_sofia` session recording, RFC 7865/6) — standards-based; forks
   media as a second SIP leg with **RTP** to a recorder. More telco-correct, but you
   then receive encoded RTP (PCMU/PCMA) and must decode it. Only if compliance-grade
   vendor-neutral recording is required.

**Format you receive** — FreeSWITCH internal audio is **L16 (signed 16-bit PCM,
little-endian)**, already decoded (not a codec):

| Property | Value |
|---|---|
| Encoding | L16 (signed 16-bit PCM, LE) |
| Channels | `mono`/`mixed` (legs summed) or **`stereo`** (caller = L, callee = R) |
| Sample rate | telephony → **8000 Hz**; HD (G.722/Opus) → **16000 Hz** (`mod_audio_stream` can resample) |
| Framing | 20 ms ptime → 160 samples @8k = **320 bytes/frame** mono (640 stereo) |

**Mapping to STT input:**
- **Deepgram** — accepts raw **`linear16`** at 8k/16k **directly** (or `mulaw`). Best match, zero transcoding.
- **Whisper / faster-whisper** — wants **16 kHz**; resample 8k→16k in the bridge.
- **ElevenLabs Scribe** — file-based; buffer PCM and wrap a **WAV** header (post-call, not live).

**Gotcha — media path:** the media bug only sees audio if FreeSWITCH is in the media
path. If a call uses **bypass/proxy media** (RTP flows endpoint-to-endpoint), there is
nothing to tap. For any transcribed call, ensure **media proxy is on** (no
`bypass_media`) or the fork produces silence.

## Component 2 — Bridge service (new, Node or Python)
- **WS server** receiving PCM frames keyed by `callUuid` (from the start metadata).
- For each call, open a **streaming STT session**; relay audio frames; receive `interim` (partial) and `final` results with timestamps + language.
- **Fan-out** each result:
  - **Live:** push to the dashboard over a WebSocket (e.g. Socket.IO), topic = `callUuid`.
  - **Persist:** write **final** segments to DB (skip interims).
- Handle: per-call session lifecycle, reconnect/backpressure, and cleanup on stop/hangup.
- Deploy as a systemd service on `.98` (near the gateway) or `.100`.

## Component 3 — STT provider (pluggable)
Make the provider an interface so it can be swapped:
- **Deepgram** — recommended for realtime telephony: native 8 kHz, WebSocket streaming, interim results, language detection. Most proven for live.
- **Self-hosted Whisper streaming** (`faster-whisper` + VAD chunking) — **free**, auto language detect, handles Bangla; slightly higher latency (chunked, not true streaming).
- **ElevenLabs** — Scribe is primarily **batch/file** today; realtime is newer/limited, so don't hard-depend on it for live. Keep it as a batch fallback for post-call.

## Component 4 — Data model
```sql
call_transcript_segments (
  segment_uuid, call_uuid FK->v_xml_cdr.xml_cdr_uuid, domain_uuid,
  channel,          -- 0=caller,1=callee (from stereo fork)
  start_ms, end_ms,
  text, language, is_final,
  created_date
)
-- On hangup, roll up into call_transcripts (full text + detected language).
```

## Component 5 — Dashboard UI
- On the **Active Calls** page, a live-caption panel that opens a WS subscription on `callUuid` and appends interim/final lines (color the two channels).
- On the **Call History** detail modal, show the stored full transcript for ended calls.

## Data flow summary
1. Call answered → dialplan starts `mod_audio_stream` with call metadata.
2. Bridge receives PCM per channel → streams to STT.
3. STT interim → live captions to dashboard; STT final → DB segments.
4. Hangup → stop stream, roll segments into `call_transcripts`.

## Key decisions / gotchas to flag
- **Sample rate/codec:** telephony is 8 kHz; configure STT for 8 kHz mono per channel, or resample to 16 kHz.
- **Stereo fork** is what gives clean speaker separation — prefer it over diarization.
- **One STT stream per concurrent call** → watch provider connection limits and cost; gate behind a **per-partner opt-in flag**.
- **Consent/legal:** live transcription of calls has consent rules — opt-in per domain.
- **Security:** bridge WS is internal-only + authenticated; don't expose publicly.
- **Cleanup:** always stop the stream on `CHANNEL_DESTROY` to avoid orphaned STT sessions.
- **Latency:** use interims for captions, finals for storage.

## Suggested build order
1. `mod_audio_stream` on one box + a stub bridge that logs PCM (prove the fork works).
2. Wire one STT provider (Deepgram or Whisper) → console-print live transcript.
3. Add DB persistence of finals.
4. Add dashboard WS + live-caption UI.
5. Per-partner opt-in flag + hangup rollup + cleanup.

## Related / future
- **Post-call transcription** (batch) is a simpler sibling: on hangup, send the existing recording (`record_name`/`record_path` in the CDR) to Scribe/Whisper and store the transcript. Good first phase if live proves too heavy.
- **Emotion/sentiment:** ElevenLabs does NOT detect emotion. Add as a layer on the transcript — text sentiment via an LLM (cheap), or acoustic emotion (Hume AI paid / self-hosted Wav2Vec2 SER free). Store `sentiment`/`emotion` alongside the transcript.

---

# Appendix A — Two transport paths (pick one)

The bridge can receive the live audio one of two ways. **Default = WebSocket fork**
(simplest). RTP/SIPREC only if standards-based compliance recording is required.

| | WebSocket fork (`mod_audio_stream`) | RTP / SIPREC |
|---|---|---|
| Signaling | none (just `uuid_audio_stream ... start`) | SIP + SDP |
| Media transport | **WebSocket** (TCP), binary frames | **RTP over UDP** |
| Audio you receive | **already-decoded L16 PCM** | encoded codec (G.711 µ-law/A-law), with RTP headers |
| You must build | a WS server | a SIP-answering **RTP media server** (UDP socket + jitter buffer + codec decode) |
| Codec work | none | strip RTP header, reorder by seq, de-jitter, decode |
| Best when | live captions, least infra | vendor-neutral compliance recording (RFC 7865/6) |

## Appendix B — The RTP path in detail

**Key fact:** no STT provider ingests RTP directly. RTP is only the
FreeSWITCH → your-server hop. You must run an **RTP terminator** that unpacks RTP
and forwards audio to the STT over the STT's own streaming API.

```
FreeSWITCH ──SIP/SDP (signaling)──►  RTP receiver  ──WS/gRPC──►  STT  ──► transcript
           ──RTP/UDP  (audio)─────►  (mini media server)
```

### 1. Make FreeSWITCH emit RTP
- **SIPREC** — FreeSWITCH INVITEs your receiver, then streams RTP to the negotiated port.
- **`rtpengine`** — media proxy that mirrors/forks a copy of the call RTP to an external IP:port (no SIP handling needed on your side, just a UDP listener).

### 2. SIP/SDP that sets up the RTP stream (SIPREC INVITE, abridged)
```
INVITE sip:srs@bridge.internal SIP/2.0
Content-Type: multipart/mixed; boundary=frontier

--frontier
Content-Type: application/sdp

m=audio 40000 RTP/AVP 0 8 101      ← open RTP on UDP 40000, codecs 0/8/101
a=rtpmap:0 PCMU/8000               ← PT 0 = G.711 µ-law @ 8 kHz
a=rtpmap:8 PCMA/8000               ← PT 8 = G.711 A-law
a=sendonly
a=label:1                          ← this leg = caller

--frontier
Content-Type: application/rs-metadata+xml
<recording xmlns="urn:ietf:params:xml:ns:recording:1"> ... participants ... </recording>
--frontier--
```

### 3. One RTP packet (RFC 3550) — what arrives on UDP 40000
```
Byte:  0    1    2    3    4    5    6    7    8    9   10   11  | 12...
       80   00   1C   34   00 00 00 A0   12 34 56 78            | payload (160 B G.711 = 20 ms)
       │    │    └seq─┘    └timestamp─┘  └───SSRC───┘
       │    └ 0x00 → Marker=0, PayloadType=0 (PCMU)
       └ 0x80 → Version=2, Padding=0, Extension=0, CSRC=0
```

### 4. Receiver loop (pseudocode)
```python
sock = udp_bind("0.0.0.0", 40000)          # port from the SDP
dg = deepgram_ws(encoding="mulaw", sample_rate=8000,
                 interim_results=True, language="multi")   # Bangla/English auto
dg.on_message = lambda m: push_caption_and_store(call_uuid, m)

jitter = JitterBuffer()
while call_active:
    pkt, _ = sock.recvfrom(2048)           # one RTP packet
    seq     = (pkt[2] << 8) | pkt[3]       # RTP sequence number
    payload = pkt[12:]                     # strip 12-byte RTP header (no CSRC)
    for frame in jitter.push(seq, payload):
        dg.send(frame)                     # raw G.711 µ-law → Deepgram (no decode)
# on hangup: dg.close(); sock.close()
```

### 5. STT hand-off note
- **Deepgram** accepts **`mulaw`/`alaw` directly** → forward the raw G.711 payload, no decode.
- **Whisper / faster-whisper** → decode G.711 → L16 and resample 8k → 16k first, i.e.
  `dg.send(resample_16k(ulaw_decode(frame)))`.

**Bottom line:** RTP → STT works, but only through an RTP-terminating bridge you
build and operate (UDP + jitter buffer + codec decode + a receiver process per
concurrent call). `mod_audio_stream` avoids all of that by delivering decoded L16
PCM over WebSocket. Same STT hand-off at the end either way.
