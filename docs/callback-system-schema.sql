-- ============================================
-- FusionPBX Callback System - Database Schema
-- ============================================

-- Callback Configuration (per domain or queue)
CREATE TABLE v_callback_configs (
    callback_config_uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid UUID NOT NULL,
    queue_uuid UUID,  -- NULL means domain-wide default
    config_name VARCHAR(100) NOT NULL,
    enabled BOOLEAN DEFAULT TRUE,

    -- Trigger Settings
    trigger_on_timeout BOOLEAN DEFAULT TRUE,
    trigger_on_abandoned BOOLEAN DEFAULT TRUE,
    trigger_on_no_answer BOOLEAN DEFAULT FALSE,
    trigger_on_busy BOOLEAN DEFAULT FALSE,
    trigger_after_hours BOOLEAN DEFAULT TRUE,
    min_wait_time INT DEFAULT 0,  -- seconds caller must wait before callback

    -- Retry Settings
    max_attempts INT DEFAULT 3,
    retry_interval INT DEFAULT 300,  -- seconds between attempts
    retry_multiplier DECIMAL(3,2) DEFAULT 1.5,  -- exponential backoff: 5min, 7.5min, 11.25min

    -- Callback Timing
    immediate_callback BOOLEAN DEFAULT FALSE,  -- callback immediately or wait for agent
    wait_for_agent BOOLEAN DEFAULT TRUE,  -- only callback when agent available

    -- Customer Experience
    play_announcement BOOLEAN DEFAULT TRUE,
    announcement_text TEXT DEFAULT 'Thank you for calling. We are connecting you to an agent.',
    announcement_file VARCHAR(255),

    -- Priority
    default_priority INT DEFAULT 5,
    vip_priority INT DEFAULT 10,

    -- Limits
    max_callbacks_per_hour INT DEFAULT 100,
    max_callbacks_per_day INT DEFAULT 500,

    insert_date TIMESTAMP DEFAULT NOW(),
    insert_user UUID,
    update_date TIMESTAMP,
    update_user UUID,

    FOREIGN KEY (domain_uuid) REFERENCES v_domains(domain_uuid) ON DELETE CASCADE
);

-- Callback Schedules (time windows when callbacks are active)
CREATE TABLE v_callback_schedules (
    schedule_uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    callback_config_uuid UUID NOT NULL,
    schedule_name VARCHAR(100),

    -- Day of week (0=Sunday, 6=Saturday)
    day_of_week INT,  -- NULL means all days

    -- Time range
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,

    -- Date range (for holidays, special events)
    start_date DATE,
    end_date DATE,

    -- Schedule type
    schedule_type VARCHAR(20) DEFAULT 'business_hours',  -- business_hours, after_hours, weekend, holiday

    enabled BOOLEAN DEFAULT TRUE,
    priority INT DEFAULT 0,  -- higher priority schedules override lower

    FOREIGN KEY (callback_config_uuid) REFERENCES v_callback_configs(callback_config_uuid) ON DELETE CASCADE
);

-- Callback Queue (pending and completed callbacks)
CREATE TABLE v_callback_queue (
    callback_uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid UUID NOT NULL,
    callback_config_uuid UUID,

    -- Caller Information
    caller_id_name VARCHAR(150),
    caller_id_number VARCHAR(50) NOT NULL,
    destination_number VARCHAR(50) NOT NULL,

    -- Queue/Agent Assignment
    queue_uuid UUID,
    queue_name VARCHAR(100),
    assigned_agent_uuid UUID,

    -- Original Call Information
    original_call_uuid UUID,
    original_call_time TIMESTAMP,
    wait_duration INT,  -- seconds waited before hangup
    hangup_cause VARCHAR(50),

    -- Callback Status
    status VARCHAR(20) DEFAULT 'pending',  -- pending, scheduled, calling, answered, completed, failed, cancelled
    priority INT DEFAULT 5,

    -- Attempt Tracking
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    last_attempt_time TIMESTAMP,
    next_attempt_time TIMESTAMP,

    -- Schedule
    scheduled_time TIMESTAMP,  -- when to make the callback

    -- Result
    callback_call_uuid UUID,
    callback_start_time TIMESTAMP,
    callback_answer_time TIMESTAMP,
    callback_end_time TIMESTAMP,
    callback_duration INT,
    callback_result VARCHAR(50),  -- answered, no_answer, busy, failed, network_error

    -- Notes
    notes TEXT,

    -- Audit
    created_date TIMESTAMP DEFAULT NOW(),
    created_by VARCHAR(50) DEFAULT 'system',
    updated_date TIMESTAMP,
    completed_date TIMESTAMP,

    FOREIGN KEY (domain_uuid) REFERENCES v_domains(domain_uuid) ON DELETE CASCADE,
    FOREIGN KEY (callback_config_uuid) REFERENCES v_callback_configs(callback_config_uuid) ON DELETE SET NULL,
    FOREIGN KEY (queue_uuid) REFERENCES v_call_center_queues(call_center_queue_uuid) ON DELETE SET NULL
);

-- Callback Attempt History
CREATE TABLE v_callback_attempts (
    attempt_uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    callback_uuid UUID NOT NULL,
    attempt_number INT NOT NULL,
    attempt_time TIMESTAMP DEFAULT NOW(),

    -- Call Details
    call_uuid UUID,
    call_start_time TIMESTAMP,
    call_answer_time TIMESTAMP,
    call_end_time TIMESTAMP,
    call_duration INT,

    -- Result
    result VARCHAR(50),  -- answered, no_answer, busy, failed, error
    hangup_cause VARCHAR(50),

    -- Agent
    agent_uuid UUID,
    agent_name VARCHAR(100),

    notes TEXT,

    FOREIGN KEY (callback_uuid) REFERENCES v_callback_queue(callback_uuid) ON DELETE CASCADE
);

-- Callback Statistics (for reporting and limits)
CREATE TABLE v_callback_stats (
    stat_uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid UUID NOT NULL,
    callback_config_uuid UUID,
    stat_date DATE NOT NULL,
    stat_hour INT,  -- 0-23

    callbacks_created INT DEFAULT 0,
    callbacks_completed INT DEFAULT 0,
    callbacks_failed INT DEFAULT 0,
    callbacks_cancelled INT DEFAULT 0,

    total_attempts INT DEFAULT 0,
    average_attempts DECIMAL(4,2),

    success_rate DECIMAL(5,2),

    FOREIGN KEY (domain_uuid) REFERENCES v_domains(domain_uuid) ON DELETE CASCADE,
    UNIQUE (domain_uuid, callback_config_uuid, stat_date, stat_hour)
);

-- Blacklist/Whitelist for callback numbers
CREATE TABLE v_callback_number_filters (
    filter_uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid UUID NOT NULL,
    callback_config_uuid UUID,

    number_pattern VARCHAR(50) NOT NULL,  -- supports wildcards: 1234*, *5678, 1234*5678
    filter_type VARCHAR(10) NOT NULL,  -- blacklist, whitelist

    reason VARCHAR(255),
    enabled BOOLEAN DEFAULT TRUE,

    created_date TIMESTAMP DEFAULT NOW(),
    created_by UUID,

    FOREIGN KEY (domain_uuid) REFERENCES v_domains(domain_uuid) ON DELETE CASCADE
);

-- Indexes for performance
CREATE INDEX idx_callback_queue_status ON v_callback_queue(status, next_attempt_time);
CREATE INDEX idx_callback_queue_domain ON v_callback_queue(domain_uuid, status);
CREATE INDEX idx_callback_queue_scheduled ON v_callback_queue(scheduled_time) WHERE status = 'scheduled';
CREATE INDEX idx_callback_queue_caller ON v_callback_queue(caller_id_number, created_date);
CREATE INDEX idx_callback_schedules_config ON v_callback_schedules(callback_config_uuid, enabled);
CREATE INDEX idx_callback_stats_date ON v_callback_stats(domain_uuid, stat_date);
CREATE INDEX idx_callback_attempts_callback ON v_callback_attempts(callback_uuid, attempt_time);

-- Views for easier querying
CREATE VIEW v_callback_queue_active AS
SELECT * FROM v_callback_queue
WHERE status IN ('pending', 'scheduled', 'calling')
ORDER BY priority DESC, next_attempt_time ASC;

CREATE VIEW v_callback_queue_today AS
SELECT * FROM v_callback_queue
WHERE DATE(created_date) = CURRENT_DATE
ORDER BY created_date DESC;
