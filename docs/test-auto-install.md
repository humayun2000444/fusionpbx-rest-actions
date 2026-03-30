# Test Callback System Auto-Install

## Method 1: Via UI (Easiest)

1. **Open Frontend:**
   ```
   https://hcc.btcliptelephony.gov.bd/callback-config
   ```

2. **What Happens:**
   - Page loads
   - Calls API to list configs
   - API checks if tables exist
   - Creates tables automatically if missing
   - Shows empty list (or existing configs)

3. **Success Signs:**
   - No errors
   - Page shows "No callback configurations found" or existing configs
   - Stats show: 0 Total Configs, 0 Active, 0 Disabled, 0 Avg Retries

## Method 2: Via Direct API Call

```bash
# Call any callback endpoint
curl -s -k -X POST https://103.95.96.100/app/rest_api/rest.php \
  -u 'fdd253f9-05be-41c9-8894-e61cb6b36dab:anypassword' \
  -H 'Content-Type: application/json' \
  -d '{
    "action": "callback-config-list",
    "domainUuid": "dd4f630a-e712-450b-8d5e-1a63fe6f4f00"
  }' | jq .
```

**Expected Response (first time):**
```json
{
  "success": true,
  "configs": []
}
```

## Method 3: Check Database Directly

```bash
# SSH to server
ssh telcobright@103.95.96.100

# Check if tables exist
sudo -u postgres psql -d fusionpbx -c "
SELECT table_name
FROM information_schema.tables
WHERE table_name LIKE 'v_callback%'
ORDER BY table_name;
"
```

**Expected Output:**
```
     table_name
---------------------
 v_callback_configs
 v_callback_queue
(2 rows)
```

## Method 4: Check PHP Error Logs

```bash
# SSH to server
ssh telcobright@103.95.96.100

# Check logs for auto-install messages
sudo tail -f /var/log/php-fpm/error.log

# Look for this line:
# "Callback tables created automatically"
```

## What If Tables Already Exist?

If you manually created tables or they exist from previous setup:

1. **AUTO-INSTALL SKIPS**: Function detects tables exist
2. **NO ERRORS**: Uses existing tables
3. **WORKS NORMALLY**: All operations proceed as expected

The `CREATE TABLE IF NOT EXISTS` ensures no conflicts!

## Troubleshooting

### Problem: "Failed to create tables"

**Check:**
1. PHP has write permissions to `/var/www/fusionpbx/app/rest_api/actions/`
2. callback-install.sql file exists in same directory
3. Database user has CREATE TABLE permissions

**Fix:**
```bash
# Ensure files are in place
ls -la /var/www/fusionpbx/app/rest_api/actions/callback-*

# Ensure correct ownership
sudo chown www-data:www-data /var/www/fusionpbx/app/rest_api/actions/callback-*

# Verify PostgreSQL permissions
sudo -u postgres psql -d fusionpbx -c "SHOW is_superuser;"
```

### Problem: UI shows white screen

**Check browser console:**
- Should NOT see "table does not exist" errors
- Should see API calls returning data

**If you see database errors:**
```bash
# Manually create tables once
sudo -u postgres psql -d fusionpbx -f /var/www/fusionpbx/app/rest_api/actions/callback-install.sql
```

## Summary

✅ **Auto-install is built-in** - no manual setup needed
✅ **Safe to call multiple times** - CREATE IF NOT EXISTS
✅ **Triggered on first use** - any callback API call
✅ **Transparent to users** - happens automatically in background

Just access the UI or call any callback API endpoint - tables will be created automatically!
