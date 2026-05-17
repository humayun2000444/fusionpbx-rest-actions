# Testing Smart IVR Outbound Campaigns

## Prerequisites
1. Smart IVR enabled
2. At least one SIP gateway configured for outbound calls
3. Test phone number to receive calls

## Test Steps

### Step 1: Create a Test Campaign

**Using the UI:**
1. Go to **Smart IVR → Campaigns**
2. Click "Create Campaign" button
3. Fill in the form:
   - **Campaign Name**: "Test Payment Reminder"
   - **Campaign Type**: Payment Reminder
   - **Message Template**:
     ```
     Hello {student_name}, this is a reminder that your tuition fee of {amount} taka is due. Please pay before {due_date}. Thank you.
     ```
   - **TTS Language**: Bengali India (WaveNet - Natural)
   - **Feedback Prompt**: "Press 1 to confirm, 2 for more information"
4. Click "Create"

**Using API (Alternative):**
```bash
curl -s -k -X POST https://vbs.btcliptelephony.gov.bd:4000/FREESWITCHREST/api/v1/smart-ivr/campaign-create \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -d '{
    "domain_uuid": "27c6bf36-93ff-4137-8896-92337da0dff1",
    "campaign_name": "Test Payment Reminder",
    "campaign_type": "payment_reminder",
    "message_template": "Hello {student_name}, this is a reminder about your payment.",
    "tts_language": "bn-IN",
    "feedback_prompt": "Press 1 to confirm"
  }' | jq '.'
```

### Step 2: Add Students to Queue

**Using API:**
```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-queue-add",
    "domain_uuid": "27c6bf36-93ff-4137-8896-92337da0dff1",
    "campaign_uuid": "YOUR_CAMPAIGN_UUID",
    "students": [
      {
        "student_id": "12345",
        "phone_number": "01712345678",
        "student_name": "Test Student",
        "custom_data": {
          "amount": "5000",
          "due_date": "2026-04-15"
        }
      }
    ]
  }' | jq '.'
```

### Step 3: Check Queue Status

1. Go to **Smart IVR → Queue**
2. You should see your added student
3. Status will show: `pending`

### Step 4: Manually Trigger Outbound Call (For Testing)

You need to manually originate the call from FreeSWITCH:

```bash
# SSH to FreeSWITCH server
ssh telcobright@114.130.145.82

# Enter FreeSWITCH CLI
sudo fs_cli

# Originate test call
originate sofia/gateway/YOUR_GATEWAY/01712345678 &lua(/usr/share/freeswitch/scripts/smart_ivr_outbound.lua queue_uuid=YOUR_QUEUE_UUID)
```

### Step 5: Monitor Call

**Check Logs:**
```bash
# View Smart IVR logs page in UI
Go to Smart IVR → Call Logs

# Or check FreeSWITCH logs
sudo tail -f /var/log/freeswitch/freeswitch.log | grep -i "smart_ivr"
```

### Step 6: Check Call Results

1. Go to **Smart IVR → Call Logs**
2. Filter by date: "Today"
3. You should see:
   - Call direction: Outbound
   - Student info
   - Call duration
   - Feedback collected (if any)

## Automated Campaign Execution

For automated execution, you need a scheduler. Create a cron job:

```bash
# Edit crontab
crontab -e

# Add entry to check queue every 5 minutes
*/5 * * * * /usr/bin/php /var/www/fusionpbx/app/rest_api/scripts/smart_ivr_queue_processor.php

# Or use FreeSWITCH XML CDR to trigger on call completion
```

## Expected Outbound Call Flow

```
1. System dials student phone: 01712345678
   ↓
2. Student answers
   ↓
3. Play campaign message:
   "Hello Test Student, this is a reminder about your payment of 5000 taka due on April 15"
   ↓
4. Play feedback prompt:
   "Press 1 to confirm, 2 for more information"
   ↓
5. Student presses DTMF digit
   ↓
6. System records feedback
   ↓
7. Play goodbye message
   ↓
8. Call ends, logs saved
```

## Quick Test Commands

### 1. Check Campaign List
```bash
curl -s -k https://vbs.btcliptelephony.gov.bd:4000/FREESWITCHREST/api/v1/smart-ivr/campaigns-list \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"domain_uuid": "27c6bf36-93ff-4137-8896-92337da0dff1"}' | jq '.'
```

### 2. Check Queue
```bash
curl -s -k https://vbs.btcliptelephony.gov.bd:4000/FREESWITCHREST/api/v1/smart-ivr/queue-list \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"domain_uuid": "27c6bf36-93ff-4137-8896-92337da0dff1"}' | jq '.'
```

### 3. Check Dashboard Stats
```bash
curl -s -k https://vbs.btcliptelephony.gov.bd:4000/FREESWITCHREST/api/v1/smart-ivr/dashboard \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{"domain_uuid": "27c6bf36-93ff-4137-8896-92337da0dff1"}' | jq '.'
```
