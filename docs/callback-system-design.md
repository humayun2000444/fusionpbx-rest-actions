# FusionPBX Callback System - Design Documentation

## Overview
A generic, configurable automatic callback system that calls customers back when they miss a call or can't reach an agent.

## Key Features

### 1. **Flexible Configuration**
- Per-domain or per-queue settings
- Enable/disable globally or per configuration
- Multiple independent configurations for different scenarios

### 2. **Schedule Management**
- Define multiple time windows when callbacks are active
- Support for:
  - Business hours (e.g., Mon-Fri 9 AM - 6 PM)
  - After hours
  - Weekends
  - Holidays
  - Special events
- Day-specific schedules
- Date-range based schedules

### 3. **Trigger Conditions**
Configure which events trigger callbacks:
- Queue timeout (caller waited too long)
- Abandoned calls (caller hung up while waiting)
- No answer
- All agents busy
- After business hours
- Minimum wait time requirement

### 4. **Retry Logic**
- Configurable max attempts (default: 3)
- Retry interval (default: 5 minutes)
- Exponential backoff support (5min → 7.5min → 11.25min)
- Smart scheduling (skip non-business hours)

### 5. **Priority System**
- Default priority for all callbacks
- VIP customer priority
- Manual priority override
- FIFO within same priority level

### 6. **Rate Limiting**
- Max callbacks per hour
- Max callbacks per day
- Prevents system overload

### 7. **Number Filtering**
- Blacklist (never callback these numbers)
- Whitelist (only callback these numbers)
- Pattern matching support (wildcards)

### 8. **Customer Experience**
- Optional announcement when customer answers
- Customizable message
- Pre-recorded audio file support
- Immediate or wait-for-agent callback

## Workflows

### Workflow 1: Queue Timeout Callback

```
1. Customer calls queue
2. Waits in queue for 60 seconds (timeout)
3. Call disconnects (timeout)
4. Event captured by callback system
5. Check if callback enabled for this queue
6. Check if current time in allowed schedule
7. Check rate limits not exceeded
8. Create callback record (status = 'pending')
9. Background daemon processes queue
10. When schedule allows + agent available:
    - Originate call to customer
    - When customer answers, play announcement
    - Bridge to queue or agent
    - Mark callback as 'completed'
```

### Workflow 2: After-Hours Callback

```
1. Customer calls after 6 PM (outside business hours)
2. IVR plays "We're closed" message
3. Callback record created (status = 'scheduled')
4. Scheduled for next business day at 9 AM
5. At 9 AM:
    - Background daemon finds scheduled callback
    - Checks if agents available
    - Originates call to customer
    - Routes to queue
```

### Workflow 3: Retry on No Answer

```
1. First callback attempt - customer doesn't answer
2. Update: attempts = 1, next_attempt_time = now + 5 minutes
3. After 5 minutes:
    - Second attempt - customer doesn't answer
    - Update: attempts = 2, next_attempt_time = now + 7.5 minutes
4. After 7.5 minutes:
    - Third attempt - customer answers!
    - Bridge to agent
    - Mark as completed
```

## Configuration Examples

### Example 1: Basic Business Hours Callback

```json
{
  "config_name": "Sales Queue Callback",
  "enabled": true,
  "queue_uuid": "abc-123",

  "triggers": {
    "on_timeout": true,
    "on_abandoned": true,
    "min_wait_time": 30
  },

  "retry": {
    "max_attempts": 3,
    "retry_interval": 300,
    "exponential_backoff": true
  },

  "schedules": [
    {
      "name": "Business Hours",
      "days": [1, 2, 3, 4, 5],
      "start_time": "09:00",
      "end_time": "18:00"
    }
  ],

  "customer_experience": {
    "play_announcement": true,
    "message": "Thank you for calling. Connecting you to sales."
  }
}
```

### Example 2: 24/7 Support with Priority

```json
{
  "config_name": "VIP Support",
  "enabled": true,
  "queue_uuid": "support-123",

  "triggers": {
    "on_timeout": true,
    "on_abandoned": true,
    "on_no_answer": true
  },

  "retry": {
    "max_attempts": 5,
    "retry_interval": 180
  },

  "schedules": [
    {
      "name": "24/7 Coverage",
      "days": [0, 1, 2, 3, 4, 5, 6],
      "start_time": "00:00",
      "end_time": "23:59"
    }
  ],

  "priority": {
    "default": 10,
    "vip": 20
  },

  "limits": {
    "max_per_hour": 200,
    "max_per_day": 2000
  }
}
```

### Example 3: After-Hours Only

```json
{
  "config_name": "After Hours Callback",
  "enabled": true,

  "triggers": {
    "after_hours_only": true
  },

  "retry": {
    "max_attempts": 2,
    "retry_interval": 3600
  },

  "schedules": [
    {
      "name": "Next Business Day",
      "type": "next_business_hours",
      "days": [1, 2, 3, 4, 5],
      "start_time": "09:00",
      "end_time": "09:30"
    }
  ],

  "immediate_callback": false,
  "wait_for_agent": true
}
```

## API Endpoints

### Configuration Management
- `POST /callback-config/create` - Create new configuration
- `POST /callback-config/list` - List configurations
- `POST /callback-config/get` - Get configuration details
- `POST /callback-config/update` - Update configuration
- `POST /callback-config/delete` - Delete configuration
- `POST /callback-config/toggle` - Enable/disable configuration

### Schedule Management
- `POST /callback-schedule/create` - Add schedule to configuration
- `POST /callback-schedule/list` - List schedules
- `POST /callback-schedule/update` - Update schedule
- `POST /callback-schedule/delete` - Delete schedule

### Callback Queue Management
- `POST /callback/create` - Manually create callback
- `POST /callback/list` - List callbacks (with filters)
- `POST /callback/get` - Get callback details
- `POST /callback/cancel` - Cancel pending callback
- `POST /callback/retry` - Manually retry failed callback
- `POST /callback/history` - Get callback attempt history

### Statistics & Reports
- `POST /callback/stats` - Get callback statistics
- `POST /callback/report` - Generate callback report

### Processing (Daemon)
- `POST /callback/process` - Process pending callbacks (internal)
- `POST /callback/check-schedule` - Check if current time in schedule

## Frontend UI Components

### 1. Configuration Page
- List of all callback configurations
- Quick enable/disable toggle
- Status indicators
- Create/Edit configuration modal

### 2. Schedule Builder
- Visual time picker
- Day selector (Mon-Sun)
- Date range picker (for holidays/events)
- Multiple schedule support
- Schedule preview calendar

### 3. Callback Queue Dashboard
- Real-time list of pending callbacks
- Filter by status, queue, priority
- Caller information
- Next attempt time
- Manual actions (cancel, retry, prioritize)

### 4. Statistics Dashboard
- Success rate charts
- Callbacks by hour/day
- Average attempts
- Top callers
- Queue performance

### 5. Settings
- Global enable/disable
- Rate limits
- Blacklist/whitelist management
- Default configuration

## Background Daemon (Node.js or PHP)

### Responsibilities
1. Poll callback queue every 30 seconds
2. Find callbacks where `next_attempt_time <= NOW()`
3. Check if current time in allowed schedule
4. Check rate limits
5. Check agent availability (if required)
6. Originate call via FreeSWITCH ESL
7. Handle call events (answer, hangup)
8. Update callback status and attempts
9. Schedule next retry if needed

### Example Daemon Logic (Pseudocode)

```javascript
async function processCallbacks() {
  // Get pending callbacks
  const callbacks = await db.query(`
    SELECT * FROM v_callback_queue
    WHERE status = 'pending'
    AND next_attempt_time <= NOW()
    AND attempts < max_attempts
    ORDER BY priority DESC, next_attempt_time ASC
    LIMIT 10
  `);

  for (const callback of callbacks) {
    // Check schedule
    if (!await isInSchedule(callback.callback_config_uuid)) {
      continue;
    }

    // Check rate limits
    if (!await checkRateLimit(callback.domain_uuid)) {
      continue;
    }

    // Check agent availability if required
    if (callback.wait_for_agent) {
      const available = await checkAgentAvailable(callback.queue_uuid);
      if (!available) continue;
    }

    // Make the callback
    try {
      await makeCallback(callback);
    } catch (error) {
      await handleCallbackError(callback, error);
    }
  }
}

// Run every 30 seconds
setInterval(processCallbacks, 30000);
```

## Integration with Existing System

### 1. Event Handling
Capture FreeSWITCH events to trigger callbacks:
```xml
<extension name="callback-on-timeout">
  <condition field="hangup_cause" expression="^ORIGINATOR_CANCEL|LOSE_RACE$">
    <action application="set" data="callback_trigger=queue_timeout"/>
    <action application="curl" data="https://api/callback/create ..."/>
  </condition>
</extension>
```

### 2. Call Origination
Use FreeSWITCH originate command:
```
originate {
  ignore_early_media=true,
  origination_caller_id_name=Support,
  origination_caller_id_number=1234567890
}sofia/gateway/trunk/${customer_number}
&bridge(loopback/queue-${queue_name}/default)
```

### 3. Queue Integration
- Read queue agent status for availability check
- Route callbacks to same queue as original call
- Preserve queue priority and routing logic

## Deployment Steps

1. **Database Setup**
   - Run schema creation SQL
   - Create initial configurations
   - Set up indexes

2. **PHP Actions**
   - Deploy callback API endpoints
   - Configure authentication

3. **Background Daemon**
   - Deploy Node.js/PHP daemon
   - Configure as systemd service
   - Set up logging and monitoring

4. **FreeSWITCH Integration**
   - Add event handlers
   - Configure origination templates
   - Test call flow

5. **Frontend UI**
   - Deploy React components
   - Configure API endpoints
   - User training

## Future Enhancements

1. **SMS Notifications**
   - Send SMS when callback scheduled
   - Send SMS on callback failure

2. **CRM Integration**
   - Link callbacks to customer records
   - Auto-update CRM with callback results

3. **AI Scheduling**
   - Learn best times to callback based on answer rates
   - Predict customer availability

4. **Multi-channel**
   - WhatsApp callback
   - Email callback scheduling

5. **Agent Assignment**
   - Callback to last agent who helped customer
   - Skill-based callback routing
