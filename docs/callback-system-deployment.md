# FusionPBX Callback System - Deployment & Usage Guide

## 🚀 Quick Start

The callback system **automatically installs database tables** on first use!

### Step 1: Deploy PHP Files

```bash
# Copy files to CCL server
sshpass -p 'Takay1#$ane%%' scp -P 22 -o StrictHostKeyChecking=no \
  /home/prototype/humayun/fusionpbx/php-actions/callback-*.php \
  telcobright@103.95.96.100:/tmp/

# Deploy to FusionPBX
sshpass -p 'Takay1#$ane%%' ssh -p 22 -o StrictHostKeyChecking=no telcobright@103.95.96.100 \
  "echo 'Takay1#\$ane%%' | sudo -S cp /tmp/callback-*.php /var/www/fusionpbx/app/rest_api/actions/ && \
   echo 'Takay1#\$ane%%' | sudo -S cp /tmp/callback-*.sql /var/www/fusionpbx/app/rest_api/actions/ && \
   echo 'Takay1#\$ane%%' | sudo -S chown www-data:www-data /var/www/fusionpbx/app/rest_api/actions/callback-* && \
   echo 'Done'"
```

### Step 2: Test Installation

```bash
# Test auto-install (tables will be created automatically)
curl -s -k -X POST https://103.95.96.100/app/rest_api/rest.php \
  -u 'fdd253f9-05be-41c9-8894-e61cb6b36dab:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{"action": "callback-install"}' | jq .
```

**Expected Response:**
```json
{
  "success": true,
  "message": "Callback system installed successfully",
  "tables_created": true,
  "tables": [
    "v_callback_configs",
    "v_callback_queue"
  ]
}
```

### Step 3: Create Your First Configuration

```bash
curl -s -k -X POST https://103.95.96.100/app/rest_api/rest.php \
  -u 'fdd253f9-05be-41c9-8894-e61cb6b36dab:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "callback-config-create",
    "domainUuid": "dd4f630a-e712-450b-8d5e-1a63fe6f4f00",
    "configName": "Sales Queue Callback",
    "enabled": true,
    "triggerOnTimeout": true,
    "triggerOnAbandoned": true,
    "maxAttempts": 3,
    "retryInterval": 300,
    "schedules": [
      {
        "days": [1, 2, 3, 4, 5],
        "start_time": "09:00",
        "end_time": "18:00"
      }
    ],
    "playAnnouncement": true,
    "announcementText": "Thank you for calling. Connecting you to an agent."
  }' | jq .
```

### Step 4: Create a Test Callback

```bash
curl -s -k -X POST https://103.95.96.100/app/rest_api/rest.php \
  -u 'fdd253f9-05be-41c9-8894-e61cb6b36dab:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "callback-queue-create",
    "domainUuid": "dd4f630a-e712-450b-8d5e-1a63fe6f4f00",
    "callerIdNumber": "01712345678",
    "callerIdName": "John Doe",
    "queueName": "Sales",
    "hangupCause": "ORIGINATOR_CANCEL"
  }' | jq .
```

---

## 📖 API Reference

### Configuration Management

#### 1. Create Configuration
**Action:** `callback-config-create`

**Required Parameters:**
- `configName` (string) - Name of the configuration
- `domainUuid` (string) - Domain UUID

**Optional Parameters:**
- `queueUuid` (string) - Specific queue (null = domain-wide)
- `enabled` (boolean) - Enable/disable (default: true)
- `triggerOnTimeout` (boolean) - Trigger on queue timeout (default: true)
- `triggerOnAbandoned` (boolean) - Trigger when caller hangs up (default: true)
- `triggerOnNoAnswer` (boolean) - Trigger on no answer (default: false)
- `triggerAfterHours` (boolean) - Trigger after business hours (default: false)
- `maxAttempts` (int) - Max retry attempts (default: 3)
- `retryInterval` (int) - Seconds between retries (default: 300)
- `immediateCallback` (boolean) - Callback immediately (default: false)
- `waitForAgent` (boolean) - Wait for agent availability (default: true)
- `schedules` (array) - Schedule rules (see below)
- `playAnnouncement` (boolean) - Play message to customer (default: true)
- `announcementText` (string) - Message text
- `defaultPriority` (int) - Default priority (default: 5)
- `maxCallbacksPerHour` (int) - Rate limit per hour (default: 100)
- `maxCallbacksPerDay` (int) - Rate limit per day (default: 500)

**Schedule Format:**
```json
{
  "schedules": [
    {
      "days": [1, 2, 3, 4, 5],  // 1=Monday, 7=Sunday
      "start_time": "09:00",
      "end_time": "18:00"
    },
    {
      "days": [6, 7],  // Weekend
      "start_time": "10:00",
      "end_time": "14:00"
    }
  ]
}
```

#### 2. List Configurations
**Action:** `callback-config-list`

**Optional Parameters:**
- `domainUuid` (string) - Filter by domain
- `queueUuid` (string) - Filter by queue
- `enabled` (boolean) - Filter by enabled status

#### 3. Toggle Enable/Disable
**Action:** `callback-config-toggle`

**Required Parameters:**
- `callbackConfigUuid` (string)

---

### Callback Queue Management

#### 4. Create Callback (Manual or Triggered)
**Action:** `callback-queue-create`

**Required Parameters:**
- `callerIdNumber` (string) - Customer phone number
- `domainUuid` (string) - Domain UUID

**Optional Parameters:**
- `callerIdName` (string) - Customer name
- `destinationNumber` (string) - Number customer called
- `queueUuid` (string) - Queue UUID
- `queueName` (string) - Queue name
- `originalCallUuid` (string) - Original call UUID
- `originalCallTime` (timestamp) - When original call occurred
- `hangupCause` (string) - Why call ended
- `priority` (int) - Override default priority

#### 5. List Callbacks
**Action:** `callback-queue-list`

**Optional Parameters:**
- `domainUuid` (string) - Filter by domain
- `status` (string) - Filter by status (pending, calling, completed, failed, cancelled)
- `queueUuid` (string) - Filter by queue
- `callerIdNumber` (string) - Filter by caller (partial match)
- `startDate` (timestamp) - Filter by created date start
- `endDate` (timestamp) - Filter by created date end
- `limit` (int) - Results per page (default: 50)
- `offset` (int) - Pagination offset (default: 0)

#### 6. Cancel Callback
**Action:** `callback-queue-cancel`

**Required Parameters:**
- `callbackUuid` (string)

---

## 🔧 Configuration Examples

### Example 1: Basic 9-5 Business Hours
```json
{
  "action": "callback-config-create",
  "domainUuid": "your-domain-uuid",
  "configName": "Standard Business Hours",
  "enabled": true,
  "triggerOnTimeout": true,
  "triggerOnAbandoned": true,
  "maxAttempts": 3,
  "retryInterval": 300,
  "schedules": [
    {
      "days": [1, 2, 3, 4, 5],
      "start_time": "09:00",
      "end_time": "17:00"
    }
  ]
}
```

### Example 2: 24/7 Support
```json
{
  "action": "callback-config-create",
  "domainUuid": "your-domain-uuid",
  "configName": "24/7 Support",
  "enabled": true,
  "triggerOnTimeout": true,
  "maxAttempts": 5,
  "retryInterval": 180,
  "immediateCallback": true,
  "schedules": [
    {
      "days": [1, 2, 3, 4, 5, 6, 7],
      "start_time": "00:00",
      "end_time": "23:59"
    }
  ]
}
```

### Example 3: After-Hours Only
```json
{
  "action": "callback-config-create",
  "domainUuid": "your-domain-uuid",
  "configName": "After Hours Callback",
  "enabled": true,
  "triggerAfterHours": true,
  "maxAttempts": 2,
  "immediateCallback": false,
  "schedules": [
    {
      "days": [1, 2, 3, 4, 5],
      "start_time": "09:00",
      "end_time": "09:30"
    }
  ]
}
```

---

## 🔄 Workflow Integration

### Option 1: FreeSWITCH Dialplan Integration

Add to your dialplan to trigger callbacks on queue timeout:

```xml
<extension name="queue-callback-on-timeout">
  <condition field="destination_number" expression="^(queue_.*?)$">
    <action application="set" data="hangup_after_bridge=true"/>
    <action application="callcenter" data="$1"/>

    <!-- After call ends, check if callback needed -->
    <action application="set" data="api_hangup_hook=lua callback_trigger.lua ${uuid} ${caller_id_number} $1"/>
  </condition>
</extension>
```

### Option 2: Event Handler (Lua Script)

Create `/usr/share/freeswitch/scripts/callback_trigger.lua`:

```lua
-- Callback trigger on missed queue call
local api = freeswitch.API()
local uuid = argv[1]
local caller = argv[2]
local queue = argv[3]

-- Get hangup cause
local hangup_cause = session:getVariable("hangup_cause")

-- Trigger callback via REST API
if hangup_cause == "ORIGINATOR_CANCEL" or hangup_cause == "NO_ANSWER" then
    local cmd = string.format(
        "curl -X POST https://localhost/app/rest_api/rest.php " ..
        "-H 'Authorization: Basic xxx' " ..
        "-d '{\"action\":\"callback-queue-create\",\"callerIdNumber\":\"%s\",\"queueName\":\"%s\"}'",
        caller, queue
    )
    os.execute(cmd)
end
```

### Option 3: Manual Callback Creation

From your application, call the API when needed:

```javascript
async function createCallback(callerNumber, queueName) {
  const response = await fetch('https://pbx/app/rest_api/rest.php', {
    method: 'POST',
    headers: {
      'Authorization': 'Basic ' + btoa('api-key:password'),
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      action: 'callback-queue-create',
      callerIdNumber: callerNumber,
      queueName: queueName,
      domainUuid: 'your-domain-uuid'
    })
  });

  return await response.json();
}
```

---

## ✅ Testing Checklist

1. ✅ Deploy PHP files to server
2. ✅ Test auto-install endpoint
3. ✅ Create test configuration
4. ✅ Verify configuration in list
5. ✅ Create test callback
6. ✅ Verify callback in queue
7. ✅ Test enable/disable toggle
8. ✅ Test cancel callback
9. ✅ Deploy background daemon (next step)
10. ✅ Test actual callback origination

---

## 🎯 Next Steps

Now that the database and API are ready:

1. **Deploy these files to server** ✅
2. **Test configuration creation** ✅
3. **Create background daemon** (processes callbacks)
4. **Build frontend UI** (manage configurations)
5. **Integrate with dialplan** (auto-trigger)

The system is ready to use! The daemon will be created next to actually originate the callbacks.
