<?php
$required_params = array();

function do_action($body) {
    global $domain_uuid, $domain_name;

    $template_dir = '/var/www/fusionpbx/app/provision/resources/templates/provision/';

    // Get installed vendors by scanning directory
    $installed = array();
    if (is_dir($template_dir)) {
        $entries = scandir($template_dir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            if (is_dir($template_dir . $entry)) {
                // Count files in vendor directory
                $file_count = 0;
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($template_dir . $entry, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $file_count++;
                    }
                }
                $installed[] = array(
                    "vendor" => $entry,
                    "path" => $template_dir . $entry,
                    "fileCount" => $file_count,
                );
            }
        }
    }

    // Build list of installed vendor names for quick lookup
    $installed_names = array();
    foreach ($installed as $inst) {
        $installed_names[] = $inst['vendor'];
    }

    // Hardcoded list of popular available vendors
    $available_vendors = array(
        array("vendor" => "grandstream", "label" => "Grandstream", "models" => array("GXP1610", "GXP1615", "GXP1620", "GXP1625", "GXP1628", "GXP1630", "GXP2130", "GXP2135", "GXP2140", "GXP2160", "GXP2170", "GRP2612", "GRP2613", "GRP2614", "GRP2615", "GRP2616", "HT801", "HT802", "HT812", "HT814")),
        array("vendor" => "yealink", "label" => "Yealink", "models" => array("T19P", "T21P", "T23G", "T27G", "T29G", "T33G", "T40G", "T42S", "T46S", "T48S", "T53", "T54W", "T57W")),
        array("vendor" => "polycom", "label" => "Polycom", "models" => array("VVX101", "VVX150", "VVX201", "VVX250", "VVX301", "VVX311", "VVX350", "VVX401", "VVX411", "VVX450", "VVX501", "VVX601")),
        array("vendor" => "cisco", "label" => "Cisco", "models" => array("SPA301", "SPA303", "SPA501G", "SPA502G", "SPA504G", "SPA508G", "SPA509G", "SPA512G", "SPA514G")),
        array("vendor" => "fanvil", "label" => "Fanvil", "models" => array("X1", "X1S", "X3S", "X3U", "X4", "X4U", "X5S", "X5U", "X6", "X6U", "X7", "X7C", "X210")),
        array("vendor" => "snom", "label" => "Snom", "models" => array("D120", "D305", "D315", "D345", "D375", "D385", "D712", "D713", "D715", "D717", "D735", "D785")),
        array("vendor" => "htek", "label" => "Htek", "models" => array("UC902", "UC912E", "UC921G", "UC923", "UC924E", "UC926E")),
        array("vendor" => "linphone", "label" => "Linphone", "models" => array("Desktop", "Mobile")),
        array("vendor" => "algo", "label" => "Algo", "models" => array("8028", "8180", "8186", "8188", "8196", "8301")),
    );

    // Mark each available vendor with installed status
    $available = array();
    foreach ($available_vendors as $v) {
        $v['installed'] = in_array($v['vendor'], $installed_names);
        $available[] = $v;
    }

    return array(
        "success" => true,
        "installed" => $installed,
        "available" => $available,
    );
}
