ALTER TABLE v_order_confirm_config ADD COLUMN IF NOT EXISTS tts_provider VARCHAR(20) DEFAULT 'free';
ALTER TABLE v_order_confirm_config ADD COLUMN IF NOT EXISTS speech_rate VARCHAR(10) DEFAULT 'slow';
ALTER TABLE v_order_confirm_config ADD COLUMN IF NOT EXISTS answer_delay_ms INTEGER DEFAULT 2000;
ALTER TABLE v_order_confirm_config ADD COLUMN IF NOT EXISTS tts_google_key TEXT DEFAULT '';
ALTER TABLE v_order_confirm_config ADD COLUMN IF NOT EXISTS tts_azure_key TEXT DEFAULT '';
ALTER TABLE v_order_confirm_config ADD COLUMN IF NOT EXISTS tts_azure_region VARCHAR(40) DEFAULT 'southeastasia';
ALTER TABLE v_order_confirm_config ADD COLUMN IF NOT EXISTS tts_elevenlabs_key TEXT DEFAULT '';
ALTER TABLE v_order_confirm_config ADD COLUMN IF NOT EXISTS tts_elevenlabs_voice_id VARCHAR(80) DEFAULT '';
