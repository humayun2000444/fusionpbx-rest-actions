# Call Permissions & Call Blocking Documentation

## Overview

This document covers two features for controlling outbound calls in FusionPBX:

1. **Call Permissions** - Control which types of calls (local, domestic, international) each extension can make
2. **Call Blocking** - Block specific phone numbers from being called or received

---

## 1. Call Permissions

### How It Works

Each extension has a `toll_allow` field that defines what types of calls it can make:

| Permission | Description | Example Numbers |
|------------|-------------|-----------------|
| `local` | Internal/local calls | Extension-to-extension, short codes |
| `domestic` | Nationwide calls within Bangladesh | +880..., 880..., 0... |
| `international` | Calls to foreign countries | +1..., +44..., 00... |

### Permission Values

| toll_allow Value | Can Make |
|------------------|----------|
| `""` (empty) | Only internal extension calls |
| `"local"` | Internal + local calls |
| `"local,domestic"` | Internal + local + domestic |
| `"local,domestic,international"` | All call types (full access) |
| `"local,international"` | Internal + local + international (NO domestic) |

### API Endpoints

#### List Permissions for All Extensions

```bash
POST /FREESWITCHREST/api/v1/call-permissions/list-by-domain

Request:
{
  "domain_uuid": "688189fa-f122-4731-a12d-01fe7ce3bff9"
}

Response:
{
  "success": true,
  "total": 5,
  "extensions": [
    {
      "extension_uuid": "b5168b48-29e8-4324-a529-5e9c1e54dfab",
      "extension": "1000",
      "toll_allow": "local,domestic,international",
      "permissions": {
        "local": true,
        "domestic": true,
        "international": true,
        "emergency": false
      }
    }
  ]
}
```

#### Update Extension Permissions

```bash
POST /FREESWITCHREST/api/v1/call-permissions/update

Request:
{
  "domain_uuid": "688189fa-f122-4731-a12d-01fe7ce3bff9",
  "extensionUuid": "b5168b48-29e8-4324-a529-5e9c1e54dfab",
  "local": true,
  "domestic": false,
  "international": true
}

Response:
{
  "success": true,
  "message": "Call permissions updated successfully",
  "extensionUuid": "b5168b48-29e8-4324-a529-5e9c1e54dfab",
  "tollAllow": "local,international",
  "permissions": {
    "local": true,
    "domestic": false,
    "international": true,
    "emergency": false
  }
}
```

### FusionPBX Outbound Route Configuration

For call permissions to work, outbound routes MUST have `toll_allow` conditions.

#### Example Route Configuration (Domestic)

```xml
<extension name="domestic_outbound">
  <condition field="${user_exists}" expression="false"/>
  <condition field="destination_number" expression="^\+?(\d{11})$"/>
  <condition field="${toll_allow}" expression="domestic">
    <action application="bridge" data="sofia/gateway/your-gateway/$1"/>
  </condition>
</extension>
```

#### Required Conditions by Call Type

| Call Type | Pattern Example | toll_allow Condition |
|-----------|-----------------|----------------------|
| Domestic (Bangladesh) | `^\+?880\d{10}$` or `^0\d{10}$` | `domestic` |
| International | `^\+(?!880)\d{10,15}$` | `international` |
| Local/Short Codes | `^\d{3,5}$` | `local` |

### UI Features

**Call Permissions Page** (`/call-permissions`):
- View all extensions with their current permissions
- Toggle individual permissions (Local, Domestic, International)
- "Enable All" button - Enable all permissions for all extensions
- "Disable All" button - Disable all permissions for all extensions
- Stats showing how many extensions have each permission type

**Edit Extension Modal** (`/extensions`):
- Toggle switches for Local, Domestic, International permissions
- Shows current toll_allow value

---

## 2. Call Blocking

### How It Works

Call blocking allows you to block specific phone numbers:
- **Domain-wide**: Block a number for all extensions in the domain
- **Extension-specific**: Block a number for a specific extension only
- **Direction**: Block inbound calls, outbound calls, or both

### API Endpoints

#### List Call Blocks

```bash
POST /FREESWITCHREST/api/v1/call-block/list-by-domain

Request:
{
  "domain_uuid": "688189fa-f122-4731-a12d-01fe7ce3bff9"
}

Response:
{
  "success": true,
  "call_blocks": [
    {
      "call_block_uuid": "abc123...",
      "call_block_name": "Block Spam",
      "call_block_number": "01712345678",
      "call_block_action": "reject",
      "call_block_direction": "inbound",
      "extension_uuid": null,
      "enabled": "true"
    }
  ]
}
```

#### Create Call Block

```bash
POST /FREESWITCHREST/api/v1/call-block/create

Request:
{
  "domain_uuid": "688189fa-f122-4731-a12d-01fe7ce3bff9",
  "name": "Block Spam Caller",
  "number": "01712345678",
  "action": "reject",
  "direction": "inbound",
  "extensionUuid": null,  // null = domain-wide, or specific extension UUID
  "enabled": true
}

Response:
{
  "success": true,
  "message": "Call block created successfully",
  "call_block_uuid": "abc123..."
}
```

#### Update Call Block

```bash
POST /FREESWITCHREST/api/v1/call-block/update

Request:
{
  "call_block_uuid": "abc123...",
  "name": "Updated Name",
  "number": "01712345678",
  "action": "reject",
  "direction": "both",
  "enabled": true
}
```

#### Delete Call Block

```bash
POST /FREESWITCHREST/api/v1/call-block/delete

Request:
{
  "call_block_uuid": "abc123..."
}
```

#### Toggle Call Block

```bash
POST /FREESWITCHREST/api/v1/call-block/toggle

Request:
{
  "call_block_uuid": "abc123..."
}
```

### Block Actions

| Action | Description |
|--------|-------------|
| `reject` | Reject the call with busy signal |
| `busy` | Return busy tone |
| `hangup` | Hang up immediately |

### Block Directions

| Direction | Description |
|-----------|-------------|
| `inbound` | Block incoming calls from this number |
| `outbound` | Block outgoing calls to this number |
| `both` | Block both inbound and outbound |

### UI Features

**Call Blocking Page** (`/call-block`):
- View all blocked numbers
- Add new block rules
- Edit existing blocks
- Delete blocks
- Toggle enable/disable
- Filter by extension (domain-wide or specific)
- Stats showing total blocks, inbound, outbound counts

---

## Database Tables

### v_extensions (toll_allow field)

```sql
-- View extension permissions
SELECT extension, toll_allow FROM v_extensions WHERE domain_uuid = 'your-domain-uuid';

-- Update extension permissions
UPDATE v_extensions
SET toll_allow = 'local,domestic,international'
WHERE extension_uuid = 'extension-uuid';
```

### v_call_block

```sql
CREATE TABLE v_call_block (
  call_block_uuid UUID PRIMARY KEY,
  domain_uuid UUID,
  extension_uuid UUID,  -- NULL for domain-wide
  call_block_name VARCHAR(255),
  call_block_number VARCHAR(64),
  call_block_action VARCHAR(32),  -- reject, busy, hangup
  call_block_direction VARCHAR(32),  -- inbound, outbound, both
  enabled VARCHAR(8),  -- true/false
  insert_date TIMESTAMP,
  update_date TIMESTAMP
);
```

---

## Troubleshooting

### Permissions Not Working?

1. **Check outbound routes have toll_allow conditions:**
```sql
SELECT d.dialplan_name, dd.dialplan_detail_type, dd.dialplan_detail_data
FROM v_dialplans d
JOIN v_dialplan_details dd ON d.dialplan_uuid = dd.dialplan_uuid
WHERE dd.dialplan_detail_type = '${toll_allow}';
```

2. **Verify extension toll_allow is set:**
```sql
SELECT extension, toll_allow FROM v_extensions WHERE extension = '1000';
```

3. **Reload FreeSWITCH XML after changes:**
```bash
fs_cli -x "reloadxml"
```

### Call Blocks Not Working?

1. **Verify block is enabled:**
```sql
SELECT * FROM v_call_block WHERE enabled = 'true';
```

2. **Check dialplan order** - Call block dialplan should run before outbound routes

---

## File Locations

### PHP API Actions (FusionPBX Server)
- `/var/www/fusionpbx/app/rest_api/actions/call-permissions-list.php`
- `/var/www/fusionpbx/app/rest_api/actions/call-permissions-update.php`
- `/var/www/fusionpbx/app/rest_api/actions/call-block-list.php`
- `/var/www/fusionpbx/app/rest_api/actions/call-block-create.php`
- `/var/www/fusionpbx/app/rest_api/actions/call-block-update.php`
- `/var/www/fusionpbx/app/rest_api/actions/call-block-delete.php`

### Java Proxy API (RTC-Manager)
- `FreeSwitchREST/src/main/java/freeswitch/controller/CallPermissionsController.java`
- `FreeSwitchREST/src/main/java/freeswitch/controller/CallBlockController.java`
- `FreeSwitchREST/src/main/java/freeswitch/service/FusionPbxCallPermissionsService.java`
- `FreeSwitchREST/src/main/java/freeswitch/service/FusionPbxCallBlockService.java`

### Frontend UI (btcl-hosted-pbx)
- `src/pages/CallPermissions.jsx`
- `src/pages/CallBlock.jsx`
- `src/pages/Extensions.jsx` (Edit modal with permissions)

---

## Quick Reference

### Enable All Permissions for an Extension
```bash
curl -X POST /api/v1/call-permissions/update \
  -d '{"extensionUuid": "xxx", "local": true, "domestic": true, "international": true}'
```

### Restrict Extension to Local Only
```bash
curl -X POST /api/v1/call-permissions/update \
  -d '{"extensionUuid": "xxx", "local": true, "domestic": false, "international": false}'
```

### Block a Number Domain-wide
```bash
curl -X POST /api/v1/call-block/create \
  -d '{"number": "01712345678", "direction": "both", "action": "reject"}'
```
