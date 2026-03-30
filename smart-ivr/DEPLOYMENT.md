# Smart IVR - Deployment and Configuration Guide

## Overview
Smart IVR is an **ADD-ON module** that provides automated student information system via phone calls. It does NOT interfere with existing IVR functionality.

## Features
- ✅ Student verification via ID or phone number
- ✅ Payment status queries
- ✅ Academic records access
- ✅ Attendance information
- ✅ Exam results
- ✅ Class schedule
- ✅ Outbound automated calls (payment reminders, class cancellations)
- ✅ DTMF feedback collection
- ✅ Google TTS integration with fallback to FreeSWITCH flite
- ✅ Enable/Disable per domain
- ✅ Call logging and analytics

---

## Prerequisites

1. FusionPBX server with PostgreSQL database
2. FreeSWITCH with Lua support
3. Backend Student API (you provide)
4. Google Cloud TTS API Key (optional, but recommended)

---

## Deployment Steps

### 1. Upload Files to Server

```bash
# From your local machine
cd /home/prototype/humayun/fusionpbx

# Copy PHP actions
sshpass -p 'Takay1#$ane%%' scp -P 22 -o StrictHostKeyChecking=no \
  php-actions/smart-ivr-*.php \
  telcobright@114.130.145.82:/tmp/

# Copy Lua scripts
sshpass -p 'Takay1#$ane%%' scp -P 22 -o StrictHostKeyChecking=no \
  smart-ivr/*.lua \
  telcobright@114.130.145.82:/tmp/

# Copy dialplan
sshpass -p 'Takay1#$ane%%' scp -P 22 -o StrictHostKeyChecking=no \
  smart-ivr/dialplan_smart_ivr.xml \
  telcobright@114.130.145.82:/tmp/

# Copy database schema
sshpass -p 'Takay1#$ane%%' scp -P 22 -o StrictHostKeyChecking=no \
  smart-ivr/database-schema.sql \
  telcobright@114.130.145.82:/tmp/
```

### 2. SSH to Server and Install

```bash
ssh -p 22 telcobright@114.130.145.82
# Password: Takay1#$ane%%

# Switch to root
sudo su -
# Password: Takay1#$ane%%

# Move PHP actions
cp /tmp/smart-ivr-*.php /var/www/fusionpbx/app/rest_api/actions/
chown www-data:www-data /var/www/fusionpbx/app/rest_api/actions/smart-ivr-*.php
chmod 644 /var/www/fusionpbx/app/rest_api/actions/smart-ivr-*.php

# Move Lua scripts
cp /tmp/smart_ivr_*.lua /usr/share/freeswitch/scripts/
chown www-data:www-data /usr/share/freeswitch/scripts/smart_ivr_*.lua
chmod 755 /usr/share/freeswitch/scripts/smart_ivr_*.lua

# Move dialplan
cp /tmp/dialplan_smart_ivr.xml /etc/freeswitch/dialplan/public/
chown www-data:www-data /etc/freeswitch/dialplan/public/dialplan_smart_ivr.xml
chmod 644 /etc/freeswitch/dialplan/public/dialplan_smart_ivr.xml

# Create audio directory for Google TTS cache
mkdir -p /usr/share/freeswitch/sounds/en/custom/smart_ivr
chown -R www-data:www-data /usr/share/freeswitch/sounds/en/custom/smart_ivr
```

### 3. Create Database Tables

```bash
# As root
su - postgres
psql fusionpbx < /tmp/database-schema.sql
exit
```

### 4. Reload FreeSWITCH Dialplan

```bash
fs_cli -x "reloadxml"
```

### 5. Configure Google Cloud TTS (Optional but Recommended)

#### Option A: Using API Key
```bash
# Set environment variable
export GOOGLE_CLOUD_TTS_API_KEY="your-api-key-here"

# Add to FreeSWITCH startup script
echo 'export GOOGLE_CLOUD_TTS_API_KEY="your-api-key-here"' >> /etc/default/freeswitch
```

#### Option B: Using Service Account JSON
```bash
# Upload service account JSON
scp your-service-account.json telcobright@114.130.145.82:/etc/freeswitch/google-tts-credentials.json

# Set environment variable
export GOOGLE_APPLICATION_CREDENTIALS="/etc/freeswitch/google-tts-credentials.json"

# Add to FreeSWITCH startup
echo 'export GOOGLE_APPLICATION_CREDENTIALS="/etc/freeswitch/google-tts-credentials.json"' >> /etc/default/freeswitch
```

#### Get Free Google TTS API Key
1. Go to https://console.cloud.google.com/
2. Create new project or select existing
3. Enable "Cloud Text-to-Speech API"
4. Go to "Credentials" → "Create Credentials" → "API Key"
5. Copy the API key

**Free Tier:** 4 million characters per month

---

## Configuration

### Enable/Disable Smart IVR

**Via REST API:**

```bash
# Enable Smart IVR
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-config-update",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959",
    "enabled": true,
    "hotline_number": "9999",
    "backend_api_url": "https://your-student-backend.com/api",
    "backend_api_key": "your-backend-api-key",
    "google_tts_enabled": true,
    "google_tts_language": "en-US",
    "welcome_message": "Welcome to Smart Student Information System",
    "goodbye_message": "Thank you for calling. Goodbye."
  }' | jq '.'

# Disable Smart IVR
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-config-update",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959",
    "enabled": false
  }' | jq '.'

# Get current configuration
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-config-get",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959"
  }' | jq '.'
```

### Configure Backend Student API

Your backend must provide these endpoints:

#### 1. Student Verification
```
POST /api/student/verify
{
  "student_id": "2021001234",
  "phone": "+8801712345678"
}

Response:
{
  "verified": true,
  "student_id": "2021001234",
  "name": "Student Name",
  "department": "Computer Science"
}
```

#### 2. Payment Status
```
GET /api/student/{student_id}/payment-status

Response:
{
  "arrears": 15000,
  "paid": 50000,
  "late_fee": 500,
  "due_date": "2026-04-30"
}
```

#### 3. Academic Records
```
GET /api/student/{student_id}/academic-records

Response:
{
  "semester": 7,
  "credits": 120,
  "cgpa": 3.5
}
```

#### 4. Attendance
```
GET /api/student/{student_id}/attendance

Response:
{
  "percentage": 85,
  "total_classes": 100,
  "attended": 85,
  "absent_days": 15
}
```

#### 5. Exam Results
```
GET /api/student/{student_id}/exam-results

Response:
{
  "pending": 2,
  "published": 5
}
```

#### 6. Class Schedule
```
GET /api/student/{student_id}/schedule

Response:
{
  "classes": [
    {"time": "10:00 AM", "subject": "Math", "room": "101"},
    {"time": "2:00 PM", "subject": "Physics", "room": "205"}
  ]
}
```

---

## Testing

### Test Inbound Calls

1. **Configure a destination/inbound route:**
```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "destination-create",
    "destination_number": "9999",
    "destination_app": "transfer",
    "destination_data": "SMART_IVR XML ${domain_name}",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959",
    "destination_description": "Smart IVR Student Hotline"
  }' | jq '.'
```

2. **Call the hotline:**
   - Dial 9999 from any phone
   - System will ask for Student ID
   - Enter Student ID followed by #
   - Navigate menu options (1-5)
   - Press 9 to exit

3. **Expected Flow:**
   ```
   "Welcome to Smart Student Information System"
   "Please enter your student ID followed by hash"
   [Enter: 2021001234#]
   "Welcome [Student Name]"
   "Press 1 for payment status, 2 for academic records..."
   [Press: 1]
   "Your total payment is 65000 taka. You have paid 50000 taka..."
   ```

### Test Outbound Calls

1. **Create a campaign:**
```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-campaign-create",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959",
    "campaign_name": "Payment Reminder Test",
    "campaign_type": "payment_reminder",
    "message_template": "This is a payment reminder. Your outstanding balance is {amount} taka. Please pay by {due_date}.",
    "feedback_prompt": "Press 1 to confirm receipt of this message",
    "tts_language": "en-US"
  }' | jq '.'
```

2. **Add students to queue:**
```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-queue-add",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959",
    "campaign_uuid": "CAMPAIGN_UUID_FROM_STEP_1",
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

3. **Trigger outbound call (via FreeSWITCH CLI):**
```bash
fs_cli
> originate sofia/gateway/your-gateway/+8801712345678 &lua(smart_ivr_outbound.lua) {domain_uuid=c0b2f64e-a0ed-41c6-a387-b1be92ea2959,queue_uuid=QUEUE_UUID}
```

---

## Monitoring and Logs

### View Call Logs
```sql
SELECT * FROM v_smart_ivr_call_logs ORDER BY call_start_time DESC LIMIT 10;
```

### View Feedback
```sql
SELECT * FROM v_smart_ivr_feedback ORDER BY insert_date DESC LIMIT 10;
```

### View Queue Status
```sql
SELECT status, COUNT(*) FROM v_smart_ivr_queue GROUP BY status;
```

### FreeSWITCH Logs
```bash
tail -f /var/log/freeswitch/freeswitch.log | grep "Smart IVR"
```

---

## Troubleshooting

### Smart IVR Not Working

1. **Check if enabled:**
```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{"action": "smart-ivr-config-get", "domain_uuid": "YOUR_DOMAIN_UUID"}' | jq '.config.enabled'
```

2. **Check Lua scripts:**
```bash
ls -la /usr/share/freeswitch/scripts/smart_ivr_*.lua
```

3. **Check dialplan:**
```bash
fs_cli -x "xml_locate dialplan"
```

4. **Test TTS:**
```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-tts-generate",
    "domain_uuid": "YOUR_DOMAIN_UUID",
    "text": "Hello, this is a test"
  }' | jq '.'
```

### Backend API Connection Issues

1. **Test API connectivity:**
```bash
curl -v -H "Authorization: Bearer YOUR_API_KEY" \
  https://your-backend.com/api/student/verify \
  -d '{"student_id": "2021001234"}'
```

2. **Check API URL in config:**
```sql
SELECT backend_api_url, backend_api_key FROM v_smart_ivr_config;
```

---

## Uninstall (if needed)

```bash
# Remove PHP actions
rm /var/www/fusionpbx/app/rest_api/actions/smart-ivr-*.php

# Remove Lua scripts
rm /usr/share/freeswitch/scripts/smart_ivr_*.lua

# Remove dialplan
rm /etc/freeswitch/dialplan/public/dialplan_smart_ivr.xml

# Drop database tables
psql fusionpbx -c "DROP TABLE IF EXISTS v_smart_ivr_feedback CASCADE;"
psql fusionpbx -c "DROP TABLE IF EXISTS v_smart_ivr_call_logs CASCADE;"
psql fusionpbx -c "DROP TABLE IF EXISTS v_smart_ivr_api_cache CASCADE;"
psql fusionpbx -c "DROP TABLE IF EXISTS v_smart_ivr_queue CASCADE;"
psql fusionpbx -c "DROP TABLE IF EXISTS v_smart_ivr_campaigns CASCADE;"
psql fusionpbx -c "DROP TABLE IF EXISTS v_smart_ivr_config CASCADE;"

# Reload FreeSWITCH
fs_cli -x "reloadxml"
```

---

## Summary

✅ Smart IVR is a standalone add-on module
✅ Does NOT affect existing IVR functionality
✅ Enable/disable per domain via REST API
✅ Integrates with your student backend API
✅ Supports Google TTS with fallback to flite
✅ Handles both inbound and outbound calls
✅ Collects DTMF feedback
✅ Full call logging and analytics

**Need help? Check FreeSWITCH logs and call logs in database.**
