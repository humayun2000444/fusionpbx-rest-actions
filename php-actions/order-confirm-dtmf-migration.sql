ALTER TABLE v_order_confirm_config ADD COLUMN IF NOT EXISTS dtmf_options JSONB DEFAULT
  '[{"digit":"1","label":"Confirm","action":"callback","value":"1"},
    {"digit":"2","label":"Cancel","action":"callback","value":"2"},
    {"digit":"0","label":"Support","action":"transfer","value":""}]'::jsonb;
ALTER TABLE v_order_confirm_config ADD COLUMN IF NOT EXISTS ack_text_en TEXT DEFAULT 'Thank you, your response has been recorded.';
ALTER TABLE v_order_confirm_config ADD COLUMN IF NOT EXISTS ack_text_bn TEXT DEFAULT 'ধন্যবাদ, আপনার উত্তর গ্রহণ করা হয়েছে।';
