-- Speed Dial Lookup
-- Called from dialplan when user dials *XX
-- Looks up speed dial code in database, translates to full number, and dials

local speed_code = "*" .. (argv[1] or "")
local domain_name = session:getVariable("domain_name") or ""
local domain_uuid = session:getVariable("domain_uuid") or ""
local caller_ext = session:getVariable("caller_id_number") or ""
local referred_by = session:getVariable("referred_by_user") or ""
local sip_from = session:getVariable("sip_from_user") or ""
local dialed_ext = session:getVariable("dialed_extension") or ""

if speed_code == "*" or domain_name == "" then
    session:execute("playback", "misc/error.wav")
    return
end

freeswitch.consoleLog("NOTICE", "[speed-dial] Looking up " .. speed_code .. " for ext " .. caller_ext .. " referred_by=" .. referred_by .. " sip_from=" .. sip_from .. " dialed_ext=" .. dialed_ext .. " in domain " .. domain_name .. "\n")

-- Connect to database
local Database = require "resources.functions.database"
local dbh = Database.new('system')

if not dbh then
    freeswitch.consoleLog("ERR", "[speed-dial] Database connection failed\n")
    session:execute("playback", "misc/error.wav")
    return
end

-- First check personal speed dial (per-extension), then domain-wide
local destination = nil
local label = nil

-- Personal lookup: match by caller extension
local sql = [[SELECT sd.speed_dial_number, sd.speed_dial_label
              FROM v_speed_dials sd
              JOIN v_extensions e ON sd.extension_uuid = e.extension_uuid
              WHERE sd.domain_uuid = ']] .. domain_uuid .. [['
              AND sd.speed_dial_code = ']] .. speed_code .. [['
              AND sd.speed_dial_type = 'personal'
              AND sd.enabled = 'true'
              AND e.extension = ']] .. caller_ext .. [['
              LIMIT 1]]

dbh:query(sql, nil, function(row)
    destination = row.speed_dial_number
    label = row.speed_dial_label
end)

-- If no personal match, try domain-wide
if not destination then
    sql = [[SELECT speed_dial_number, speed_dial_label
            FROM v_speed_dials
            WHERE domain_uuid = ']] .. domain_uuid .. [['
            AND speed_dial_code = ']] .. speed_code .. [['
            AND speed_dial_type = 'domain'
            AND enabled = 'true'
            AND extension_uuid IS NULL
            LIMIT 1]]

    dbh:query(sql, nil, function(row)
        destination = row.speed_dial_number
        label = row.speed_dial_label
    end)
end

dbh:release()

if destination then
    freeswitch.consoleLog("NOTICE", "[speed-dial] " .. speed_code .. " -> " .. destination .. " (" .. (label or "") .. ")\n")

    -- Check if caller is a secretary trying to reach the boss
    -- If so, use bridge to bypass boss-secretary dialplan
    local is_secretary_to_boss = false
    -- Check all possible caller identities (caller_id, referred_by, sip_from, dialed_extension)
    local possible_callers = {caller_ext, referred_by, sip_from, dialed_ext}
    local bs_sql = [[SELECT boss_extension FROM v_boss_secretary
                     WHERE domain_uuid = ']] .. domain_uuid .. [['
                     AND (secretary_extension = ']] .. caller_ext .. [['
                          OR secretary_extension = ']] .. referred_by .. [['
                          OR secretary_extension = ']] .. sip_from .. [['
                          OR secretary_extension = ']] .. dialed_ext .. [[')
                     AND boss_extension = ']] .. destination .. [['
                     AND enabled = 'true'
                     LIMIT 1]]

    local dbh2 = Database.new('system')
    if dbh2 then
        dbh2:query(bs_sql, nil, function(row)
            is_secretary_to_boss = true
        end)
        dbh2:release()
    end

    if is_secretary_to_boss then
        -- Secretary dialing boss via speed dial — bridge directly, bypass boss-secretary
        freeswitch.consoleLog("NOTICE", "[speed-dial] Secretary " .. caller_ext .. " -> Boss " .. destination .. " (direct bridge)\n")
        session:setVariable("hangup_after_bridge", "true")
        session:setVariable("call_timeout", "30")
        session:execute("bridge", "user/" .. destination .. "@" .. domain_name)
    else
        -- Normal speed dial — transfer through dialplan
        session:execute("transfer", destination .. " XML " .. domain_name)
    end
else
    freeswitch.consoleLog("WARNING", "[speed-dial] No speed dial found for " .. speed_code .. "\n")
    session:execute("playback", "ivr/ivr-invalid_entry.wav")
    session:execute("sleep", "1000")
    session:hangup("UNALLOCATED_NUMBER")
end
