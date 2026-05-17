# Full Project Context - FusionPBX Hosted PBX Platform

## What This Project Is

A **multi-tenant hosted PBX platform** built on top of FusionPBX/FreeSWITCH. We are building a custom React GUI and REST API layer to replace/extend FusionPBX's native web interface, giving customers a modern self-service portal for managing their phone system.

---

## System Architecture

```
                    Internet
                       |
            +----------+----------+
            |                     |
     SIP Phones/Gateways    Web Browser
            |                     |
      +-----+-----+        +-----+-----+
      | FreeSWITCH |        |   React   |
      | (PBX Core) |        | Frontend  |
      +-----+------+        +-----+-----+
            |                      |
      +-----+------+        +-----+-----+
      | PostgreSQL  |        | Java RTC  |
      | (FusionPBX  |<-------| Manager   |
      |  Database)  |        | (Proxy)   |
      +-----+------+        +-----+-----+
            |                      |
      +-----+------+        +-----+-----+
      | FusionPBX   |<------| PHP REST  |
      | (Web Admin) |        |   API     |
      +-------------+        +-----------+
```

### Three Repositories

| Repo | Location | Branch | Purpose |
|------|----------|--------|---------|
| **btcl-hosted-pbx** | `/home/prototype/Documents/AllProjects/btcl-hosted-pbx/` | `contact_center` | React frontend (customer portal) |
| **RTC-Manager** | `/home/prototype/Documents/AllProjects/RTC-Manager/FreeSwitchREST/` | `master` | Java Spring Boot proxy API |
| **fusionpbx** | `/home/prototype/humayun/fusionpbx/` | `master` | PHP REST actions + custom scripts |

### Two Servers

| Server | IP | Role |
|--------|----|------|
| **Primary (100)** | 103.95.96.100 | Development/testing - deploy PHP here |
| **Secondary (82)** | 114.130.145.82 | Production - skip unless asked |

---

## How the 3-Layer API Works

### Request Flow

```
React Frontend  --->  Java RTC Manager  --->  PHP REST API  --->  FreeSWITCH/PostgreSQL
     (GUI)           (Auth + Proxy)         (Business Logic)        (PBX Engine)
```

1. **React** sends POST to `/FREESWITCHREST/api/call-center/v1/queues/list`
2. **Java** adds PHP API auth headers, forwards with `{"action": "call-center-queue-list", ...}`
3. **PHP** queries PostgreSQL and/or FreeSWITCH ESL, returns JSON
4. Response flows back through Java to React

### Why 3 Layers?

- **PHP** runs inside FusionPBX with access to its database classes, ESL helpers, and session management
- **Java** provides a clean REST API for the frontend, handles JWT auth, and can serve multiple PHP backends
- **React** provides the modern UI that customers interact with

---

## Features Built So Far

### Call Center (mod_callcenter)
- **Queue CRUD** - Create, edit, delete call center queues with full settings (MOH, recording, announcements, greeting, strategy, tier rules, abandoned call handling)
- **Agent CRUD** - Create, edit, delete agents with all timing settings (call timeout, wrap up, reject delay, busy delay, no answer delay)
- **Tier Management** - Assign/remove agents to/from queues with level and position
- **Live Monitoring** - Real-time queue status showing waiting calls, agent states, active calls
- **Eavesdrop** - Listen/Whisper/Barge on active call center calls (resolves loopback UUIDs)
- **Agent Status** - Change agent status (Available, On Break, Logged Out)

### Call Broadcast
- **Broadcast CRUD** - Create, schedule, start, stop call broadcasts
- **Lead Upload** - Upload phone number lists for broadcast campaigns
- **Queue Integration** - Broadcast calls route to call center queues
- **CID Passthrough** - Customer's number shows on agent phone (not agent's extension)
- **Gateway CID Fix** - Prevents post-bridge CID flip from showing agent extension to PSTN gateway

### Other Features
- **Extensions** - List, create, update SIP extensions
- **Destinations/Inbound Routes** - Manage DID routing
- **Recordings** - Upload, list, download, delete audio recordings
- **IVR** - Interactive Voice Response menu management
- **Registrations** - View SIP registration status
- **Smart IVR** - AI-powered IVR with Bengali voice support

---

## Key Technical Details

### FreeSWITCH ESL (Event Socket Layer)

PHP actions talk to FreeSWITCH via ESL for real-time operations:

```php
// ALWAYS use class-based (not function-based)
$esl = event_socket::create();
$result = event_socket::api("callcenter_config queue list");
$result = event_socket::api("originate {vars}user/1011@domain &eavesdrop(uuid)");
$result = event_socket::api("show channels");
```

### Call Center Loopback Channels

Call center `session_uuid` is a **loopback channel** - `uuid_exists` returns false for it. To find the real SIP channel for eavesdrop:
1. Parse `show channels` output
2. Find the loopback UUID row
3. Read `application_data` column (contains the real bridged SIP UUID)

This is handled in `call-center-eavesdrop.php` > `resolve_eavesdrop_uuid()`.

### Agent Contact String

FusionPBX stores agent contact as a complex string:
```
{call_timeout=20,domain_name=103.95.96.100,domain_uuid=xxx,extension_uuid=xxx,ignore_display_updates=true,sip_h_caller_destination=${caller_destination}}user/1011@103.95.96.100
```

- The `{vars}` prefix sets channel variables when mod_callcenter calls the agent
- `ignore_display_updates=true` prevents post-bridge CID flip
- The PHP backend builds this from the extension; frontend only sends `user/EXT@DOMAIN`

### Call Broadcast CID Flow

```
Broadcast originates: loopback/CUSTOMER_NUM/domain 8989 XML domain
  -> loopback-a: calls customer via gateway (origination_caller_id=COMPANY_CLI)
  -> loopback-b: enters callcenter queue (caller_id=CUSTOMER_NUM)
  -> Agent answers: mod_callcenter passes customer's CID to agent phone
  -> Gateway keeps COMPANY_CLI (ignore_display_updates + sip_cid_type=none prevent flip)
```

### Recording Paths

FusionPBX stores recordings at:
```
/var/lib/freeswitch/recordings/<domain_name>/filename.wav
```
Example: `/var/lib/freeswitch/recordings/103.95.96.100/greeting.wav`

The React GUI loads uploaded recordings from the recordings API and builds the full path for queue MOH, greeting, and announce sound dropdowns.

---

## Database Schema (Key Tables)

### v_call_center_queues
```
call_center_queue_uuid, domain_uuid, queue_name, queue_extension, queue_strategy,
queue_moh_sound, queue_record_template, queue_time_base_score, queue_max_wait_time,
queue_max_wait_time_with_no_agent, queue_tier_rules_apply, queue_tier_rule_wait_second,
queue_tier_rule_no_agent_no_wait, queue_timeout_action, queue_cid_prefix,
queue_cc_exit_keys, queue_announce_position, queue_announce_sound,
queue_announce_frequency, queue_greeting, queue_discard_abandoned_after,
queue_abandoned_resume_allowed, queue_description
```

### v_call_center_agents
```
call_center_agent_uuid, domain_uuid, user_uuid, agent_name, agent_type,
agent_call_timeout, agent_id, agent_contact, agent_status, agent_max_no_answer,
agent_wrap_up_time, agent_reject_delay_time, agent_busy_delay_time,
agent_no_answer_delay_time, agent_record
```

### v_call_center_tiers
```
call_center_tier_uuid, call_center_queue_uuid, call_center_agent_uuid,
tier_level, tier_position
```

---

## All API Endpoints

### Call Center
| Method | Endpoint | PHP Action | Description |
|--------|----------|-----------|-------------|
| POST | `/v1/queues/list` | call-center-queue-list | List queues |
| POST | `/v1/queues/create` | call-center-queue-create | Create queue + dialplan |
| POST | `/v1/queues/update` | call-center-queue-update | Update queue + ESL reload |
| POST | `/v1/queues/delete` | call-center-queue-delete | Delete queue + dialplan |
| POST | `/v1/agents/list` | call-center-agent-list | List agents with queue assignments |
| POST | `/v1/agents/create` | call-center-agent-create | Create agent + ESL load |
| POST | `/v1/agents/update` | call-center-agent-update | Update agent + ESL reload |
| POST | `/v1/agents/delete` | call-center-agent-delete | Delete agent |
| POST | `/v1/agents/status` | call-center-agent-status | Change agent status (Available/Break/etc) |
| POST | `/v1/tiers` | call-center-tier-list | List all tier assignments |
| POST | `/v1/tiers/add` | call-center-tier-add | Assign agent to queue |
| POST | `/v1/tiers/remove` | call-center-tier-remove | Remove agent from queue |
| POST | `/v1/live` | call-center-live | Real-time queue data via ESL |
| POST | `/v1/eavesdrop` | call-center-eavesdrop | Listen/Whisper/Barge on call |

### Call Broadcast
| Method | Endpoint | PHP Action | Description |
|--------|----------|-----------|-------------|
| POST | `/v1/list` | call-broadcast-list | List broadcasts |
| POST | `/v1/create` | call-broadcast-create | Create broadcast |
| POST | `/v1/update` | call-broadcast-update | Update broadcast |
| POST | `/v1/delete` | call-broadcast-delete | Delete broadcast |
| POST | `/v1/start` | call-broadcast-start | Start broadcast (schedule calls) |
| POST | `/v1/stop` | call-broadcast-stop | Stop broadcast |
| POST | `/v1/details` | call-broadcast-details | Get broadcast details |
| POST | `/v1/upload-leads` | call-broadcast-upload-leads | Upload phone numbers |

### Other
| Method | Endpoint | PHP Action | Description |
|--------|----------|-----------|-------------|
| POST | `/recordings/list-by-domain` | recording-list | List uploaded recordings |
| POST | `/recordings/create` | recording-create | Upload new recording |
| POST | `/extensions/list-by-domain` | extension-list | List SIP extensions |
| POST | `/registration-list` | registration-list | List SIP registrations |
| POST | `/destination-list` | destination-list | List inbound routes |

---

## How to Make Changes

### Adding a new feature (end-to-end)

**Step 1: PHP Action** (business logic)
```
Create: /home/prototype/humayun/fusionpbx/php-actions/my-feature.php
Deploy: scp to 103.95.96.100:/var/www/fusionpbx/app/rest_api/actions/
```

**Step 2: Java Proxy** (endpoint routing)
```
Edit: CallCenterController.java - add @PostMapping endpoint
Edit: FusionPbxCallCenterService.java - add service method
Build: mvn clean package on build server
```

**Step 3: React Frontend** (UI)
```
Edit: src/config/index.js - add endpoint URL
Edit: src/pages/MyPage.jsx - add UI + API calls
Build: npx vite build
Push: git push origin contact_center
```

### Modifying existing queue/agent fields

1. Add column to PHP list query (e.g., `call-center-queue-list.php`)
2. Handle in PHP create/update (e.g., `call-center-queue-create.php`)
3. Add to React form state, defaults, edit mapping, submit body, and JSX
4. Deploy PHP, rebuild React

---

## Recent Changes (This Session)

### CID Passthrough Fix
- **Problem:** Agent phone showed `1011` (own extension) instead of customer number on broadcast calls; gateway also flipped to `1011`
- **Root cause:** Post-bridge CID masquerade in FreeSWITCH + empty `${caller_id_number}` vars in agent contact overriding mod_callcenter
- **Fix:** Removed CID vars from agent contact (let mod_callcenter handle it), added `ignore_display_updates=true` to agent contact, added `ignore_display_updates=true` + `sip_cid_type=none` to broadcast channel vars

### Eavesdrop (Listen/Whisper/Barge)
- **PHP:** `call-center-eavesdrop.php` with loopback UUID resolution via `show channels`
- **Java:** Proxy endpoint at `/v1/eavesdrop`
- **React:** Modal in QueueMonitor.jsx with extension input + mode buttons

### Call Center GUI Enhancement
- Added 14 missing queue fields (MOH, greeting, recording, announcements, tier rules, etc.)
- Sound fields use recording dropdowns (loaded from recordings API)
- Added reject/busy delay to agent form
- Fixed agent contact dropdown matching on edit
- Fixed queue list API to return all columns
