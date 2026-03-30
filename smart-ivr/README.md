# Smart IVR - Student Information System

## Overview
Smart IVR is a **completely separate add-on module** for FusionPBX that provides automated student information via phone calls. It does **NOT interfere** with existing IVR functionality.

## Status: ✅ DEPLOYED AND READY

### Deployment Date: 2026-03-30
### Server: 114.130.145.82

---

## Features Implemented

### Inbound Calls (Student Queries)
- ✅ Student verification via Student ID or phone number
- ✅ Payment status (arrears, paid amount, late fees)
- ✅ Academic records (semester, credits, CGPA)
- ✅ Attendance information
- ✅ Exam results
- ✅ Class schedule
- ✅ Multi-level DTMF menu navigation

### Outbound Calls (Automated Campaigns)
- ✅ Campaign management (payment reminders, class cancellations, exam notices)
- ✅ Queue system for bulk calls
- ✅ DTMF feedback collection
- ✅ Retry logic for failed calls
- ✅ Call status tracking

### Text-to-Speech
- ✅ Google Cloud TTS integration (free tier: 4M chars/month)
- ✅ Automatic fallback to FreeSWITCH flite
- ✅ Audio caching for performance
- ✅ Multi-language support

### Management & Monitoring
- ✅ Enable/Disable per domain via REST API
- ✅ Configuration management
- ✅ Call logging and analytics
- ✅ Feedback tracking
- ✅ API response caching

---

## Files Deployed

### PHP REST API Actions (8 files)
Location: `/var/www/fusionpbx/app/rest_api/actions/`

1. **smart-ivr-config-get.php** - Get configuration
2. **smart-ivr-config-update.php** - Update config (enable/disable)
3. **smart-ivr-student-verify.php** - Verify student
4. **smart-ivr-query-data.php** - Query student data
5. **smart-ivr-tts-generate.php** - Generate Google TTS audio
6. **smart-ivr-campaign-create.php** - Create outbound campaign
7. **smart-ivr-queue-add.php** - Add to call queue
8. **smart-ivr-feedback-save.php** - Save DTMF feedback

### FreeSWITCH Lua Scripts (2 files)
Location: `/usr/share/freeswitch/scripts/`

1. **smart_ivr_inbound.lua** - Handles incoming student calls
2. **smart_ivr_outbound.lua** - Handles outbound campaign calls

### Dialplan
Location: `/etc/freeswitch/dialplan/`

1. **dialplan_smart_ivr.xml** - Smart IVR routing (separate from regular IVR)

### Database Tables (6 tables)
- **v_smart_ivr_config** - Configuration per domain
- **v_smart_ivr_campaigns** - Outbound campaign definitions
- **v_smart_ivr_queue** - Outbound call queue
- **v_smart_ivr_call_logs** - All call logs (inbound + outbound)
- **v_smart_ivr_feedback** - DTMF/voice feedback
- **v_smart_ivr_api_cache** - Backend API response cache (5 min TTL)

---

## Quick Start

### 1. Configure Your Backend API

```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-config-update",
    "domain_uuid": "c0b2f64e-a0ed-41c6-a387-b1be92ea2959",
    "enabled": true,
    "hotline_number": "9999",
    "backend_api_url": "https://YOUR_STUDENT_API/api",
    "backend_api_key": "YOUR_API_KEY"
  }' | jq '.'
```

### 2. Create Hotline Number (9999)

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
    "destination_description": "Smart IVR Hotline"
  }' | jq '.'
```

### 3. Test It

**Dial 9999** from any phone and follow the prompts:
- Enter Student ID
- Press 1 for payment status
- Press 2 for academic records
- Press 3 for attendance
- Press 4 for exam results
- Press 5 for class schedule
- Press 9 to exit

---

## Required Backend API Endpoints

Your student backend must provide these endpoints:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/student/verify` | POST | Verify student by ID or phone |
| `/api/student/{id}/payment-status` | GET | Get payment info |
| `/api/student/{id}/academic-records` | GET | Get semester, credits, CGPA |
| `/api/student/{id}/attendance` | GET | Get attendance percentage |
| `/api/student/{id}/exam-results` | GET | Get exam results |
| `/api/student/{id}/schedule` | GET | Get class schedule |

**Authentication:** Bearer token via `Authorization` header

---

## Enable/Disable Smart IVR

### Disable Smart IVR
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

### Enable Smart IVR
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

## Documentation

| File | Purpose |
|------|---------|
| **README.md** | This file - overview and quick start |
| **DEPLOYMENT.md** | Full deployment guide with all details |
| **TESTING_GUIDE.md** | Step-by-step testing instructions |
| **database-schema.sql** | Database table definitions |

---

## Architecture

```
                    ┌─────────────────────┐
                    │   Student Phone     │
                    └──────────┬──────────┘
                               │
                        Dials 9999
                               │
                               ▼
                    ┌─────────────────────┐
                    │    FreeSWITCH       │
                    │   (Dialplan XML)    │
                    └──────────┬──────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │ smart_ivr_inbound   │
                    │    (Lua Script)     │
                    └──────────┬──────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │  Smart IVR PHP API  │
                    │  (REST Actions)     │
                    └──────────┬──────────┘
                               │
                ┌──────────────┼──────────────┐
                │              │              │
                ▼              ▼              ▼
         ┌──────────┐   ┌──────────┐   ┌──────────┐
         │ Student  │   │ Google   │   │PostgreSQL│
         │ Backend  │   │   TTS    │   │ Database │
         │   API    │   │   API    │   │          │
         └──────────┘   └──────────┘   └──────────┘
```

---

## Key Technical Details

### Database Tables
- All tables prefixed with `v_smart_ivr_*`
- Separate from existing IVR tables
- Foreign keys to `v_domains` for multi-tenancy
- JSONB fields for flexible data storage
- Automatic timestamps

### Security
- REST API authentication via FusionPBX API key
- Backend API uses Bearer token auth
- Domain-level isolation
- SQL injection protection via parameterized queries

### Performance
- 5-minute API response caching
- Audio file caching for Google TTS
- Database indexes on frequently queried fields
- Efficient queue processing

### Scalability
- Supports multiple domains
- Unlimited concurrent calls (FreeSWITCH limit)
- Queue-based outbound calling
- Retry logic for failed calls

---

## Monitoring

### View Call Logs
```bash
ssh telcobright@114.130.145.82
sudo -u postgres psql fusionpbx -c "
  SELECT student_id, call_direction, call_start_time, queries_made, feedback
  FROM v_smart_ivr_call_logs
  ORDER BY call_start_time DESC
  LIMIT 10;
"
```

### View Queue Status
```bash
ssh telcobright@114.130.145.82
sudo -u postgres psql fusionpbx -c "
  SELECT status, COUNT(*) as count
  FROM v_smart_ivr_queue
  GROUP BY status;
"
```

### FreeSWITCH Logs
```bash
ssh telcobright@114.130.145.82
tail -f /var/log/freeswitch/freeswitch.log | grep -i "smart"
```

---

## Google Cloud TTS Setup (Optional)

### Get Free API Key
1. Go to https://console.cloud.google.com/
2. Create new project
3. Enable "Cloud Text-to-Speech API"
4. Create API key
5. Free tier: **4 million characters/month**

### Configure API Key
```bash
ssh telcobright@114.130.145.82
echo 'export GOOGLE_CLOUD_TTS_API_KEY="your-api-key"' | sudo tee -a /etc/default/freeswitch
sudo systemctl restart freeswitch
```

**Without Google TTS:** System automatically uses FreeSWITCH flite (robotic voice)

---

## Support

### Troubleshooting
- Check DEPLOYMENT.md for detailed troubleshooting steps
- Check TESTING_GUIDE.md for step-by-step testing
- Check FreeSWITCH logs: `/var/log/freeswitch/freeswitch.log`
- Check call logs: `SELECT * FROM v_smart_ivr_call_logs`

### Common Issues

**Smart IVR not answering:**
- Check if enabled: `smart-ivr-config-get` API
- Check dialplan loaded: `fs_cli -x 'reloadxml'`
- Check Lua scripts exist: `ls /usr/share/freeswitch/scripts/smart_ivr_*.lua`

**Backend API errors:**
- Test API directly with curl
- Check API URL in config
- Check API key is correct

**TTS not working:**
- Google TTS will auto-fallback to flite
- Check Google API key environment variable
- Check audio directory permissions

---

## Summary

✅ **Smart IVR is deployed and ready to use**
✅ **Does NOT interfere with existing IVR**
✅ **Enable/Disable per domain**
✅ **Full call logging and analytics**
✅ **Google TTS with fallback**
✅ **Inbound + Outbound calling**
✅ **DTMF feedback collection**

**Next Steps:**
1. Provide your Student Backend API URL and key
2. Test inbound calls by dialing 9999
3. Create outbound campaigns if needed
4. Monitor call logs for debugging

**All documentation is in `/home/prototype/humayun/fusionpbx/smart-ivr/`**
