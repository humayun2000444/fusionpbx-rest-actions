<?php

// Shared dialplan generation for boss-secretary feature
// Included by create, update, and mode PHP actions

define('BOSS_SEC_APP_UUID', 'b0555ec4-e7a4-4000-b055-000000000001');

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

    // VIP bypass group
    if (!empty($vip_list) && $mode !== 'off') {
        $vip_numbers = array_filter(array_map('trim', explode(',', $vip_list)));
        if (!empty($vip_numbers)) {
            $order = 10;
            bs_insert_detail($database, $domain_uuid, $dialplan_uuid, 'condition',
                'destination_number', '^' . $boss_ext . '$', $order, $group);
            $order += 10;
            $vip_regex = '^(' . implode('|', array_map(function($n) { return preg_quote($n, '/'); }, $vip_numbers)) . ')$';
            bs_insert_detail($database, $domain_uuid, $dialplan_uuid, 'condition',
                'caller_id_number', $vip_regex, $order, $group);
            $order += 10;
            bs_insert_detail($database, $domain_uuid, $dialplan_uuid, 'action',
                'transfer', "$boss_ext XML $domain_name", $order, $group);
            $group += 10;
        }
    }

    // Secretary route group
    if ($mode !== 'off') {
        $order = 10;
        bs_insert_detail($database, $domain_uuid, $dialplan_uuid, 'condition',
            'destination_number', '^' . $boss_ext . '$', $order, $group);
        $order += 10;
        bs_insert_detail($database, $domain_uuid, $dialplan_uuid, 'action',
            'set', "effective_caller_id_name=${cid_prefix}\${caller_id_name}", $order, $group);
        $order += 10;
        bs_insert_detail($database, $domain_uuid, $dialplan_uuid, 'action',
            'set', "call_timeout=$ring_timeout", $order, $group);
        $order += 10;
        bs_insert_detail($database, $domain_uuid, $dialplan_uuid, 'action',
            'transfer', "$secretary_ext XML $domain_name", $order, $group);
    }
}

function bs_insert_detail($database, $domain_uuid, $dialplan_uuid, $tag, $type, $data, $order, $group) {
    $sql = "INSERT INTO v_dialplan_details (
        dialplan_detail_uuid, domain_uuid, dialplan_uuid,
        dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data,
        dialplan_detail_order, dialplan_detail_group
    ) VALUES (:uuid, :domain, :dialplan, :tag, :type, :data, :order, :group)";
    $database->execute($sql, array(
        "uuid" => uuid(), "domain" => $domain_uuid, "dialplan" => $dialplan_uuid,
        "tag" => $tag, "type" => $type, "data" => $data, "order" => $order, "group" => $group
    ));
}
