-- Add voice configuration fields to Smart IVR config table
ALTER TABLE v_smart_ivr_config 
ADD COLUMN IF NOT EXISTS google_tts_voice_name VARCHAR(100),
ADD COLUMN IF NOT EXISTS google_tts_voice_gender VARCHAR(20) DEFAULT 'NEUTRAL';

-- Add comment
COMMENT ON COLUMN v_smart_ivr_config.google_tts_voice_name IS 'Specific Google TTS voice name (e.g., bn-IN-Wavenet-A)';
COMMENT ON COLUMN v_smart_ivr_config.google_tts_voice_gender IS 'Voice gender: MALE, FEMALE, or NEUTRAL';
