#!/bin/bash
# FusionPBX Create User Script
# Usage: ./create-user.sh <username> <password> <email> [group]
# Groups: superadmin, admin, user, agent

API_URL="https://hippbx.btcliptelephony.gov.bd/app/rest_api/rest.php"
API_KEY="0c1ece42-31ce-4174-99e2-37e709fe348b"
API_SECRET="KwmCmUnRePdJurcRh4sx"
DOMAIN_UUID="feabe5b2-b142-498c-a6f6-685fa478533f"

# Group UUIDs
declare -A GROUPS
GROUPS[superadmin]="825aa4b1-16ac-44fa-87e2-c4b470de523d"
GROUPS[admin]="ac668c66-d9a6-4ac6-b31f-985521e9a37b"
GROUPS[user]="727fec46-7ea4-47d4-835e-164843a5e257"
GROUPS[agent]="fa278d90-c876-4919-9cf5-b3796bc555ba"

if [ -z "$1" ] || [ -z "$2" ] || [ -z "$3" ]; then
    echo "Usage: $0 <username> <password> <email> [group]"
    echo "Groups: superadmin, admin, user (default), agent"
    exit 1
fi

USERNAME="$1"
PASSWORD="$2"
EMAIL="$3"
GROUP="${4:-user}"

GROUP_UUID="${GROUPS[$GROUP]}"
if [ -z "$GROUP_UUID" ]; then
    echo "Invalid group: $GROUP"
    echo "Available groups: superadmin, admin, user, agent"
    exit 1
fi

echo "Creating user: $USERNAME with group: $GROUP"

curl -sk --user "$API_KEY:$API_SECRET" -X POST \
    -d "{\"action\": \"user-create\", \"domain_uuid\": \"$DOMAIN_UUID\", \"username\": \"$USERNAME\", \"password\": \"$PASSWORD\", \"user_email\": \"$EMAIL\", \"group_uuid\": \"$GROUP_UUID\"}" \
    "$API_URL"

echo ""
