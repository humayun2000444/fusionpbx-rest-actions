<?php

$required_params = array("bossExtension", "secretaryExtension");

// Unique app_uuid for boss-secretary dialplans
define('BOSS_SEC_APP_UUID', 'b0555ec4-e7a4-4000-b055-000000000001');

function do_action($body) {
    global $domain_uuid;

    $db_domain_uuid = isset($body->domainUuid) ? $body->domainUuid : (isset($body->domain_uuid) ? $body->domain_uuid : $domain_uuid);

    $boss_ext = isset($body->bossExtension) ? $body->bossExtension : $body->boss_extension;
    $secretary_ext = isset($body->secretaryExtension) ? $body->secretaryExtension : $body->secretary_extension;
    $boss_name = isset($body->bossName) ? $body->bossName : (isset($body->boss_name) ? $body->boss_name : '');
    $secretary_name = isset($body->secretaryName) ? $body->secretaryName : (isset($body->secretary_name) ? $body->secretary_name : '');
    $mode = isset($body->mode) ? $body->mode : 'filter_all';
    $vip_list = isset($body->vipList) ? $body->vipList : (isset($body->vip_list) ? $body->vip_list : '');
    $ring_timeout = isset($body->ringTimeout) ? intval($body->ringTimeout) : 20;
    $cid_prefix = isset($body->cidPrefix) ? $body->cidPrefix : (isset($body->cid_prefix) ? $body->cid_prefix : 'Boss: ');
    $enabled = isset($body->enabled) ? $body->enabled : 'true';

    $database = new database;

    // Get domain name
    $domain_result = $database->select("SELECT domain_name FROM v_domains WHERE domain_uuid = :uuid",
        array("uuid" => $db_domain_uuid), "row");
    if (!$domain_result) return array("error" => "Domain not found");
    $domain_name = $domain_result['domain_name'];

    // Check duplicate
    $existing = $database->select(
        "SELECT boss_secretary_uuid FROM v_boss_secretary WHERE domain_uuid = :domain AND boss_extension = :ext",
        array("domain" => $db_domain_uuid, "ext" => $boss_ext), "row");
    if ($existing) return array("error" => "Boss extension $boss_ext already has a secretary configured");

    // Generate UUIDs
    $bs_uuid = uuid();
    $dialplan_uuid = uuid();

    // Insert boss-secretary record
    $sql = "INSERT INTO v_boss_secretary (
        boss_secretary_uuid, domain_uuid, boss_extension, boss_name,
        secretary_extension, secretary_name, mode, vip_list,
        ring_timeout, cid_prefix, enabled, dialplan_uuid, insert_date
    ) VALUES (
        :uuid, :domain, :boss_ext, :boss_name,
        :sec_ext, :sec_name, :mode, :vip_list,
        :timeout, :prefix, :enabled, :dialplan_uuid, NOW()
    )";
    $result = $database->execute($sql, array(
        "uuid" => $bs_uuid, "domain" => $db_domain_uuid,
        "boss_ext" => $boss_ext, "boss_name" => $boss_name,
        "sec_ext" => $secretary_ext, "sec_name" => $secretary_name,
        "mode" => $mode, "vip_list" => $vip_list,
        "timeout" => $ring_timeout, "prefix" => $cid_prefix,
        "enabled" => $enabled, "dialplan_uuid" => $dialplan_uuid
    ));
    if ($result === false) return array("error" => "Failed to create boss-secretary record");

    // Generate dialplan
    if ($enabled === 'true' && $mode !== 'off') {
        generate_boss_secretary_dialplan($database, $dialplan_uuid, $db_domain_uuid, $domain_name,
            $boss_ext, $secretary_ext, $mode, $vip_list, $ring_timeout, $cid_prefix, $boss_name);
    }

    // Reload dialplan
    require_once "resources/switch.php";
    $esl = event_socket::create();
    if ($esl) event_socket::api("reloadxml");

    return array(
        "success" => true,
        "bossSecretaryUuid" => $bs_uuid,
        "dialplanUuid" => $dialplan_uuid,
        "bossExtension" => $boss_ext,
        "secretaryExtension" => $secretary_ext,
        "mode" => $mode,
        "message" => "Boss-Secretary pair created"
    );
}

function generate_boss_secretary_dialplan($database, $dialplan_uuid, $domain_uuid, $domain_name,
    $boss_ext, $secretary_ext, $mode, $vip_list, $ring_timeout, $cid_prefix, $boss_name) {

    // Insert dialplan entry
    $sql = "INSERT INTO v_dialplans (
        dialplan_uuid, domain_uuid, app_uuid, dialplan_name, dialplan_number,
        dialplan_context, dialplan_continue, dialplan_order, dialplan_enabled,
        dialplan_description, insert_date
    ) VALUES (
        :uuid, :domain, :app_uuid, :name, :number,
        :context, 'false', '295', 'true',
        :desc, NOW()
    )";
    $database->execute($sql, array(
        "uuid" => $dialplan_uuid, "domain" => $domain_uuid,
        "app_uuid" => BOSS_SEC_APP_UUID,
        "name" => "Boss-Secretary: $boss_ext",
        "number" => $boss_ext,
        "context" => $domain_name,
        "desc" => "Boss-Secretary filter for extension $boss_ext" . ($boss_name ? " ($boss_name)" : "")
    ));

    $group = 100;

    // MODE: VIP_ONLY or FILTER_ALL with VIP list
    if (!empty($vip_list) && $mode !== 'off') {
        $vip_numbers = array_filter(array_map('trim', explode(',', $vip_list)));
        if (!empty($vip_numbers)) {
            // Group 100: VIP callers → ring Boss directly
            $order = 10;

            // Condition: destination_number = boss_ext
            insert_detail($database, $domain_uuid, $dialplan_uuid, 'condition',
                'destination_number', '^' . $boss_ext . '$', $order, $group);
            $order += 10;

            // Condition: caller_id_number matches VIP regex
            $vip_regex = '^(' . implode('|', array_map(function($n) { return preg_quote($n, '/'); }, $vip_numbers)) . ')$';
            insert_detail($database, $domain_uuid, $dialplan_uuid, 'condition',
                'caller_id_number', $vip_regex, $order, $group);
            $order += 10;

            // Action: transfer to boss directly
            insert_detail($database, $domain_uuid, $dialplan_uuid, 'action',
                'transfer', "$boss_ext XML $domain_name", $order, $group);

            $group += 10;
        }
    }

    // Group 110: Non-VIP callers → route to Secretary (if mode is filter_all or vip_only)
    if ($mode !== 'off') {
        $order = 10;

        // Condition: destination_number = boss_ext
        insert_detail($database, $domain_uuid, $dialplan_uuid, 'condition',
            'destination_number', '^' . $boss_ext . '$', $order, $group);
        $order += 10;

        // Set CID prefix so secretary sees who the call is for
        insert_detail($database, $domain_uuid, $dialplan_uuid, 'action',
            'set', "effective_caller_id_name=${cid_prefix}\${caller_id_name}", $order, $group);
        $order += 10;

        // Set ring timeout
        insert_detail($database, $domain_uuid, $dialplan_uuid, 'action',
            'set', "call_timeout=$ring_timeout", $order, $group);
        $order += 10;

        // Transfer to secretary extension
        insert_detail($database, $domain_uuid, $dialplan_uuid, 'action',
            'transfer', "$secretary_ext XML $domain_name", $order, $group);
    }
}

function insert_detail($database, $domain_uuid, $dialplan_uuid, $tag, $type, $data, $order, $group) {
    $sql = "INSERT INTO v_dialplan_details (
        dialplan_detail_uuid, domain_uuid, dialplan_uuid,
        dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
        dialplan_detail_order, dialplan_detail_group
    ) VALUES (
        :uuid, :domain, :dialplan, :tag, :type, :data, :order, :group
    )";
    $database->execute($sql, array(
        "uuid" => uuid(), "domain" => $domain_uuid,
        "dialplan" => $dialplan_uuid,
        "tag" => $tag, "type" => $type, "data" => $data,
        "order" => $order, "group" => $group
    ));
}
