-- order-confirm-ivr.lua
-- Runtime IVR for the Order Confirmation Call feature.
-- Runs on the answered customer channel. Plays the order message, reads one
-- digit (1=confirm, 2=cancel, 0=support), records the result in the database
-- and either transfers to support (0) or plays an acknowledgement and hangs up.
--
-- Channel variables (set by oc_originate in order-confirm-helper.php):
--   oc_call_uuid, oc_domain_name, oc_support, oc_amd,
--   oc_msg_b64, oc_confirm_b64, oc_cancel_b64   (playback specs, base64)
-- A playback spec is "file:/abs/path.wav", "file_string://a.wav!b.wav!..."
-- (chained multi-segment prompt), or "flite:English text".

-- ---------- tiny base64 decoder (no external deps) ----------
local b = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'
local function b64dec(data)
    if not data or data == '' then return '' end
    data = string.gsub(data, '[^' .. b .. '=]', '')
    return (data:gsub('.', function(x)
        if x == '=' then return '' end
        local r, f = '', (b:find(x) - 1)
        for i = 6, 1, -1 do r = r .. (f % 2 ^ i - f % 2 ^ (i - 1) > 0 and '1' or '0') end
        return r
    end):gsub('%d%d%d?%d?%d?%d?%d?%d?', function(x)
        if #x ~= 8 then return '' end
        local c = 0
        for i = 1, 8 do c = c + (x:sub(i, i) == '1' and 2 ^ (8 - i) or 0) end
        return string.char(c)
    end))
end

local function log(msg) freeswitch.consoleLog("NOTICE", "[order-confirm] " .. msg .. "\n") end

-- ---------- read channel variables ----------
local call_uuid   = session:getVariable("oc_call_uuid") or ""
local domain_name = session:getVariable("oc_domain_name") or "default"
local support     = session:getVariable("oc_support") or ""
local amd_enabled = (session:getVariable("oc_amd") == "true")
local msg_spec    = b64dec(session:getVariable("oc_msg_b64") or "")
local ack_spec    = b64dec(session:getVariable("oc_ack_b64") or "")
local valid       = session:getVariable("oc_valid") or "120"
local opts_raw    = b64dec(session:getVariable("oc_opts_b64") or "")

-- parse dynamic DTMF options: lines of "digit~action~dest~label~sayAudioB64"
local options = {}
for line in opts_raw:gmatch("[^\n]+") do
    local d, a, v, l, say = line:match("^(%d)~([^~]*)~([^~]*)~([^~]*)~(.*)$")
    if d then options[d] = { action = a, value = v, label = l, say = b64dec(say or "") } end
end

-- ---------- database helper ----------
local Database = require "resources.functions.database"
local function db_update(sets)
    local dbh = Database.new('system')
    if not dbh then log("DB connection failed"); return end
    local sql = "UPDATE v_order_confirm_calls SET " .. sets ..
                " WHERE call_uuid = '" .. call_uuid .. "'"
    dbh:query(sql)
    dbh:release()
end

-- ---------- playback + single digit capture ----------
-- Placeholder prompt used when no real TTS audio is available (no Google TTS
-- key / no flite). Lets the customer still hear something and press a digit so
-- the confirm/cancel/support flow is fully testable. Replace by configuring
-- Google TTS for the real spoken message.
local FALLBACK_PROMPT = "ivr/ivr-welcome_to_freeswitch.wav"

local function tts_file(spec)
    -- returns a playable path/URI, or nil if the spec is not real audio
    if spec:sub(1, 14) == "file_string://" then return spec end  -- chained prompt, pass through as-is
    local kind = spec:match("^(%a+):")
    if kind == "file" then return (spec:gsub("^%a+:", "")) end
    -- "flite:" specs need a TTS engine; if flite is loaded use it, else fallback
    return nil
end

local function play_and_read(spec)
    if session:ready() ~= true then return "" end
    -- Warm the RTP/media path so the first words aren't clipped on the callee side.
    session:execute("playback", "silence_stream://1200")
    local regex = "[" .. valid .. "]"
    local f = tts_file(spec)
    if f then
        return session:playAndGetDigits(1, 1, 3, 7000, "#", f, "", regex)
    end
    -- no real audio -> fall back to a built-in prompt so DTMF still works
    for _ = 1, 3 do
        if session:ready() ~= true then break end
        session:flushDigits()
        local d = session:playAndGetDigits(1, 1, 1, 6000, "#", FALLBACK_PROMPT, "", regex)
        if options[d] then return d end
    end
    return ""
end

local function play(spec)
    if session:ready() ~= true or spec == "" then return end
    local f = tts_file(spec)
    if f then session:streamFile(f); return end
    local text = spec:gsub("^%a+:", "")
    local ok = pcall(function() session:set_tts_params("flite", "kal"); session:speak(text) end)
    if not ok then session:streamFile("ivr/ivr-thank_you.wav") end  -- generic ack fallback
end

-- ============================================================
if session:ready() ~= true then return end
session:answer()
-- Pause after answer before speaking (gives the callee a moment; avoids
-- clipping the first words). Configurable via oc_answer_delay (ms).
local answer_delay = tonumber(session:getVariable("oc_answer_delay") or "2000") or 2000
session:sleep(answer_delay)
db_update("status = 'answered', answered_date = NOW()")

-- ---------- optional answering-machine detection ----------
if amd_enabled then
    -- Human: short greeting then silence. Machine: long continuous greeting.
    session:execute("wait_for_silence", "200 25 3 4000")
    -- If the channel is still up and we saw a very long greeting, treat as machine.
    -- (Heuristic; tune per environment.)
    local ready = session:ready()
    if ready ~= true then
        db_update("status = 'voicemail', disposition = 'voicemail', complete_date = NOW()")
        return
    end
end

-- escape a value for inline SQL
local function esc(s) return (tostring(s or ""):gsub("'", "''")) end

-- ---------- main prompt ----------
local digit = play_and_read(msg_spec)
local opt = options[digit]
log("call " .. call_uuid .. " digit=" .. (digit or "") .. " action=" .. (opt and opt.action or "none"))

if opt then
    if opt.action == "transfer" then
        local dest = (opt.value ~= "" and opt.value) or support
        if dest ~= "" then
            db_update("status='transferred', dtmf_pressed='" .. esc(digit) .. "', disposition='" .. esc(opt.label) .. "', complete_date=NOW()")
            if opt.say ~= nil and opt.say ~= "" then play(opt.say) end  -- e.g. "connecting you now"
            log("transferring to " .. dest)
            session:execute("transfer", dest .. " XML " .. domain_name)
        else
            db_update("status='answered', dtmf_pressed='" .. esc(digit) .. "', disposition='no_transfer_target', complete_date=NOW()")
            session:hangup()
        end

    elseif opt.action == "api" or opt.action == "callback" then
        -- record the keypress; the worker resolves this digit's API config
        -- (method/url/auth/payload) from config and calls it.
        db_update("status='responded', dtmf_pressed='" .. esc(digit) .. "', disposition='" .. esc(opt.label)
            .. "', callback_pending=TRUE, complete_date=NOW()")
        play((opt.say ~= nil and opt.say ~= "") and opt.say or ack_spec)  -- this key's own response
        session:hangup()

    else  -- "hangup" or unknown: acknowledge and end (no callback)
        db_update("status='responded', dtmf_pressed='" .. esc(digit) .. "', disposition='" .. esc(opt.label) .. "', complete_date=NOW()")
        play((opt.say ~= nil and opt.say ~= "") and opt.say or ack_spec)
        session:hangup()
    end
else
    -- answered but no valid input
    db_update("status='answered', disposition='no_input', complete_date=NOW()")
    session:hangup()
end
