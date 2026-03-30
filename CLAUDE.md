# FusionPBX Project Configuration

## SSH Access to FusionPBX Server

- **Host:** 114.130.145.82
- **Port:** 22
- **Username:** telcobright
- **Password:** Takay1#$ane%%

## REST API Location on Server

- **Base Path:** `/var/www/fusionpbx/app/rest_api/`
- **Actions Directory:** `/var/www/fusionpbx/app/rest_api/actions/`

### Key Action Files:

**Destinations:**
- `destination-create.php` - Create new destinations/inbound routes
- `destination-update.php` - Update existing destinations
- `destination-delete.php` - Delete destinations
- `destination-list.php` - List destinations
- `destination-details.php` - Get destination details

**Registrations:**
- `registration-list.php` - List all SIP registrations
- `registration-count.php` - Get registration count by profile
- `registration-unregister.php` - Unregister a device

## REST API Authentication

- **API Key UUID:** `0c1ece42-31ce-4174-99e2-37e709fe348b`
- **API Key Name:** `RTC`
- **Auth:** HTTP Basic Auth with key_uuid as username

### Example API Call:
```bash
curl -s -k -X POST https://114.130.145.82/app/rest_api/rest.php \
  -u '0c1ece42-31ce-4174-99e2-37e709fe348b:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{"action": "registration-list"}'
```

## Deployment Command

```bash
# Copy files to server
sshpass -p 'Takay1#$ane%%' scp -P 22 -o StrictHostKeyChecking=no /home/prototype/humayun/fusionpbx/php-actions/*.php telcobright@114.130.145.82:/tmp/

# Deploy to FusionPBX
sshpass -p 'Takay1#$ane%%' ssh -p 22 -o StrictHostKeyChecking=no telcobright@114.130.145.82 "echo 'Takay1#\$ane%%' | sudo -S cp /tmp/*.php /var/www/fusionpbx/app/rest_api/actions/ && echo 'Takay1#\$ane%%' | sudo -S chown www-data:www-data /var/www/fusionpbx/app/rest_api/actions/*.php"
```

## Local Development Path

- **Source Files:** `/home/prototype/humayun/fusionpbx/php-actions/`
