-- Smart IVR Database Schema
-- This is an ADD-ON module, does not affect existing IVR tables

-- 1. Smart IVR Configuration Table
CREATE TABLE IF NOT EXISTS v_smart_ivr_config (
    smart_ivr_config_uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid UUID NOT NULL,
    enabled BOOLEAN DEFAULT FALSE,
    hotline_number VARCHAR(50),
    backend_api_url VARCHAR(500),
    backend_api_key VARCHAR(200),
    google_tts_enabled BOOLEAN DEFAULT TRUE,
    google_tts_language VARCHAR(10) DEFAULT 'en-US',
    welcome_message TEXT,
    goodbye_message TEXT,
    insert_date TIMESTAMP DEFAULT NOW(),
    insert_user UUID,
    update_date TIMESTAMP,
    update_user UUID,
    FOREIGN KEY (domain_uuid) REFERENCES v_domains(domain_uuid) ON DELETE CASCADE
);

-- 2. Smart IVR Campaigns (for outbound calls)
CREATE TABLE IF NOT EXISTS v_smart_ivr_campaigns (
    campaign_uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid UUID NOT NULL,
    campaign_name VARCHAR(100) NOT NULL,
    campaign_type VARCHAR(50) NOT NULL, -- 'payment_reminder', 'class_cancel', 'exam_notice'
    enabled BOOLEAN DEFAULT TRUE,
    message_template TEXT,
    tts_language VARCHAR(10) DEFAULT 'en-US',
    require_feedback BOOLEAN DEFAULT TRUE,
    feedback_prompt TEXT,
    scheduled_time TIMESTAMP,
    status VARCHAR(20) DEFAULT 'pending', -- 'pending', 'running', 'completed', 'paused'
    total_calls INTEGER DEFAULT 0,
    completed_calls INTEGER DEFAULT 0,
    failed_calls INTEGER DEFAULT 0,
    insert_date TIMESTAMP DEFAULT NOW(),
    insert_user UUID,
    FOREIGN KEY (domain_uuid) REFERENCES v_domains(domain_uuid) ON DELETE CASCADE
);

-- 3. Smart IVR Outbound Queue
CREATE TABLE IF NOT EXISTS v_smart_ivr_queue (
    queue_uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    campaign_uuid UUID,
    domain_uuid UUID NOT NULL,
    student_id VARCHAR(50),
    phone_number VARCHAR(20) NOT NULL,
    student_name VARCHAR(100),
    message TEXT,
    custom_data JSONB, -- For dynamic data like amount, date, etc.
    status VARCHAR(20) DEFAULT 'pending', -- 'pending', 'calling', 'answered', 'no_answer', 'busy', 'failed'
    attempts INTEGER DEFAULT 0,
    max_attempts INTEGER DEFAULT 3,
    feedback VARCHAR(50),
    scheduled_time TIMESTAMP,
    called_time TIMESTAMP,
    answered_time TIMESTAMP,
    hangup_time TIMESTAMP,
    call_duration INTEGER,
    insert_date TIMESTAMP DEFAULT NOW(),
    FOREIGN KEY (campaign_uuid) REFERENCES v_smart_ivr_campaigns(campaign_uuid) ON DELETE CASCADE,
    FOREIGN KEY (domain_uuid) REFERENCES v_domains(domain_uuid) ON DELETE CASCADE
);

-- 4. Smart IVR Call Logs (for both inbound and outbound)
CREATE TABLE IF NOT EXISTS v_smart_ivr_call_logs (
    log_uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid UUID NOT NULL,
    call_direction VARCHAR(10), -- 'inbound', 'outbound'
    campaign_uuid UUID,
    queue_uuid UUID,
    caller_id_number VARCHAR(50),
    student_id VARCHAR(50),
    student_name VARCHAR(100),
    call_start_time TIMESTAMP,
    call_end_time TIMESTAMP,
    call_duration INTEGER,
    menu_selections JSONB, -- Track which menu options were selected
    queries_made JSONB, -- Track what information was queried (payment, attendance, etc.)
    feedback VARCHAR(50),
    recording_path VARCHAR(500),
    insert_date TIMESTAMP DEFAULT NOW(),
    FOREIGN KEY (domain_uuid) REFERENCES v_domains(domain_uuid) ON DELETE CASCADE,
    FOREIGN KEY (campaign_uuid) REFERENCES v_smart_ivr_campaigns(campaign_uuid) ON DELETE SET NULL,
    FOREIGN KEY (queue_uuid) REFERENCES v_smart_ivr_queue(queue_uuid) ON DELETE SET NULL
);

-- 5. Smart IVR Feedback/Responses
CREATE TABLE IF NOT EXISTS v_smart_ivr_feedback (
    feedback_uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    log_uuid UUID,
    domain_uuid UUID NOT NULL,
    student_id VARCHAR(50),
    feedback_type VARCHAR(50), -- 'dtmf', 'voice', 'rating'
    feedback_value VARCHAR(100),
    question TEXT,
    insert_date TIMESTAMP DEFAULT NOW(),
    FOREIGN KEY (log_uuid) REFERENCES v_smart_ivr_call_logs(log_uuid) ON DELETE CASCADE,
    FOREIGN KEY (domain_uuid) REFERENCES v_domains(domain_uuid) ON DELETE CASCADE
);

-- 6. Smart IVR Backend API Cache (optional - for performance)
CREATE TABLE IF NOT EXISTS v_smart_ivr_api_cache (
    cache_uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid UUID NOT NULL,
    student_id VARCHAR(50),
    api_endpoint VARCHAR(200),
    cache_key VARCHAR(200),
    cache_data JSONB,
    expires_at TIMESTAMP,
    insert_date TIMESTAMP DEFAULT NOW(),
    FOREIGN KEY (domain_uuid) REFERENCES v_domains(domain_uuid) ON DELETE CASCADE
);

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_smart_ivr_queue_status ON v_smart_ivr_queue(status, scheduled_time);
CREATE INDEX IF NOT EXISTS idx_smart_ivr_queue_phone ON v_smart_ivr_queue(phone_number);
CREATE INDEX IF NOT EXISTS idx_smart_ivr_logs_student ON v_smart_ivr_call_logs(student_id);
CREATE INDEX IF NOT EXISTS idx_smart_ivr_logs_date ON v_smart_ivr_call_logs(call_start_time);
CREATE INDEX IF NOT EXISTS idx_smart_ivr_cache_key ON v_smart_ivr_api_cache(cache_key, expires_at);

-- Insert default configuration (disabled by default)
INSERT INTO v_smart_ivr_config (domain_uuid, enabled, hotline_number, welcome_message, goodbye_message)
SELECT
    domain_uuid,
    FALSE,
    NULL,
    'Welcome to Smart Student Information System. Please enter your student ID followed by hash.',
    'Thank you for using our service. Goodbye.'
FROM v_domains
ON CONFLICT DO NOTHING;

-- Grant permissions (adjust as needed)
-- GRANT ALL ON v_smart_ivr_config TO fusionpbx;
-- GRANT ALL ON v_smart_ivr_campaigns TO fusionpbx;
-- GRANT ALL ON v_smart_ivr_queue TO fusionpbx;
-- GRANT ALL ON v_smart_ivr_call_logs TO fusionpbx;
-- GRANT ALL ON v_smart_ivr_feedback TO fusionpbx;
-- GRANT ALL ON v_smart_ivr_api_cache TO fusionpbx;
