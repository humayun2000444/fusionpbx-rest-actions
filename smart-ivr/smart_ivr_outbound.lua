--
-- Smart IVR - Outbound Campaign Call Handler
-- Handles outbound automated calls for campaigns
--

-- Get session variables
local domain_uuid = session:getVariable("domain_uuid")
local queue_uuid = session:getVariable("queue_uuid")
local campaign_uuid = session:getVariable("campaign_uuid")

-- API configuration
local api_base_url = "https://114.130.145.82/app/rest_api/rest.php"
local api_key = "0c1ece42-31ce-4174-99e2-37e709fe348b"

-- Helper function to call REST API
function call_api(action, data)
    data = data or {}
    data.action = action
    data.domain_uuid = domain_uuid

    local json_data = JSON:encode(data)
    local api = freeswitch.API()
    local cmd = string.format(
        "curl -s -k -X POST %s -u '%s:x' -H 'Content-Type: application/json' -d '%s'",
        api_base_url, api_key, json_data
    )

    local result = api:execute("system", cmd)
    if result then
        return JSON:decode(result)
    end
    return nil
end

-- Helper function to play TTS
function play_tts(text, language)
    language = language or "en-US"

    -- Generate TTS via API
    local tts_result = call_api("smart-ivr-tts-generate", {
        text = text,
        language = language
    })

    if tts_result and tts_result.tts_type == "google" then
        session:streamFile(tts_result.audio_path)
    elseif tts_result and tts_result.tts_string then
        session:execute("speak", tts_result.tts_string)
    else
        -- Fallback to flite
        session:execute("speak", "flite|rms|" .. text)
    end

    session:sleep(500)
end

-- Helper function to get DTMF digit
function get_digit(prompt, timeout)
    timeout = timeout or 10000
    if prompt then
        play_tts(prompt)
    end
    local digit = session:getDigits(1, "", timeout)
    return digit
end

-- Main outbound call flow
function main()
    -- Get queue item if not provided
    if not queue_uuid then
        local next_result = call_api("smart-ivr-queue-next", {
            campaign_uuid = campaign_uuid
        })

        if not next_result or not next_result.success then
            freeswitch.consoleLog("INFO", "No calls in queue\n")
            session:hangup()
            return
        end

        queue_item = next_result.queue_item
        campaign = next_result.campaign
        queue_uuid = queue_item.queue_uuid
    end

    -- Answer call
    if session:ready() then
        session:answer()
    else
        -- Mark as no answer
        call_api("smart-ivr-feedback-save", {
            queue_uuid = queue_uuid,
            student_id = queue_item.student_id,
            feedback_type = "status",
            feedback_value = "no_answer"
        })
        return
    end

    session:sleep(1000)

    -- Greeting
    local student_name = queue_item.student_name or "Student"
    play_tts("Hello " .. student_name)

    -- Play message
    local message = queue_item.message or campaign.message_template or "This is an automated message from your institution."

    -- Replace placeholders in message
    if queue_item.custom_data then
        local custom = JSON:decode(queue_item.custom_data)
        for key, value in pairs(custom) do
            message = string.gsub(message, "{" .. key .. "}", tostring(value))
        end
    end

    play_tts(message)

    -- Collect feedback if required
    if campaign and campaign.require_feedback then
        local feedback_prompt = campaign.feedback_prompt or "Press 1 to confirm, 2 for more information, or hang up."
        local feedback = get_digit(feedback_prompt, 10000)

        if feedback and feedback ~= "" then
            -- Save feedback
            call_api("smart-ivr-feedback-save", {
                queue_uuid = queue_uuid,
                student_id = queue_item.student_id,
                feedback_type = "dtmf",
                feedback_value = feedback,
                question = feedback_prompt
            })

            -- Respond based on feedback
            if feedback == "1" then
                play_tts("Thank you for your confirmation.")
            elseif feedback == "2" then
                play_tts("Please contact your institution office for more information.")
            else
                play_tts("Thank you for your response.")
            end
        end
    end

    -- Goodbye
    play_tts("Thank you. Goodbye.")
    session:sleep(500)

    -- Update queue status
    local call_end_time = os.time()
    local call_duration = session:getVariable("billsec") or 0

    call_api("system", string.format(
        "UPDATE v_smart_ivr_queue SET status = 'answered', answered_time = NOW(), hangup_time = NOW(), call_duration = %d WHERE queue_uuid = '%s'",
        call_duration, queue_uuid
    ))

    session:hangup()
end

-- Run main function with error handling
local status, err = pcall(main)
if not status then
    freeswitch.consoleLog("ERR", "Smart IVR Outbound Error: " .. tostring(err) .. "\n")

    if queue_uuid then
        -- Mark as failed
        call_api("smart-ivr-feedback-save", {
            queue_uuid = queue_uuid,
            feedback_type = "status",
            feedback_value = "failed",
            question = "Error: " .. tostring(err)
        })
    end

    session:hangup()
end
