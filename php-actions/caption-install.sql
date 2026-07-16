-- Live call captions (PoC) — ElevenLabs Scribe micro-batch transcription.
-- Jobs: one row per captioned call; worker tails the uuid_record wav.
CREATE TABLE IF NOT EXISTS v_caption_jobs (
    job_uuid      uuid PRIMARY KEY,
    call_uuid     uuid NOT NULL,
    domain_name   text,
    record_path   text NOT NULL,
    status        text NOT NULL DEFAULT 'active',   -- active | done | failed
    byte_offset   bigint NOT NULL DEFAULT 0,
    seq           int NOT NULL DEFAULT 0,
    created       timestamptz NOT NULL DEFAULT now(),
    updated       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_caption_jobs_status ON v_caption_jobs(status);
CREATE INDEX IF NOT EXISTS idx_caption_jobs_call ON v_caption_jobs(call_uuid);

CREATE TABLE IF NOT EXISTS v_call_captions (
    caption_uuid     uuid PRIMARY KEY,
    call_uuid        uuid NOT NULL,
    seq              int NOT NULL,
    speaker          smallint,           -- 0/1 for stereo per-leg, NULL for mono
    caption_text     text,
    caption_language text,
    created          timestamptz NOT NULL DEFAULT now()
);
ALTER TABLE v_call_captions ADD COLUMN IF NOT EXISTS speaker smallint;
CREATE INDEX IF NOT EXISTS idx_call_captions_call ON v_call_captions(call_uuid, seq);
