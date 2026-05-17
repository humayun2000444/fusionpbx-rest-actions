-- Smart IVR Dialplan Database Insert
-- This script inserts Smart IVR dialplan entries into FusionPBX database
-- for both samsung and hcc_samsung domains

-- ============================================
-- Samsung Domain Smart IVR Dialplan
-- ============================================

-- Insert main dialplan entry for samsung domain
INSERT INTO v_dialplans (
  domain_uuid,
  dialplan_uuid,
  dialplan_context,
  dialplan_name,
  dialplan_number,
  dialplan_continue,
  dialplan_order,
  dialplan_enabled,
  dialplan_description,
  insert_date
) VALUES (
  '27c6bf36-93ff-4137-8896-92337da0dff1',  -- samsung domain_uuid
  'a1b2c3d4-1111-2222-3333-111111111111',  -- new dialplan_uuid
  'samsung.btcliptelephony.gov.bd',
  'smart_ivr_inbound',
  '^(SMART_IVR|9999)$',
  'false',
  35,  -- Run after call-block (40) but before extensions
  'true',
  'Smart IVR - Student Information System Hotline',
  NOW()
);

-- Insert condition: destination_number regex match
INSERT INTO v_dialplan_details (
  domain_uuid,
  dialplan_uuid,
  dialplan_detail_uuid,
  dialplan_detail_tag,
  dialplan_detail_type,
  dialplan_detail_data,
  dialplan_detail_group,
  dialplan_detail_order,
  dialplan_detail_enabled
) VALUES (
  '27c6bf36-93ff-4137-8896-92337da0dff1',
  'a1b2c3d4-1111-2222-3333-111111111111',
  'a1b2c3d4-c001-0000-0000-000000000001',
  'condition',
  'destination_number',
  '^(SMART_IVR|9999)$',
  0,
  10,
  true
);

-- Insert action: set hangup_after_bridge=true
INSERT INTO v_dialplan_details (
  domain_uuid,
  dialplan_uuid,
  dialplan_detail_uuid,
  dialplan_detail_tag,
  dialplan_detail_type,
  dialplan_detail_data,
  dialplan_detail_group,
  dialplan_detail_order,
  dialplan_detail_enabled
) VALUES (
  '27c6bf36-93ff-4137-8896-92337da0dff1',
  'a1b2c3d4-1111-2222-3333-111111111111',
  'a1b2c3d4-a001-0000-0000-000000000001',
  'action',
  'set',
  'hangup_after_bridge=true',
  0,
  20,
  true
);

-- Insert action: answer
INSERT INTO v_dialplan_details (
  domain_uuid,
  dialplan_uuid,
  dialplan_detail_uuid,
  dialplan_detail_tag,
  dialplan_detail_type,
  dialplan_detail_data,
  dialplan_detail_group,
  dialplan_detail_order,
  dialplan_detail_enabled
) VALUES (
  '27c6bf36-93ff-4137-8896-92337da0dff1',
  'a1b2c3d4-1111-2222-3333-111111111111',
  'a1b2c3d4-a002-0000-0000-000000000001',
  'action',
  'answer',
  '',
  0,
  30,
  true
);

-- Insert action: sleep 1000ms
INSERT INTO v_dialplan_details (
  domain_uuid,
  dialplan_uuid,
  dialplan_detail_uuid,
  dialplan_detail_tag,
  dialplan_detail_type,
  dialplan_detail_data,
  dialplan_detail_group,
  dialplan_detail_order,
  dialplan_detail_enabled
) VALUES (
  '27c6bf36-93ff-4137-8896-92337da0dff1',
  'a1b2c3d4-1111-2222-3333-111111111111',
  'a1b2c3d4-a003-0000-0000-000000000001',
  'action',
  'sleep',
  '1000',
  0,
  40,
  true
);

-- Insert action: lua smart_ivr_inbound.lua
INSERT INTO v_dialplan_details (
  domain_uuid,
  dialplan_uuid,
  dialplan_detail_uuid,
  dialplan_detail_tag,
  dialplan_detail_type,
  dialplan_detail_data,
  dialplan_detail_group,
  dialplan_detail_order,
  dialplan_detail_enabled
) VALUES (
  '27c6bf36-93ff-4137-8896-92337da0dff1',
  'a1b2c3d4-1111-2222-3333-111111111111',
  'a1b2c3d4-a004-0000-0000-000000000001',
  'action',
  'lua',
  'smart_ivr_inbound.lua',
  0,
  50,
  true
);

-- ============================================
-- HCC Samsung Domain Smart IVR Dialplan
-- ============================================

-- Insert main dialplan entry for hcc_samsung domain
INSERT INTO v_dialplans (
  domain_uuid,
  dialplan_uuid,
  dialplan_context,
  dialplan_name,
  dialplan_number,
  dialplan_continue,
  dialplan_order,
  dialplan_enabled,
  dialplan_description,
  insert_date
) VALUES (
  '989b5198-cee3-4b8a-a448-f3a217fcb3bc',  -- hcc_samsung domain_uuid
  'a1b2c3d4-2222-3333-4444-222222222222',  -- new dialplan_uuid
  'hcc_samsung.btcliptelephony.gov.bd',
  'smart_ivr_inbound',
  '^(SMART_IVR|9999)$',
  'false',
  35,
  'true',
  'Smart IVR - Student Information System Hotline',
  NOW()
);

-- Insert condition for hcc_samsung
INSERT INTO v_dialplan_details (
  domain_uuid,
  dialplan_uuid,
  dialplan_detail_uuid,
  dialplan_detail_tag,
  dialplan_detail_type,
  dialplan_detail_data,
  dialplan_detail_group,
  dialplan_detail_order,
  dialplan_detail_enabled
) VALUES (
  '989b5198-cee3-4b8a-a448-f3a217fcb3bc',
  'a1b2c3d4-2222-3333-4444-222222222222',
  'a1b2c3d4-c002-0000-0000-000000000002',
  'condition',
  'destination_number',
  '^(SMART_IVR|9999)$',
  0,
  10,
  true
);

-- Insert actions for hcc_samsung (same as samsung domain)
INSERT INTO v_dialplan_details (
  domain_uuid,
  dialplan_uuid,
  dialplan_detail_uuid,
  dialplan_detail_tag,
  dialplan_detail_type,
  dialplan_detail_data,
  dialplan_detail_group,
  dialplan_detail_order,
  dialplan_detail_enabled
) VALUES (
  '989b5198-cee3-4b8a-a448-f3a217fcb3bc',
  'a1b2c3d4-2222-3333-4444-222222222222',
  'a1b2c3d4-a101-0000-0000-000000000002',
  'action',
  'set',
  'hangup_after_bridge=true',
  0,
  20,
  true
),
(
  '989b5198-cee3-4b8a-a448-f3a217fcb3bc',
  'a1b2c3d4-2222-3333-4444-222222222222',
  'a1b2c3d4-a102-0000-0000-000000000002',
  'action',
  'answer',
  '',
  0,
  30,
  true
),
(
  '989b5198-cee3-4b8a-a448-f3a217fcb3bc',
  'a1b2c3d4-2222-3333-4444-222222222222',
  'a1b2c3d4-a103-0000-0000-000000000002',
  'action',
  'sleep',
  '1000',
  0,
  40,
  true
),
(
  '989b5198-cee3-4b8a-a448-f3a217fcb3bc',
  'a1b2c3d4-2222-3333-4444-222222222222',
  'a1b2c3d4-a104-0000-0000-000000000002',
  'action',
  'lua',
  'smart_ivr_inbound.lua',
  0,
  50,
  true
);
