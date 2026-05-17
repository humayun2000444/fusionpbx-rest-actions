# Testing Smart IVR Inbound Calls

## Prerequisites
1. Smart IVR must be **enabled** in configuration
2. Hotline number configured (default: 9999)
3. Dialplan deployed to FreeSWITCH

## Test Steps

### Step 1: Enable Smart IVR
1. Go to Smart IVR Config page
2. Toggle "Smart IVR Status" to **ON** (enabled)
3. Click "Save Configuration"

### Step 2: Register a SIP Phone
Using any softphone (Zoiper, Linphone, MicroSIP):
- Server: 114.130.145.82
- Username: (any extension on domain samsung.btcliptelephony.gov.bd)
- Password: (extension password)
- Domain: samsung.btcliptelephony.gov.bd

### Step 3: Call the Smart IVR Hotline
1. From your registered phone, dial: **9999** or **SMART_IVR**
2. You should hear the welcome message in Bengali (if configured) or English
3. Follow the voice prompts:
   - Press 1: Payment Status
   - Press 2: Academic Records
   - Press 3: Attendance
   - Press 4: Exam Results
   - Press 5: Class Schedule
   - Press 9: Exit

### Step 4: Check Call Logs
1. Go to Smart IVR → Call Logs
2. You should see your test call listed
3. Check call duration, queries made

## Expected Flow

```
1. Call 9999
   ↓
2. Hear: "স্বাগতম ছাত্র তথ্য সিস্টেমে" (Welcome message)
   ↓
3. System asks for Student ID
   ↓
4. Enter Student ID followed by # (hash)
   ↓
5. If Backend API configured: Student verified
   ↓
6. Main Menu:
   - Press 1 for Payment Status
   - Press 2 for Academic Records
   - Press 3 for Attendance
   - Press 4 for Exam Results
   - Press 5 for Class Schedule
   - Press 9 to Exit
   ↓
7. Hear information via TTS
   ↓
8. Return to main menu or exit
```

## Troubleshooting

### No Answer / Not Working
```bash
# Check if dialplan is loaded
ssh telcobright@114.130.145.82
sudo fs_cli -x "reload xml"
sudo fs_cli -x "reloadxml"
```

### Check Logs
```bash
# View FreeSWITCH logs
sudo tail -f /var/log/freeswitch/freeswitch.log | grep -i "smart_ivr"
```

### Test TTS Generation
```bash
# Direct PHP test
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "smart-ivr-tts-generate",
    "domain_uuid": "27c6bf36-93ff-4137-8896-92337da0dff1",
    "text": "স্বাগতম ছাত্র তথ্য সিস্টেমে",
    "language": "bn-IN",
    "voice_gender": "FEMALE"
  }' | jq '.'
```
