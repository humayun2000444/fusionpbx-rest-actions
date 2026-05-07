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

    // Get the source broadcast
    $sql = "SELECT * FROM v_call_broadcasts
            WHERE call_broadcast_uuid = :uuid AND domain_uuid = :domain_uuid";
    $source = $database->select($sql, array(
        "uuid" => $source_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    if (empty($source)) {
        return array("success" => false, "error" => "Broadcast not found");
    }

    // Generate new UUID
    $new_uuid = uuid();

    // Clone name
    $new_name = isset($body->newName) ? $body->newName : ($source['broadcast_name'] . ' (Copy)');

    // Insert cloned broadcast with idle status and reset stats
    $sql_insert = "INSERT INTO v_call_broadcasts (
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
    ) VALUES (
        :new_uuid, :domain_uuid, :name, :description,
        :start_time, :timeout, :concurrent_limit,
        :recording_uuid, :caller_id_name, :caller_id_number,
        :dest_type, :dest_data,
        :phone_numbers, :avmd, :accountcode, :toll_allow,
        :schedule_enabled, :schedule_type, :schedule_date,
        :schedule_time, :schedule_days, :schedule_end_date,
        :retry_enabled, :retry_max, :retry_interval, :retry_causes,
        :pacing_mode, :dial_ratio, :max_abandon_rate,
        'idle', :dial_ratio,
        0, 0, 0,
        NOW()
    )";

    $params = array(
        "new_uuid" => $new_uuid,
        "domain_uuid" => $db_domain_uuid,
        "name" => $new_name,
        "description" => $source['broadcast_description'],
        "start_time" => $source['broadcast_start_time'],
        "timeout" => $source['broadcast_timeout'],
        "concurrent_limit" => $source['broadcast_concurrent_limit'],
        "recording_uuid" => $source['recording_uuid'],
        "caller_id_name" => $source['broadcast_caller_id_name'],
        "caller_id_number" => $source['broadcast_caller_id_number'],
        "dest_type" => $source['broadcast_destination_type'],
        "dest_data" => $source['broadcast_destination_data'],
        "phone_numbers" => $source['broadcast_phone_numbers'],
        "avmd" => $source['broadcast_avmd'],
        "accountcode" => $source['broadcast_accountcode'],
        "toll_allow" => $source['broadcast_toll_allow'],
        "schedule_enabled" => $source['broadcast_schedule_enabled'],
        "schedule_type" => $source['broadcast_schedule_type'],
        "schedule_date" => $source['broadcast_schedule_date'],
        "schedule_time" => $source['broadcast_schedule_time'],
        "schedule_days" => $source['broadcast_schedule_days'],
        "schedule_end_date" => $source['broadcast_schedule_end_date'],
        "retry_enabled" => $source['broadcast_retry_enabled'],
        "retry_max" => $source['broadcast_retry_max'],
        "retry_interval" => $source['broadcast_retry_interval'],
        "retry_causes" => $source['broadcast_retry_causes'],
        "pacing_mode" => $source['broadcast_pacing_mode'],
        "dial_ratio" => $source['broadcast_dial_ratio'],
        "max_abandon_rate" => $source['broadcast_max_abandon_rate'],
    );

    $result = $database->execute($sql_insert, $params);
    if ($result === false) {
        return array("success" => false, "error" => "Failed to clone broadcast");
    }

    // Clone leads with reset status
    $sql_leads = "SELECT phone_number, max_attempts
                  FROM v_call_broadcast_leads
                  WHERE call_broadcast_uuid = :source_uuid AND domain_uuid = :domain_uuid";
    $leads = $database->select($sql_leads, array(
        "source_uuid" => $source_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "all");

    $lead_count = 0;
    if (is_array($leads)) {
        foreach ($leads as $lead) {
            $lead_uuid = uuid();
            $sql_lead = "INSERT INTO v_call_broadcast_leads (
                call_broadcast_lead_uuid, call_broadcast_uuid, domain_uuid,
                phone_number, lead_status, attempts, max_attempts, insert_date
            ) VALUES (
                :lead_uuid, :broadcast_uuid, :domain_uuid,
                :phone, 'pending', 0, :max_attempts, NOW()
            )";
            $database->execute($sql_lead, array(
                "lead_uuid" => $lead_uuid,
                "broadcast_uuid" => $new_uuid,
                "domain_uuid" => $db_domain_uuid,
                "phone" => $lead['phone_number'],
                "max_attempts" => $lead['max_attempts']
            ));
            $lead_count++;
        }
    }

    return array(
        "success" => true,
        "callBroadcastUuid" => $new_uuid,
        "name" => $new_name,
        "leadCount" => $lead_count,
        "clonedFrom" => $source_uuid,
        "message" => "Campaign cloned successfully with $lead_count leads"
    );
}
