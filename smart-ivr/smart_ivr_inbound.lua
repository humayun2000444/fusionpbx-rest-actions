--
-- Smart IVR - Inbound Student Call Handler
-- Handles incoming calls for student information system
--

-- Get session variables
local domain_uuid = session:getVariable("domain_uuid")
local domain_name = session:getVariable("domain_name")
local caller_id_number = session:getVariable("caller_id_number")

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

-- Helper function to get digits
function get_digits(prompt, min_digits, max_digits, timeout)
    timeout = timeout or 5000
    play_tts(prompt)
    local digits = session:getDigits(max_digits, "#", timeout)
    return digits
end

-- Main IVR flow
function main()
    -- Answer call
    session:answer()
    session:sleep(500)

    -- Welcome message
    play_tts("Welcome to the Smart Student Information System.")
    session:sleep(500)

    -- Student verification
    local student_id = nil
    local student_verified = false
    local attempts = 0

    while not student_verified and attempts < 3 do
        student_id = get_digits("Please enter your student ID followed by hash", 5, 15, 10000)

        if student_id and student_id ~= "" then
            -- Verify student
            local verify_result = call_api("smart-ivr-student-verify", {
                student_id = student_id,
                phone_number = caller_id_number
            })

            if verify_result and verify_result.verified then
                student_verified = true
                play_tts("Welcome " .. (verify_result.student_name or ""))

                -- Save log_uuid for later
                log_uuid = verify_result.log_uuid
            else
                play_tts("Student ID not found. Please try again.")
                attempts = attempts + 1
            end
        else
            play_tts("No input received. Please try again.")
            attempts = attempts + 1
        end
    end

    if not student_verified then
        play_tts("Unable to verify your identity. Goodbye.")
        session:hangup()
        return
    end

    -- Main menu loop
    local continue_menu = true

    while continue_menu do
        local menu_choice = get_digits(
            "Press 1 for payment status, 2 for academic records, 3 for attendance, 4 for exam results, 5 for class schedule, or 9 to exit",
            1, 1, 10000
        )

        if menu_choice == "1" then
            -- Payment status
            local payment_data = call_api("smart-ivr-query-data", {
                student_id = student_id,
                query_type = "payment",
                log_uuid = log_uuid
            })

            if payment_data and payment_data.success and payment_data.data then
                local data = payment_data.data
                local arrears = data.arrears or 0
                local paid = data.paid or 0
                local late_fee = data.late_fee or 0

                play_tts(string.format(
                    "Your total payment is %d taka. You have paid %d taka. Outstanding balance is %d taka. Late fee is %d taka.",
                    arrears + paid, paid, arrears, late_fee
                ))
            else
                play_tts("Unable to retrieve payment information.")
            end

        elseif menu_choice == "2" then
            -- Academic records
            local academic_data = call_api("smart-ivr-query-data", {
                student_id = student_id,
                query_type = "academic",
                log_uuid = log_uuid
            })

            if academic_data and academic_data.success and academic_data.data then
                local data = academic_data.data
                play_tts(string.format(
                    "You are in semester %s. You have earned %s credits. Your CGPA is %s.",
                    data.semester or "unknown",
                    data.credits or "unknown",
                    data.cgpa or "unknown"
                ))
            else
                play_tts("Unable to retrieve academic records.")
            end

        elseif menu_choice == "3" then
            -- Attendance
            local attendance_data = call_api("smart-ivr-query-data", {
                student_id = student_id,
                query_type = "attendance",
                log_uuid = log_uuid
            })

            if attendance_data and attendance_data.success and attendance_data.data then
                local data = attendance_data.data
                play_tts(string.format(
                    "Your attendance percentage is %s percent.",
                    data.percentage or "unknown"
                ))
            else
                play_tts("Unable to retrieve attendance information.")
            end

        elseif menu_choice == "4" then
            -- Exam results
            local exam_data = call_api("smart-ivr-query-data", {
                student_id = student_id,
                query_type = "exam",
                log_uuid = log_uuid
            })

            if exam_data and exam_data.success and exam_data.data then
                local data = exam_data.data
                play_tts(string.format(
                    "You have %s exam results published and %s results pending.",
                    data.published or 0,
                    data.pending or 0
                ))
            else
                play_tts("Unable to retrieve exam results.")
            end

        elseif menu_choice == "5" then
            -- Class schedule
            local schedule_data = call_api("smart-ivr-query-data", {
                student_id = student_id,
                query_type = "schedule",
                log_uuid = log_uuid
            })

            if schedule_data and schedule_data.success and schedule_data.data then
                play_tts("Your class schedule information is available in the student portal.")
            else
                play_tts("Unable to retrieve schedule information.")
            end

        elseif menu_choice == "9" or menu_choice == "" then
            -- Exit
            continue_menu = false
        else
            play_tts("Invalid option. Please try again.")
        end

        if continue_menu then
            session:sleep(1000)
        end
    end

    -- Goodbye
    play_tts("Thank you for using Smart Student Information System. Goodbye.")
    session:sleep(500)
    session:hangup()
end

-- Run main function with error handling
local status, err = pcall(main)
if not status then
    freeswitch.consoleLog("ERR", "Smart IVR Error: " .. tostring(err) .. "\n")
    session:hangup()
end
