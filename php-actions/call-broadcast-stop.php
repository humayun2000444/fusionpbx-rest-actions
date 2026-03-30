<?php

$required_params = array("callBroadcastUuid");

function do_action($body) {
    global $domain_uuid;

    // Use domain_uuid from request if provided, otherwise use global
    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid :
                     (isset($body->domainUuid) ? $body->domainUuid : $domain_uuid);

    $call_broadcast_uuid = isset($body->callBroadcastUuid) ? $body->callBroadcastUuid :
                          (isset($body->call_broadcast_uuid) ? $body->call_broadcast_uuid : null);

    if (empty($call_broadcast_uuid)) {
        return array(
            "success" => false,
            "error" => "callBroadcastUuid is required"
        );
    }

    $database = new database;

    // Check if broadcast exists
    $sql = "SELECT broadcast_name, broadcast_status FROM v_call_broadcasts
            WHERE call_broadcast_uuid = :call_broadcast_uuid
            AND domain_uuid = :domain_uuid";

    $broadcast = $database->select($sql, array(
        "call_broadcast_uuid" => $call_broadcast_uuid,
        "domain_uuid" => $db_domain_uuid
    ), "row");

    if (empty($broadcast)) {
        return array(
            "success" => false,
            "error" => "Broadcast not found"
        );
    }

    // Update status to 'stopped'
    $update_sql = "UPDATE v_call_broadcasts SET broadcast_status = 'stopped', update_date = NOW()
                   WHERE call_broadcast_uuid = :call_broadcast_uuid AND domain_uuid = :domain_uuid";
    try {
        $database->execute($update_sql, array(
            "call_broadcast_uuid" => $call_broadcast_uuid,
            "domain_uuid" => $db_domain_uuid
        ));
    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Failed to update broadcast status: " . $e->getMessage()
        );
    }

    // Verify status was updated
    $verify_sql = "SELECT broadcast_status FROM v_call_broadcasts WHERE call_broadcast_uuid = :call_broadcast_uuid";
    $verify_result = $database->select($verify_sql, array("call_broadcast_uuid" => $call_broadcast_uuid), "row");
    if (empty($verify_result) || $verify_result['broadcast_status'] !== 'stopped') {
        return array(
            "success" => false,
            "error" => "Failed to update broadcast status"
        );
    }

    // Try to cancel scheduled calls via event socket
    $esl_result = "Event socket not available";
    if (class_exists('event_socket')) {
        $fp = @event_socket::create();
        if ($fp) {
            $cmd = "sched_del " . $call_broadcast_uuid;
            $esl_result = @event_socket::api($cmd);
            $esl_result = trim($esl_result);
        }
    }

    return array(
        "success" => true,
        "message" => "Broadcast stopped successfully",
        "callBroadcastUuid" => $call_broadcast_uuid,
        "broadcastName" => $broadcast['broadcast_name'],
        "previousStatus" => $broadcast['broadcast_status'],
        "status" => "stopped",
        "eslResult" => $esl_result
    );
}
