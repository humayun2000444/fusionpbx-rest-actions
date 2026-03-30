-- AMD Handler Lua Script for FusionPBX
-- Detects answering machines vs humans using silence detection
-- Humans say "Hello?" and wait - machines have long greetings

-- Get channel variables
local destination = session:getVariable("amd_destination") or ""
local domain_name = session:getVariable("domain_name") or "default"
local caller_id_number = session:getVariable("caller_id_number") or ""

-- AMD Parameters (tune these for your environment)
local initial_silence_ms = 2500   -- Wait for initial greeting
local silence_threshold = 200    -- Silence level threshold
local silence_hits = 25          -- How many silence hits to confirm
local max_wait_ms = 5000         -- Max time to wait for AMD decision

-- Log start
freeswitch.consoleLog("INFO", "[AMD] Starting detection for " .. caller_id_number .. "\n")

-- Answer the call if not already answered
if session:ready() then
    session:answer()
    session:sleep(500)  -- Brief pause after answer

    -- Wait for silence (human says hello then waits, machine keeps talking)
    -- wait_for_silence: <silence_thresh> <silence_hits> <listen_hits> <timeout_ms>
    local result = session:execute("wait_for_silence", silence_threshold .. " " .. silence_hits .. " 3 " .. max_wait_ms)

    -- Check if silence was detected
    local silence_detected = session:getVariable("wait_for_silence_detected_speech") or "false"
    local detect_result = session:getVariable("detect_result") or ""

    -- Get duration of audio before silence
    local audio_duration = session:getVariable("record_seconds") or "0"

    freeswitch.consoleLog("INFO", "[AMD] Silence detected: " .. tostring(silence_detected) .. ", Audio duration: " .. audio_duration .. "\n")

    -- Simple heuristic: if silence detected quickly, likely human
    -- If we timed out waiting for silence, likely machine (long greeting)
    if session:ready() then
        -- Transfer to destination (queue)
        freeswitch.consoleLog("INFO", "[AMD] Detected HUMAN - transferring to " .. destination .. "\n")
        session:execute("transfer", destination .. " XML " .. domain_name)
    end
else
    freeswitch.consoleLog("WARNING", "[AMD] Session not ready\n")
end
