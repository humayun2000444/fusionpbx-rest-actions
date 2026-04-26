# Call Broadcast Retry Feature - Deployment Guide

## Overview
Auto-retry calls for no answer, busy, failed, or unreachable numbers with configurable retry count and interval.

## Step 1: Database Migration (Run on EACH server)

```bash
# SSH into server
ssh telcobright@103.95.96.100

# Run migration SQL on PostgreSQL
sudo -u postgres psql fusionpbx < /tmp/001_call_broadcast_retry.sql
```

**SQL file:** `php-actions/migrations/001_call_broadcast_retry.sql`

This creates:
- `v_call_broadcast_leads` table (per-number tracking with status, attempts, hangup_cause)
- Retry columns on `v_call_broadcasts`: `broadcast_retry_max`, `broadcast_retry_interval`, `broadcast_retry_enabled`, `broadcast_retry_causes`
- Performance indexes

**Safe to run multiple times** - uses `IF NOT EXISTS` and `ADD COLUMN IF NOT EXISTS`.

## Step 2: Deploy PHP Actions (Run on EACH server)

### New files to deploy:
- `call-broadcast-lead-status.php` - Returns per-number status for a broadcast
- `call-broadcast-retry.php` - Syncs CDR + re-queues failed leads

### Modified files to deploy:
- `call-broadcast-create.php` - Added retry fields (retryEnabled, retryMax, retryInterval, retryCauses)
- `call-broadcast-update.php` - Added retry field mappings
- `call-broadcast-start.php` - Inserts leads into tracking table, updates lead status to 'calling'
- `call-broadcast-scheduler.php` - Added auto-retry processing (syncs CDR + re-queues eligible leads every minute)

### Deploy command:
```bash
# Copy files to server
sshpass -p 'Takay1#$ane%%' scp -P 22 -o StrictHostKeyChecking=no \
  /home/prototype/humayun/fusionpbx/php-actions/call-broadcast-create.php \
  /home/prototype/humayun/fusionpbx/php-actions/call-broadcast-update.php \
  /home/prototype/humayun/fusionpbx/php-actions/call-broadcast-start.php \
  /home/prototype/humayun/fusionpbx/php-actions/call-broadcast-scheduler.php \
  /home/prototype/humayun/fusionpbx/php-actions/call-broadcast-lead-status.php \
  /home/prototype/humayun/fusionpbx/php-actions/call-broadcast-retry.php \
  /home/prototype/humayun/fusionpbx/php-actions/migrations/001_call_broadcast_retry.sql \
  telcobright@103.95.96.100:/tmp/

# Deploy PHP files
sshpass -p 'Takay1#$ane%%' ssh -p 22 -o StrictHostKeyChecking=no telcobright@103.95.96.100 \
  "echo 'Takay1#\$ane%%' | sudo -S cp /tmp/call-broadcast-*.php /var/www/fusionpbx/app/rest_api/actions/ && \
   echo 'Takay1#\$ane%%' | sudo -S chown www-data:www-data /var/www/fusionpbx/app/rest_api/actions/call-broadcast-*.php"

# Run DB migration
sshpass -p 'Takay1#$ane%%' ssh -p 22 -o StrictHostKeyChecking=no telcobright@103.95.96.100 \
  "echo 'Takay1#\$ane%%' | sudo -S -u postgres psql fusionpbx < /tmp/001_call_broadcast_retry.sql"
```

## Step 3: Deploy Java Proxy (RTC-Manager)

### Modified files:
- `CallBroadcastController.java` - Added `/v1/lead-status` and `/v1/retry` endpoints
- `FusionPbxCallBroadcastService.java` - Added `getLeadStatus()` and `retryBroadcast()` methods
- `DatabaseContextResolver.java` - Added new endpoints to whitelist

### Build & deploy:
```bash
cd /home/prototype/Documents/AllProjects/RTC-Manager/FreeSwitchREST
mvn clean package -DskipTests
# Deploy the JAR to server
```

## Step 4: Deploy Frontend

### Modified files:
- `src/config/index.js` - Added `leadStatus` and `retry` endpoints
- `src/pages/CallBroadcastEdit.jsx` - Added retry config UI (max retries, interval, causes)
- `src/pages/CallBroadcast.jsx` - Added retry button, lead status view in details modal

### Build & deploy:
```bash
cd /home/prototype/Documents/AllProjects/btcl-hosted-pbx
npm run build
# Deploy dist/ to server
```

## How It Works

### Flow:
1. **Create campaign** with retry settings (max retries, interval, which hangup causes to retry)
2. **Start broadcast** - leads inserted into `v_call_broadcast_leads` with status `pending`
3. Calls scheduled via FreeSWITCH - leads updated to `calling`
4. **Scheduler runs every minute** and:
   a. Checks CDR for completed calls (leads in `calling` state)
   b. Updates lead status based on hangup_cause: `answered`, `no_answer`, `busy`, `failed`
   c. If hangup_cause is retryable AND attempts < max_attempts: sets `retry_pending` + `next_retry_at`
   d. Re-queues `retry_pending` leads whose `next_retry_at` has passed
5. **Manual retry** also available via API/UI button

### Lead Status Flow:
```
pending -> calling -> answered (done)
                   -> no_answer/busy/failed -> retry_pending -> calling -> ...
                   -> skipped (max retries reached)
```

### Backward Compatibility:
- Existing broadcasts without retry fields default to `retry_enabled=false`, `retry_max=0`
- No retry happens unless explicitly enabled
- `v_call_broadcast_leads` table is new - no impact on existing data
- Scheduler changes only add new code paths, existing schedule logic unchanged

## Verify After Deploy

```bash
# Check table exists
sudo -u postgres psql fusionpbx -c "SELECT COUNT(*) FROM v_call_broadcast_leads;"

# Check columns added
sudo -u postgres psql fusionpbx -c "SELECT broadcast_retry_enabled, broadcast_retry_max FROM v_call_broadcasts LIMIT 1;"

# Check scheduler log
tail -20 /var/log/fusionpbx/call_broadcast_scheduler.log
```
