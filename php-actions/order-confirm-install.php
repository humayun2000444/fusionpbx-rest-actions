<?php
/**
 * order-confirm-install.php
 * Creates the Order Confirmation tables by running order-confirm-install.sql.
 * Idempotent (CREATE TABLE IF NOT EXISTS). Call once after deployment.
 */

$required_params = array();

function do_action($body) {
    $sql_file = __DIR__ . '/order-confirm-install.sql';
    if (!file_exists($sql_file)) {
        return array("success" => false, "error" => "order-confirm-install.sql not found next to this action");
    }
    $sql = file_get_contents($sql_file);

    $database = new database;
    // Split on semicolons at end of line to run statements individually.
    $statements = preg_split('/;\s*\n/', $sql);
    $ran = 0; $errors = array();
    foreach ($statements as $stmt) {
        // strip whole-line SQL comments, then run whatever SQL remains
        $stmt = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
        if ($stmt === '') continue;
        try {
            $database->execute($stmt);
            $ran++;
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }

    // Verify the main table exists
    try {
        $database->select("SELECT 1 FROM v_order_confirm_calls LIMIT 1", array(), 'row');
        $installed = true;
    } catch (Exception $e) {
        $installed = false;
    }

    // Deploy the Lua IVR into the FreeSWITCH scripts dir (best-effort; needs
    // write access). If this fails, copy order-confirm-ivr.lua there manually.
    $lua_src  = __DIR__ . '/order-confirm-ivr.lua';
    $lua_dest = '/usr/share/freeswitch/scripts/order-confirm-ivr.lua';
    $lua_deployed = false;
    if (file_exists($lua_src) && is_writable(dirname($lua_dest))) {
        $lua_deployed = @copy($lua_src, $lua_dest);
        if ($lua_deployed) { @chown($lua_dest, 'www-data'); @chgrp($lua_dest, 'www-data'); }
    }

    return array(
        "success" => $installed,
        "message" => $installed ? "Order Confirmation schema installed" : "Install may have failed",
        "statementsRun" => $ran,
        "errors" => $errors,
        "luaDeployed" => $lua_deployed,
        "luaNote" => $lua_deployed ? "order-confirm-ivr.lua deployed to FreeSWITCH scripts"
                                   : "Copy order-confirm-ivr.lua to /usr/share/freeswitch/scripts/ manually",
    );
}
