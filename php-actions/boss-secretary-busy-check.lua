-- Boss-Secretary busy check + bridge/transfer
-- Args: boss_ext domain secretary_ext cid_prefix

local boss_ext = argv[1]
local domain = argv[2]
local secretary_ext = argv[3]
local cid_prefix = argv[4] or "Boss:"

if not boss_ext or not domain or not secretary_ext then
    return
end

-- Check if boss has active calls via show calls
local api = freeswitch.API()
local result = api:execute("show", "calls as delim |")

local busy = false
if result then
    local pattern = boss_ext .. "@" .. domain
    for line in result:gmatch("[^\n]+") do
        -- Skip the header line and total line
        if line:find(pattern, 1, true) and not line:find("total") then
            -- Make sure it's the boss as caller/callee, not our current call
            local current_uuid = session:getVariable("uuid") or ""
            if not line:find(current_uuid, 1, true) then
                busy = true
                break
            end
        end
    end
end

if busy then
    -- Boss is busy - bridge directly to secretary (bypass dialplan to avoid loop)
    freeswitch.consoleLog("NOTICE", "[boss-secretary] Boss " .. boss_ext .. " is BUSY, bridging to secretary " .. secretary_ext .. "\n")
    session:setVariable("effective_caller_id_name", "BUSY " .. cid_prefix .. " " .. (session:getVariable("caller_id_name") or ""))
    session:setVariable("hangup_after_bridge", "true")
    session:setVariable("call_timeout", "20")
    session:execute("bridge", "user/" .. secretary_ext .. "@" .. domain)
else
    -- Boss is free - bridge to boss
    freeswitch.consoleLog("NOTICE", "[boss-secretary] Boss " .. boss_ext .. " is FREE, bridging\n")
    session:setVariable("hangup_after_bridge", "true")
    session:setVariable("call_timeout", "30")
    session:execute("bridge", "user/" .. boss_ext .. "@" .. domain)

    -- If bridge fails (no answer/timeout) - bridge directly to secretary
    local hangup_cause = session:getVariable("originate_disposition") or ""
    if hangup_cause ~= "SUCCESS" and session:ready() then
        freeswitch.consoleLog("NOTICE", "[boss-secretary] Boss " .. boss_ext .. " no answer (" .. hangup_cause .. "), bridging to secretary\n")
        session:setVariable("effective_caller_id_name", "NOANSWER " .. cid_prefix .. " " .. (session:getVariable("caller_id_name") or ""))
        session:setVariable("hangup_after_bridge", "true")
        session:setVariable("call_timeout", "20")
        session:execute("bridge", "user/" .. secretary_ext .. "@" .. domain)
    end
end
