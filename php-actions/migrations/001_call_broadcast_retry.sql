-- =============================================================================
-- Call Broadcast Retry Mechanism - Database Migration
-- Run this on each server's PostgreSQL database
-- Safe to run multiple times (uses IF NOT EXISTS)
-- =============================================================================

-- 1. Add retry configuration columns to v_call_broadcasts
ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_retry_max INTEGER DEFAULT 0;
ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_retry_interval INTEGER DEFAULT 300;
ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_retry_enabled VARCHAR(8) DEFAULT 'false';
ALTER TABLE v_call_broadcasts ADD COLUMN IF NOT EXISTS broadcast_retry_causes TEXT DEFAULT 'NO_ANSWER,ORIGINATOR_CANCEL,USER_BUSY,NO_USER_RESPONSE,CALL_REJECTED,NORMAL_TEMPORARY_FAILURE';

-- 2. Create leads tracking table
CREATE TABLE IF NOT EXISTS v_call_broadcast_leads (
    call_broadcast_lead_uuid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    call_broadcast_uuid UUID NOT NULL REFERENCES v_call_broadcasts(call_broadcast_uuid) ON DELETE CASCADE,
    domain_uuid UUID NOT NULL,
    phone_number VARCHAR(32) NOT NULL,
    lead_status VARCHAR(32) DEFAULT 'pending',
    -- pending, calling, answered, no_answer, busy, failed, retry_pending, completed, skipped
    hangup_cause VARCHAR(128),
    attempts INTEGER DEFAULT 0,
    max_attempts INTEGER DEFAULT 1,
    next_retry_at TIMESTAMP,
    last_attempt_at TIMESTAMP,
    call_duration INTEGER DEFAULT 0,
    billsec INTEGER DEFAULT 0,
    xml_cdr_uuid UUID,
    insert_date TIMESTAMP DEFAULT NOW(),
    update_date TIMESTAMP
);

-- 3. Indexes for performance
CREATE INDEX IF NOT EXISTS idx_broadcast_leads_broadcast ON v_call_broadcast_leads(call_broadcast_uuid);
CREATE INDEX IF NOT EXISTS idx_broadcast_leads_status ON v_call_broadcast_leads(lead_status);
CREATE INDEX IF NOT EXISTS idx_broadcast_leads_retry ON v_call_broadcast_leads(lead_status, next_retry_at) WHERE lead_status = 'retry_pending';
CREATE INDEX IF NOT EXISTS idx_broadcast_leads_domain ON v_call_broadcast_leads(domain_uuid);
CREATE INDEX IF NOT EXISTS idx_broadcast_leads_phone ON v_call_broadcast_leads(call_broadcast_uuid, phone_number);

-- =============================================================================
-- NOTES:
-- - broadcast_retry_max: 0 = no retries, 1-10 = number of retries
-- - broadcast_retry_interval: seconds between retries (default 5 min)
-- - broadcast_retry_causes: comma-separated FreeSWITCH hangup causes to retry
-- - lead_status flow: pending -> calling -> answered/no_answer/busy/failed
--   If retryable: no_answer/busy/failed -> retry_pending -> calling -> ...
--   Final: answered/completed/skipped (max retries reached)
-- =============================================================================
