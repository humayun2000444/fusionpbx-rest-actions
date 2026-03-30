<?php

$required_params = array("callBroadcastUuid");

function do_action($body) {
    global $domain_uuid;

    // Use domain_uuid from request if provided, otherwise use global
    $db_domain_uuid = isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid;

    $call_broadcast_uuid = isset($body->callBroadcastUuid) ? $body->callBroadcastUuid :
                          (isset($body->call_broadcast_uuid) ? $body->call_broadcast_uuid : null);

    if (empty($call_broadcast_uuid)) {
        return array(
            "success" => false,
            "error" => "callBroadcastUuid is required"
        );
    }

    $database = new database;

    // Check if broadcast exists and get name
    $sql = "SELECT broadcast_name FROM v_call_broadcasts
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

    // Stop any scheduled calls first (ignore errors if event_socket not available)
    if (class_exists('event_socket')) {
        $fp = @event_socket::create();
        if ($fp) {
            $cmd = "sched_del " . $call_broadcast_uuid;
            @event_socket::api($cmd);
        }
    }

    // Delete the broadcast
    $sql = "DELETE FROM v_call_broadcasts
            WHERE call_broadcast_uuid = :call_broadcast_uuid
            AND domain_uuid = :domain_uuid";

    try {
        $database->execute($sql, array(
            "call_broadcast_uuid" => $call_broadcast_uuid,
            "domain_uuid" => $db_domain_uuid
        ));
    } catch (Exception $e) {
        return array(
            "success" => false,
            "error" => "Failed to delete broadcast: " . $e->getMessage()
        );
    }

    // Verify deletion
    $verify_sql = "SELECT call_broadcast_uuid FROM v_call_broadcasts WHERE call_broadcast_uuid = :call_broadcast_uuid";
    $verify_result = $database->select($verify_sql, array("call_broadcast_uuid" => $call_broadcast_uuid), "row");
    if (!empty($verify_result)) {
        return array(
            "success" => false,
            "error" => "Broadcast deletion failed - record still exists"
        );
    }

    return array(
        "success" => true,
        "message" => "Broadcast '" . $broadcast['broadcast_name'] . "' deleted successfully",
        "callBroadcastUuid" => $call_broadcast_uuid
    );
}
