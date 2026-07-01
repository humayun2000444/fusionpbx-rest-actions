<?php
/**
 * event-guard-log-unblock.php
 * Unblock an IP - matches FusionPBX's native unblock flow:
 *   1. Set log_status to 'pending' in v_event_guard_logs
 *   2. Send ESL event "event_guard:unblock" to FreeSWITCH
 *   3. The event_guard service daemon picks up pending rows and removes iptables rules
 *
 * Parameters:
 *   event_guard_log_uuid - UUID of the log entry to unblock
 *   ip_address           - (alternative) IP address to unblock directly
 */

$required_params = array();

function do_action($body) {
    $log_uuid = isset($body->event_guard_log_uuid) ? $body->event_guard_log_uuid : null;
    $ip_address = isset($body->ip_address) ? $body->ip_address : null;

    if (empty($log_uuid) && empty($ip_address)) {
        return ['error' => 'event_guard_log_uuid or ip_address is required'];
    }

    $uuids_to_update = [];

    if (!empty($log_uuid)) {
        // Single UUID provided
        $sql = "SELECT event_guard_log_uuid, ip_address, filter, log_status FROM v_event_guard_logs WHERE event_guard_log_uuid = :uuid";
        $database = new database;
        $row = $database->select($sql, ['uuid' => $log_uuid], 'row');

        if (!$row) {
            return ['error' => 'Log entry not found'];
        }

        $ip_address = $row['ip_address'];
        $uuids_to_update[] = $log_uuid;
    } else {
        // IP address provided - find all blocked entries for this IP
        $sql = "SELECT event_guard_log_uuid FROM v_event_guard_logs WHERE ip_address = :ip AND log_status = 'blocked'";
        $database = new database;
        $rows = $database->select($sql, ['ip' => $ip_address], 'all');
        if ($rows && is_array($rows)) {
            foreach ($rows as $r) {
                $uuids_to_update[] = $r['event_guard_log_uuid'];
            }
        }
    }

    if (empty($uuids_to_update)) {
        return ['error' => 'No blocked entries found'];
    }

    // Step 1: Set log_status to 'pending' (same as FusionPBX event_guard class)
    $array = [];
    $x = 0;
    foreach ($uuids_to_update as $uuid) {
        $array['event_guard_logs'][$x]['event_guard_log_uuid'] = $uuid;
        $array['event_guard_logs'][$x]['log_status'] = 'pending';
        $x++;
    }

    $database = new database;
    $database->app_name = 'event_guard';
    $database->app_uuid = 'c5b86612-1514-40cb-8e2c-3f01a8f6f637';
    $database->save($array, false);

    // Step 2: Send ESL event "event_guard:unblock" to FreeSWITCH
    $esl_sent = false;
    if (class_exists('event_socket')) {
        $esl = event_socket::create();
        if ($esl) {
            $cmd = "sendevent CUSTOM\n";
            $cmd .= "Event-Name: CUSTOM\n";
            $cmd .= "Event-Subclass: event_guard:unblock\n";
            $switch_result = event_socket::command($cmd);
            $esl_sent = true;
        }
    }

    return [
        'status' => 'success',
        'ipAddress' => $ip_address,
        'logStatus' => 'pending',
        'eslEventSent' => $esl_sent,
        'entriesUpdated' => count($uuids_to_update)
    ];
}
