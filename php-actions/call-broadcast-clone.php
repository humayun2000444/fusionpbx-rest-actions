<?php

$required_params = array("callBroadcastUuid");

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domainUuid) ? $body->domainUuid :
                     (isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid);

    $source_uuid = isset($body->callBroadcastUuid) ? $body->callBroadcastUuid :
                  (isset($body->call_broadcast_uuid) ? $body->call_broadcast_uuid : null);

    if (empty($source_uuid)) {
        return array("success" => false, "error" => "callBroadcastUuid is required");
    }

    $database = new database;

    // Verify source exists
    $sql_check = "SELECT broadcast_name FROM v_call_broadcasts
                  WHERE call_broadcast_uuid = :uuid AND domain_uuid = :domain_uuid";
    $source = $database->select($sql_check, array(
        "uuid" => $source_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    if (empty($source)) {
        return array("success" => false, "error" => "Broadcast not found");
    }

    $new_uuid = uuid();
    $new_name = isset($body->newName) ? $body->newName : ($source['broadcast_name'] . ' (Copy)');

    // Clone using INSERT INTO ... SELECT to preserve all column types correctly
    $sql_clone = "INSERT INTO v_call_broadcasts (
        call_broadcast_uuid, domain_uuid, broadcast_name, broadcast_description,
        broadcast_start_time, broadcast_timeout, broadcast_concurrent_limit,
        recording_uuid, broadcast_caller_id_name, broadcast_caller_id_number,
        broadcast_destination_type, broadcast_destination_data,
        broadcast_phone_numbers, broadcast_avmd, broadcast_accountcode, broadcast_toll_allow,
        broadcast_schedule_enabled, broadcast_schedule_type, broadcast_schedule_date,
        broadcast_schedule_time, broadcast_schedule_days, broadcast_schedule_end_date,
        broadcast_retry_enabled, broadcast_retry_max, broadcast_retry_interval, broadcast_retry_causes,
        broadcast_pacing_mode, broadcast_dial_ratio, broadcast_max_abandon_rate,
        broadcast_status, broadcast_current_dial_ratio,
        broadcast_total_answered, broadcast_total_abandoned, broadcast_avg_talk_time,
        insert_date
    )
    SELECT
        :new_uuid::uuid, domain_uuid, :new_name, broadcast_description,
        broadcast_start_time, broadcast_timeout, broadcast_concurrent_limit,
        recording_uuid, broadcast_caller_id_name, broadcast_caller_id_number,
        broadcast_destination_type, broadcast_destination_data,
        broadcast_phone_numbers, broadcast_avmd, broadcast_accountcode, broadcast_toll_allow,
        broadcast_schedule_enabled, broadcast_schedule_type, NULL,
        broadcast_schedule_time, broadcast_schedule_days, NULL,
        broadcast_retry_enabled, broadcast_retry_max, broadcast_retry_interval, broadcast_retry_causes,
        broadcast_pacing_mode, broadcast_dial_ratio, broadcast_max_abandon_rate,
        'idle', broadcast_dial_ratio,
        0, 0, 0,
        NOW()
    FROM v_call_broadcasts
    WHERE call_broadcast_uuid = :source_uuid AND domain_uuid = :domain_uuid";

    $result = $database->execute($sql_clone, array(
        "new_uuid" => $new_uuid,
        "new_name" => $new_name,
        "source_uuid" => $source_uuid,
        "domain_uuid" => $db_domain_uuid
    ));

    if ($result === false) {
        return array("success" => false, "error" => "Failed to clone broadcast record");
    }

    // Clone leads with reset status
    $sql_leads = "INSERT INTO v_call_broadcast_leads (
        call_broadcast_lead_uuid, call_broadcast_uuid, domain_uuid,
        phone_number, lead_status, attempts, max_attempts, insert_date
    )
    SELECT
        gen_random_uuid(), :new_uuid::uuid, domain_uuid,
        phone_number, 'pending', 0, max_attempts, NOW()
    FROM v_call_broadcast_leads
    WHERE call_broadcast_uuid = :source_uuid AND domain_uuid = :domain_uuid";

    $database->execute($sql_leads, array(
        "new_uuid" => $new_uuid,
        "source_uuid" => $source_uuid,
        "domain_uuid" => $db_domain_uuid
    ));

    // Count cloned leads
    $sql_count = "SELECT COUNT(*) as cnt FROM v_call_broadcast_leads
                  WHERE call_broadcast_uuid = :uuid AND domain_uuid = :domain_uuid";
    $count_result = $database->select($sql_count, array(
        "uuid" => $new_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");
    $lead_count = $count_result ? intval($count_result['cnt']) : 0;

    return array(
        "success" => true,
        "callBroadcastUuid" => $new_uuid,
        "name" => $new_name,
        "leadCount" => $lead_count,
        "clonedFrom" => $source_uuid,
        "message" => "Campaign cloned successfully with $lead_count leads"
    );
}
