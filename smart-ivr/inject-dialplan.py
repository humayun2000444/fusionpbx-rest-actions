#!/usr/bin/env python3
"""Inject Smart IVR dialplan into FusionPBX cached dialplan files"""

import os
import shutil

SMART_IVR_XML = '''<extension name="smart_ivr_inbound" continue="false" uuid="a1b2c3d4-1111-2222-3333-111111111111">
	<condition field="destination_number" expression="^(SMART_IVR|9999)$">
		<action application="set" data="hangup_after_bridge=true"/>
		<action application="answer"/>
		<action application="sleep" data="1000"/>
		<action application="lua" data="smart_ivr_inbound.lua"/>
	</condition>
</extension>
'''

def inject_dialplan(filepath):
    """Inject Smart IVR extension into dialplan cache file"""

    if not os.path.exists(filepath):
        print(f"File {filepath} does not exist, skipping...")
        return False

    # Read the file
    with open(filepath, 'r') as f:
        content = f.read()

    # Check if Smart IVR already exists
    if 'smart_ivr_inbound' in content:
        print(f"Smart IVR already exists in {filepath}, skipping...")
        return True

    # Find the user_record extension line
    marker = '<extension name="user_record"'

    if marker not in content:
        print(f"Could not find user_record extension in {filepath}, skipping...")
        return False

    # Create backup
    shutil.copy(filepath, filepath + '.backup')

    # Insert Smart IVR before user_record
    content = content.replace(marker, SMART_IVR_XML + '\n' + marker)

    # Write back
    with open(filepath, 'w') as f:
        f.write(content)

    print(f"Successfully injected Smart IVR into {filepath}")
    return True

# Main execution
dialplan_files = [
    '/var/cache/fusionpbx/dialplan.hcc_samsung.btcliptelephony.gov.bd',
    '/var/cache/fusionpbx/dialplan.samsung.btcliptelephony.gov.bd'
]

print("Injecting Smart IVR dialplan...")
success_count = 0

for filepath in dialplan_files:
    if inject_dialplan(filepath):
        success_count += 1

print(f"\nInjected Smart IVR into {success_count}/{len(dialplan_files)} files")

if success_count > 0:
    print("Reloading FreeSWITCH XML...")
    os.system('fs_cli -x "reloadxml"')
    print("Done!")
