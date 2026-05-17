# FusionPBX REST API & RTC Manager Proxy - Developer Guide

## Architecture Overview

```
React Frontend  -->  Java Spring Boot (RTC Manager)  -->  FusionPBX PHP REST API  -->  FreeSWITCH + PostgreSQL
(btcl-hosted-pbx)    (FreeSwitchREST)                     (rest_api/actions/)
```

The system has 3 layers:

1. **React Frontend** - User-facing GUI
2. **Java RTC Manager** - Proxy/middleware that forwards requests to the PHP API
3. **PHP REST API** - Runs inside FusionPBX, talks to FreeSWITCH ESL and PostgreSQL directly

---

## Layer 1: PHP REST API (FusionPBX Server)

### Location
- **Server:** 103.95.96.100 (production)
- **Path:** `/var/www/fusionpbx/app/rest_api/actions/`
- **Local dev:** `/home/prototype/humayun/fusionpbx/php-actions/`
- **Git repo:** fusionpbx (rest_api branch on server)

### How It Works

The entry point is `rest.php` which:
1. Receives a POST request with `{"action": "some-action-name", ...params}`
2. Loads `/var/www/fusionpbx/app/rest_api/actions/<action-name>.php`
3. Calls the `do_action($body)` function inside that file
4. Returns the result as JSON

### Creating a New PHP Action

Create a file like `my-new-action.php`:

```php
<?php
// Optional: list required params (rest.php validates these before calling do_action)
$required_params = array('param1', 'param2');

function do_action($body) {
    global $domain_uuid; // authenticated user's domain UUID

    // Access request params
    $param1 = isset($body->param1) ? $body->param1 : null;

    // Database access (FusionPBX database class)
    $database = new database;
    $sql = "SELECT * FROM v_some_table WHERE domain_uuid = :domain_uuid";
    $result = $database->select($sql, array('domain_uuid' => $domain_uuid), 'all');

    // FreeSWITCH Event Socket (ESL) - class-based
    $esl = event_socket::create();
    $response = event_socket::api("show channels");

    // Return array (auto-converted to JSON)
    return array('success' => true, 'data' => $result);
}
```

### Authentication

PHP REST API uses HTTP Basic Auth:
- **Username:** API Key UUID (e.g., `0c1ece42-31ce-4174-99e2-37e709fe348b`)
- **Password:** anything (not validated)

### Testing PHP API Directly

```bash
curl -s -k -X POST https://103.95.96.100/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:x' \
  -H 'Content-Type: application/json' \
  -d '{"action": "call-center-queue-list", "domain_uuid": "688189fa-f122-4731-a12d-01fe7ce3bff9"}'
```

### Deploying PHP Changes

```bash
# Copy from local dev to server
sshpass -p 'Takay1#$ane%%' scp -P 22 -o StrictHostKeyChecking=no \
  /home/prototype/humayun/fusionpbx/php-actions/my-action.php \
  telcobright@103.95.96.100:/tmp/

# Deploy to FusionPBX
sshpass -p 'Takay1#$ane%%' ssh -p 22 -o StrictHostKeyChecking=no telcobright@103.95.96.100 \
  "echo 'Takay1#\$ane%%' | sudo -S cp /tmp/my-action.php /var/www/fusionpbx/app/rest_api/actions/ && \
   echo 'Takay1#\$ane%%' | sudo -S chown www-data:www-data /var/www/fusionpbx/app/rest_api/actions/my-action.php"
```

### Server Git (on 103.95.96.100)

```bash
cd /var/www/fusionpbx/app/rest_api
git status
git add actions/my-action.php
git commit -m "description"
git push
```

---

## Layer 2: Java RTC Manager (Spring Boot Proxy)

### Location
- **Local dev:** `/home/prototype/Documents/AllProjects/RTC-Manager/FreeSwitchREST/`
- **Build server:** `telcobright@103.95.96.100:/home/telcobright/Documents/Development/RTC-Manager/`
- **Key files:**
  - `src/main/java/freeswitch/controller/CallCenterController.java` - REST endpoints
  - `src/main/java/freeswitch/service/FusionPbxCallCenterService.java` - Service layer

### How the Proxy Works

The Java app is a **pass-through proxy**. It:
1. Receives HTTP request from the React frontend
2. Adds the PHP API auth headers
3. Forwards the request body to the PHP REST API with `{"action": "xxx", ...params}`
4. Returns the PHP response back to the frontend

### Example: Adding a New Endpoint

**1. Service method** (`FusionPbxCallCenterService.java`):

```java
@SuppressWarnings("unchecked")
public Map<String, Object> myNewAction(String param1, String param2) {
    try {
        Map<String, Object> request = new HashMap<>();
        request.put("action", "my-new-action");  // matches PHP filename
        request.put("param1", param1);
        request.put("param2", param2);

        HttpEntity<Map<String, Object>> entity = new HttpEntity<>(request, createHeaders());
        ResponseEntity<String> response = restTemplate.exchange(
            fusionPbxRestUrl, HttpMethod.POST, entity, String.class);

        if (response.getStatusCode().is2xxSuccessful() && response.getBody() != null) {
            return objectMapper.readValue(response.getBody(), Map.class);
        }
        return errorResult("Failed: " + response.getStatusCode());
    } catch (Exception e) {
        return errorResult("Error: " + e.getMessage());
    }
}
```

**2. Controller endpoint** (`CallCenterController.java`):

```java
@PostMapping("/v1/my-new-action")
public ResponseEntity<Map<String, Object>> myNewAction(@RequestBody Map<String, Object> request) {
    Map<String, Object> response = new HashMap<>();
    try {
        String param1 = (String) request.get("param1");
        String param2 = (String) request.get("param2");

        Map<String, Object> result = callCenterService.myNewAction(param1, param2);

        if (result.containsKey("error")) {
            response.put("errorCode", "500 INTERNAL_SERVER_ERROR");
            response.put("message", result.get("error"));
            return ResponseEntity.status(HttpStatus.INTERNAL_SERVER_ERROR).body(response);
        }
        response.put("status", "success");
        response.put("data", result);
        return ResponseEntity.ok(response);
    } catch (Exception e) {
        response.put("errorCode", "500 INTERNAL_SERVER_ERROR");
        response.put("message", "Error: " + e.getMessage());
        return ResponseEntity.status(HttpStatus.INTERNAL_SERVER_ERROR).body(response);
    }
}
```

### Building & Deploying Java App

```bash
# On build server (103.95.96.100)
cd /home/telcobright/Documents/Development/RTC-Manager
git pull
mvn clean package -DskipTests
# Restart the service
```

---

## Layer 3: React Frontend

### Location
- **Local dev:** `/home/prototype/Documents/AllProjects/btcl-hosted-pbx/`
- **Branch:** `contact_center`
- **Key files:**
  - `src/config/index.js` - API endpoint definitions
  - `src/pages/CallCenter.jsx` - Call center management (queues, agents, tiers)
  - `src/pages/QueueMonitor.jsx` - Live queue monitoring with eavesdrop

### Adding a New API Call

**1. Add endpoint to config** (`src/config/index.js`):

```js
callCenter: {
  myNewAction: '/FREESWITCHREST/api/call-center/v1/my-new-action',
}
```

**2. Call from React component:**

```js
const response = await fetch(
  `${config.api.baseUrl}${config.api.endpoints.callCenter.myNewAction}`,
  {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify({ param1: 'value1', param2: 'value2' }),
  }
);
const data = await response.json();
```

---

## Full Flow Example: Creating a Queue

```
1. User fills queue form in React (CallCenter.jsx)
2. React POSTs to: /FREESWITCHREST/api/call-center/v1/queues/create
   Body: { domainUuid, queueName, queueExtension, queueStrategy, ... }

3. Java CallCenterController receives it
4. Java CallCenterService forwards to PHP:
   POST https://103.95.96.100/app/rest_api/rest.php
   Auth: Basic 0c1ece42-...:x
   Body: { "action": "call-center-queue-create", "queueName": "Sales", ... }

5. PHP call-center-queue-create.php:
   - Inserts into v_call_center_queues (PostgreSQL)
   - Creates dialplan entry in v_dialplans
   - Runs ESL: callcenter_config queue load <queue>
   - Returns { success: true, callCenterQueueUuid: "..." }

6. Response flows back: PHP -> Java -> React
```

---

## Existing Call Center API Endpoints

| Endpoint | PHP Action | Description |
|----------|-----------|-------------|
| `POST /v1/queues/list` | call-center-queue-list | List all queues |
| `POST /v1/queues/create` | call-center-queue-create | Create queue |
| `POST /v1/queues/update` | call-center-queue-update | Update queue |
| `POST /v1/queues/delete` | call-center-queue-delete | Delete queue |
| `POST /v1/agents/list` | call-center-agent-list | List all agents |
| `POST /v1/agents/create` | call-center-agent-create | Create agent |
| `POST /v1/agents/update` | call-center-agent-update | Update agent |
| `POST /v1/agents/delete` | call-center-agent-delete | Delete agent |
| `POST /v1/agents/status` | call-center-agent-status | Change agent status |
| `POST /v1/tiers` | call-center-tier-list | List tiers |
| `POST /v1/tiers/add` | call-center-tier-add | Add agent to queue |
| `POST /v1/tiers/remove` | call-center-tier-remove | Remove agent from queue |
| `POST /v1/live` | call-center-live | Live queue data (ESL) |
| `POST /v1/eavesdrop` | call-center-eavesdrop | Listen/Whisper/Barge |

---

## Branch (Inter-Domain Calling) API Endpoints

| Endpoint | PHP Action | Description |
|----------|-----------|-------------|
| `POST /v1/branch/group/list` | branch-group-list | List all branch groups |
| `POST /v1/branch/group/create` | branch-group-create | Create a branch group |
| `POST /v1/branch/group/update` | branch-group-update | Update a branch group |
| `POST /v1/branch/group/delete` | branch-group-delete | Delete a branch group |
| `POST /v1/branch/member/add` | branch-member-add | Add a domain as branch member |
| `POST /v1/branch/member/remove` | branch-member-remove | Remove a branch member |
| `POST /v1/branch/domain/list` | branch-domain-list | List available domains |
| `POST /v1/branch/dialplan-generate` | branch-dialplan-generate | Generate inter-branch dialplans |

### How Branch Calling Works

1. A **branch group** contains multiple domains (tenants) that can call each other
2. Each member domain gets a unique **prefix** (e.g., `11`, `12`, `20`)
3. When dialplans are generated, each domain gets a dialplan to reach every other member
4. Users dial **prefix + extension** to call another branch (e.g., `121001` calls ext 1001 on the domain with prefix `12`)

### Example: Branch Member Add

```bash
curl -s -k -X POST https://103.95.96.100/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:x' \
  -H 'Content-Type: application/json' \
  -d '{"action": "branch-member-add", "branchGroupUuid": "uuid", "domainName": "tb.com", "branchPrefix": "11", "branchLabel": "Dhaka Office"}'
```

### Example: Generate Dialplans

```bash
curl -s -k -X POST https://103.95.96.100/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:x' \
  -H 'Content-Type: application/json' \
  -d '{"action": "branch-dialplan-generate", "domainUuid": "688189fa-...", "branchGroupUuid": "9e6b4f96-..."}'
```

### Branch Database Tables

| Table | Purpose |
|-------|---------|
| `v_branch_groups` | Branch group definitions |
| `v_branch_members` | Domain memberships with prefix/label |

---

## Key Database Tables

| Table | Purpose |
|-------|---------|
| `v_call_center_queues` | Queue configuration |
| `v_call_center_agents` | Agent configuration |
| `v_call_center_tiers` | Agent-to-queue assignments |
| `v_dialplans` / `v_dialplan_details` | Dialplan routing |
| `v_domains` | Domain/tenant info |
| `v_extensions` | SIP extensions |
| `v_branch_groups` | Branch group definitions |
| `v_branch_members` | Domain-to-branch assignments with prefix |

---

## Important Notes

- **ESL (Event Socket):** Use class-based `event_socket::create()` and `event_socket::api()` in PHP. Do NOT use function-based `event_socket_create()`.
- **Agent Contact String:** The PHP backend auto-builds the full contact string (e.g., `{call_timeout=20,...}user/1011@domain`) from the extension. The frontend only sends `user/EXT@DOMAIN`.
- **Domain UUID:** Every API call needs `domainUuid` to scope data to the correct tenant.
- **Deploy only to 103.95.96.100** for PHP actions (not 114.130.145.82).
- **Recording paths:** Use format `/var/lib/freeswitch/recordings/<domain_name>/filename.wav`
