# Smart IVR - Quick Testing Guide

## Deployment Status
✅ PHP REST API actions installed
✅ Lua scripts deployed
✅ Dialplan configured
✅ Database tables created
✅ FreeSWITCH reloaded

---

## Step 1: Configure Your Backend API

You need to provide your student backend API details. Update Smart IVR configuration:

```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-config-update",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959",
    "enabled": true,
    "hotline_number": "9999",
    "backend_api_url": "https://YOUR_STUDENT_API_URL/api",
    "backend_api_key": "YOUR_API_KEY_HERE",
    "google_tts_enabled": true,
    "google_tts_language": "en-US"
  }' | jq '.'
```

**Replace:**
- `YOUR_STUDENT_API_URL` - Your student backend API base URL
- `YOUR_API_KEY_HERE` - Your backend API authentication key

---

## Step 2: Verify Configuration

```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-config-get",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959"
  }' | jq '.'
```

**Expected output:**
```json
{
  "success": true,
  "config": {
    "enabled": true,
    "hotline_number": "9999",
    "backend_api_url": "https://...",
    "google_tts_enabled": true
  }
}
```

---

## Step 3: Create Inbound Route (Hotline Number)

```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "destination-create",
    "destination_number": "9999",
    "destination_app": "transfer",
    "destination_data": "SMART_IVR XML pbx-dgmsw-sbn.btcliptelephony.gov.bd",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959",
    "destination_description": "Smart IVR Student Hotline"
  }' | jq '.'
```

**Expected output:**
```json
{
  "destination_uuid": "...",
  "destination_number": "9999",
  "destination_app": "transfer",
  "destination_data": "SMART_IVR XML ...",
  "reloaded": true
}
```

---

## Step 4: Test TTS (Text-to-Speech)

```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-tts-generate",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959",
    "text": "Welcome to Smart Student Information System",
    "language": "en-US"
  }' | jq '.'
```

**Expected output (with Google TTS):**
```json
{
  "success": true,
  "tts_type": "google",
  "audio_file": "smart_ivr_xyz.wav"
}
```

**Expected output (without Google TTS):**
```json
{
  "success": true,
  "tts_type": "flite",
  "tts_string": "speak|flite|rms|Welcome to..."
}
```

---

## Step 5: Test Student Verification

```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-student-verify",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959",
    "student_id": "2021001234",
    "phone_number": "+8801712345678"
  }' | jq '.'
```

**Expected output:**
```json
{
  "success": true,
  "verified": true,
  "student_id": "2021001234",
  "student_name": "...",
  "log_uuid": "..."
}
```

---

## Step 6: Test Data Queries

### Test Payment Status
```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-query-data",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959",
    "student_id": "2021001234",
    "query_type": "payment"
  }' | jq '.'
```

### Test Academic Records
```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-query-data",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959",
    "student_id": "2021001234",
    "query_type": "academic"
  }' | jq '.'
```

---

## Step 7: Test Inbound Call

### Option A: Call from your phone
1. Dial **9999** from any registered extension
2. System will answer with: "Welcome to Smart Student Information System"
3. Enter your student ID followed by **#**
4. System will verify and greet you
5. Navigate the menu:
   - Press **1** for payment status
   - Press **2** for academic records
   - Press **3** for attendance
   - Press **4** for exam results
   - Press **5** for class schedule
   - Press **9** to exit

### Option B: Test via FreeSWITCH CLI
```bash
ssh telcobright@114.130.145.82
fs_cli
> originate user/1001 &lua(smart_ivr_inbound.lua)
```

---

## Step 8: Test Outbound Call Campaign

### Create a test campaign
```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-campaign-create",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959",
    "campaign_name": "Test Payment Reminder",
    "campaign_type": "payment_reminder",
    "message_template": "Your outstanding balance is {amount} taka. Please pay by {due_date}.",
    "feedback_prompt": "Press 1 to confirm receipt",
    "tts_language": "en-US"
  }' | jq '.'
```

### Add a student to the queue
```bash
# Replace CAMPAIGN_UUID with the UUID from above
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-queue-add",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959",
    "campaign_uuid": "CAMPAIGN_UUID_HERE",
    "students": [
      {
        "student_id": "2021001234",
        "phone": "+8801712345678",
        "name": "Test Student",
        "custom_data": {
          "amount": 15000,
          "due_date": "April 30"
        }
      }
    ]
  }' | jq '.'
```

### Trigger the outbound call
```bash
ssh telcobright@114.130.145.82
fs_cli
> originate sofia/gateway/YOUR_GATEWAY/+8801712345678 &lua(smart_ivr_outbound.lua)
```

---

## Step 9: Monitor and Debug

### View call logs
```bash
ssh telcobright@114.130.145.82
sudo -u postgres psql fusionpbx -c "SELECT * FROM v_smart_ivr_call_logs ORDER BY call_start_time DESC LIMIT 5;"
```

### View queue status
```bash
ssh telcobright@114.130.145.82
sudo -u postgres psql fusionpbx -c "SELECT status, COUNT(*) FROM v_smart_ivr_queue GROUP BY status;"
```

### View FreeSWITCH logs
```bash
ssh telcobright@114.130.145.82
tail -f /var/log/freeswitch/freeswitch.log | grep -i "smart"
```

### Check installed files
```bash
ssh telcobright@114.130.145.82
ls -la /var/www/fusionpbx/app/rest_api/actions/smart-ivr-*.php
ls -la /usr/share/freeswitch/scripts/smart_ivr_*.lua
ls -la /etc/freeswitch/dialplan/dialplan_smart_ivr.xml
```

---

## Troubleshooting

### Smart IVR not answering
1. Check if enabled:
```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{"action": "smart-ivr-config-get", "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959"}' | jq '.config.enabled'
```

2. Check dialplan loaded:
```bash
ssh telcobright@114.130.145.82 "fs_cli -x 'xml_locate dialplan' | grep -i smart_ivr"
```

3. Check Lua script exists:
```bash
ssh telcobright@114.130.145.82 "ls -la /usr/share/freeswitch/scripts/smart_ivr_*.lua"
```

### Backend API not responding
1. Test API directly:
```bash
curl -v -H "Authorization: Bearer YOUR_API_KEY" \
  https://your-backend-api.com/api/student/verify \
  -d '{"student_id": "2021001234"}'
```

2. Check API URL in config:
```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{"action": "smart-ivr-config-get", "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959"}' \
  | jq '.config.backend_api_url'
```

### Google TTS not working
- Smart IVR will automatically fallback to FreeSWITCH flite TTS
- To enable Google TTS, set environment variable:
```bash
ssh telcobright@114.130.145.82
echo 'export GOOGLE_CLOUD_TTS_API_KEY="your-key"' | sudo tee -a /etc/default/freeswitch
sudo systemctl restart freeswitch
```

---

## Enable/Disable Smart IVR

### Disable (turn off)
```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-config-update",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959",
    "enabled": false
  }' | jq '.'
```

### Enable (turn on)
```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-config-update",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959",
    "enabled": true
  }' | jq '.'
```

---

## Summary

✅ **Smart IVR deployed and ready to test**
✅ **Files installed on 114.130.145.82**
✅ **Database tables created**
✅ **Dialplan loaded**

**Next Steps:**
1. Configure your backend Student API URL and key
2. Create inbound route (destination 9999)
3. Call 9999 to test
4. Monitor logs for debugging

**Need help? Check:**
- Full deployment guide: `/home/prototype/humayun/fusionpbx/smart-ivr/DEPLOYMENT.md`
- FreeSWITCH logs: `/var/log/freeswitch/freeswitch.log`
- Database logs: `SELECT * FROM v_smart_ivr_call_logs`
