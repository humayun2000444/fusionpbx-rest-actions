<?php
/**
 * order-confirm-helper.php
 *
 * Shared helpers for the Order Confirmation Call feature. Included by the
 * webhook trigger (order-confirm-call.php), the test-call action and the
 * background worker (order-confirm-worker.php).
 *
 * Provides:
 *   oc_get_config()   - load per-domain config (with sane defaults)
 *   oc_build_text()   - substitute {name}/{order_id} placeholders
 *   oc_generate_tts() - Google Cloud TTS (bn-IN / en-US) with flite fallback,
 *                       returns a playback spec string understood by the Lua IVR
 *   oc_originate()    - place the outbound call into the Lua IVR via ESL
 */

if (!function_exists('oc_get_config')) {

/** Load the domain's config row; returns an associative array (defaults if none). */
function oc_get_config($database, $domain_uuid) {
    $row = $database->select(
        "SELECT * FROM v_order_confirm_config WHERE domain_uuid = :d LIMIT 1",
        array('d' => $domain_uuid), 'row'
    );
    if (!$row) {
        // Return defaults matching the install schema so callers never crash.
        $row = array(
            'enabled' => 'true',
            'default_language' => 'en', 'voice_gender' => 'FEMALE',
            'message_template_en' => 'Dear customer {name}, your order number is {order_id}. To confirm press 1, to cancel press 2, to talk to customer service press 0.',
            'message_template_bn' => 'প্রিয় গ্রাহক {name}, আপনার অর্ডার নম্বর {order_id}। নিশ্চিত করতে ১ চাপুন, বাতিল করতে ২ চাপুন, কাস্টমার সার্ভিসের সাথে কথা বলতে ০ চাপুন।',
            'confirm_text_en' => 'Thank you. Your order has been confirmed.',
            'confirm_text_bn' => 'ধন্যবাদ। আপনার অর্ডার নিশ্চিত করা হয়েছে।',
            'cancel_text_en' => 'Your order has been cancelled.',
            'cancel_text_bn' => 'আপনার অর্ডার বাতিল করা হয়েছে।',
            'caller_id_name' => 'Order Confirmation', 'caller_id_number' => '',
            'default_support_number' => '', 'call_timeout' => 40, 'amd_enabled' => 'true',
            'default_confirm_url' => '', 'callback_auth_type' => 'none',
            'callback_auth_token' => '', 'callback_hmac_secret' => '',
            'callback_hmac_header' => 'X-Signature', 'callback_timeout' => 15,
            'retry_enabled' => 'true', 'retry_max' => 3, 'retry_interval' => 300,
            'retry_on_no_answer' => 'true', 'retry_on_busy' => 'true',
            'retry_on_voicemail' => 'true', 'retry_on_failed' => 'true',
            'callback_retry_max' => 5, 'callback_retry_interval' => 60,
            'tts_provider' => 'google', 'speech_rate' => 'slow', 'answer_delay_ms' => 2000,
            'tts_google_key' => '', 'tts_azure_key' => '', 'tts_azure_region' => 'southeastasia',
            'tts_elevenlabs_key' => '', 'tts_elevenlabs_voice_id' => '',
            'tts_openai_key' => '', 'tts_openai_voice' => 'nova',
            'ack_text_en' => 'Thank you, your response has been recorded.',
            'ack_text_bn' => 'ধন্যবাদ, আপনার উত্তর গ্রহণ করা হয়েছে।',
            'dtmf_options' => '[{"digit":"1","label":"Confirm","action":"callback","value":"1"},{"digit":"2","label":"Cancel","action":"callback","value":"2"},{"digit":"0","label":"Support","action":"transfer","value":""}]',
        );
    }
    return $row;
}

/** Expand digit glyphs to spoken words (offline neural TTS can't say bare
 *  digits like "১" or "1"). Handles ASCII 0-9 and Bengali ০-৯. */
function oc_expand_digits($text, $lang) {
    if ($lang === 'bn') {
        $map = array('0'=>' শূন্য ','1'=>' এক ','2'=>' দুই ','3'=>' তিন ','4'=>' চার ','5'=>' পাঁচ ','6'=>' ছয় ','7'=>' সাত ','8'=>' আট ','9'=>' নয় ',
                     '০'=>' শূন্য ','১'=>' এক ','২'=>' দুই ','৩'=>' তিন ','৪'=>' চার ','৫'=>' পাঁচ ','৬'=>' ছয় ','৭'=>' সাত ','৮'=>' আট ','৯'=>' নয় ');
    } else {
        $map = array('0'=>' zero ','1'=>' one ','2'=>' two ','3'=>' three ','4'=>' four ','5'=>' five ','6'=>' six ','7'=>' seven ','8'=>' eight ','9'=>' nine ');
    }
    $out = '';
    foreach (preg_split('//u', (string)$text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $out .= isset($map[$ch]) ? $map[$ch] : $ch;
    }
    return trim(preg_replace('/\s+/', ' ', $out));
}

/** Spell a reference out char-by-char so TTS reads "123" as "one two three"
 *  (and letters individually) instead of "one hundred twenty three". */
function oc_speakify($s) {
    $clean = preg_replace('/[^A-Za-z0-9]/', '', (string)$s);
    if ($clean === '') return (string)$s;
    // spaced digits/letters; use commas for a small pause between characters
    return implode(', ', str_split($clean));
}

/** Replace {name} and {order_id} placeholders. The order id is spoken
 *  digit-by-digit (speakify) so it's clear over the phone. */
function oc_build_text($template, $name, $order_id) {
    $spoken_id = oc_speakify($order_id);
    return str_replace(
        array('{name}', '{order_id}', '{orderId}'),
        array($name, $spoken_id, $spoken_id),
        (string)$template
    );
}

/** Build the full {placeholder} -> spoken-value map for a call: the fixed
 *  fields (name/order_id/phone) plus any custom fields the caller passed in
 *  via the call's metadata JSON. Any {xyz} in a template just works as long
 *  as "xyz" exists here — no template-specific code needed. */
function oc_resolve_vars($call) {
    $vars = array(
        'name'     => isset($call['customer_name']) ? $call['customer_name'] : '',
        'order_id' => oc_speakify(isset($call['order_id']) ? $call['order_id'] : ''),
        'phone'    => isset($call['phone']) ? $call['phone'] : '',
    );
    $vars['orderId'] = $vars['order_id']; // alias
    if (!empty($call['metadata'])) {
        $meta = is_array($call['metadata']) ? $call['metadata'] : json_decode($call['metadata'], true);
        if (is_array($meta)) {
            foreach ($meta as $k => $v) {
                if (is_scalar($v) && !isset($vars[$k])) $vars[$k] = (string)$v;
            }
        }
    }
    return $vars;
}

/** Split a template into an ordered list of {type:'text'|'var', ...} tokens
 *  at each {placeholder} boundary. */
function oc_split_template($template) {
    $tokens = array();
    $parts = preg_split('/(\{[a-zA-Z0-9_]+\})/', (string)$template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    foreach ($parts as $p) {
        if (preg_match('/^\{([a-zA-Z0-9_]+)\}$/', $p, $m)) {
            $tokens[] = array('type' => 'var', 'key' => $m[1]);
        } else {
            $tokens[] = array('type' => 'text', 'value' => $p);
        }
    }
    return $tokens;
}

/**
 * Generate a playback spec for a whole template, synthesizing each literal
 * chunk and each {placeholder} value as its own small audio file (each
 * individually cached by oc_generate_tts), then chaining them into one
 * continuous prompt via FreeSWITCH's "file_string://a.wav!b.wav!..." — no
 * audio merging needed. The literal chunks are identical across every call
 * for a given template, so after the first call they're a permanent cache
 * hit; only the {placeholder} values are generated fresh per call.
 * Falls back to single-shot whole-text synthesis if any segment fails.
 */
function oc_generate_tts_chain($config, $template, $vars, $language) {
    $template = trim((string)$template);
    if ($template === '') return 'flite:';

    $files = array();
    $all_ok = true;
    foreach (oc_split_template($template) as $tok) {
        if ($tok['type'] === 'text') {
            if (trim($tok['value']) === '') continue;
            $spec = oc_generate_tts($config, $tok['value'], $language);
        } else {
            $val = isset($vars[$tok['key']]) ? trim((string)$vars[$tok['key']]) : '';
            if ($val === '') continue;
            $spec = oc_generate_tts($config, $val, $language);
        }
        if (strpos($spec, 'file:') === 0) {
            $files[] = substr($spec, 5);
        } else {
            $all_ok = false; // this segment couldn't be synthesized
        }
    }

    if (!$all_ok || empty($files)) {
        $full = $template;
        foreach ($vars as $k => $v) $full = str_replace('{' . $k . '}', $v, $full);
        return oc_generate_tts($config, $full, $language); // safety-net: single-shot
    }
    if (count($files) === 1) return 'file:' . $files[0];
    return 'file_string://' . implode('!', $files);
}

/**
 * Generate a playback spec for the Lua IVR.
 *   - If Google TTS is available -> synthesize a wav and return "file:/abs/path.wav"
 *   - Otherwise -> return "flite:<text>" (FreeSWITCH built-in speech)
 * Language: 'en' => en-US, 'bn' => bn-IN.
 */
/** Wrap raw 16-bit mono PCM in a minimal WAV header. */
function oc_pcm_to_wav($pcm, $rate) {
    $n = strlen($pcm); $byteRate = $rate * 2;
    return 'RIFF' . pack('V', 36 + $n) . 'WAVEfmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1)
         . pack('V', $rate) . pack('V', $byteRate) . pack('v', 2) . pack('v', 16)
         . 'data' . pack('V', $n) . $pcm;
}

/**
 * Generate a playback spec ("file:/path.wav" or "flite:text") for the IVR.
 * Provider is chosen from $config['tts_provider']:
 *   google | azure | openai | elevenlabs
 * Falls back to the offline engines only if the configured cloud provider errors.
 */
function oc_generate_tts($config, $text, $language) {
    $text = trim((string)$text);
    if ($text === '') return 'flite:';

    $provider = isset($config['tts_provider']) && $config['tts_provider'] !== '' ? $config['tts_provider'] : 'google';
    $gender   = $config['voice_gender'] ?: 'FEMALE';
    $rate     = isset($config['speech_rate']) && $config['speech_rate'] !== '' ? $config['speech_rate'] : 'slow';

    $audio_dir = '/usr/share/freeswitch/sounds/custom/order_confirm/';
    if (!is_dir($audio_dir)) { @mkdir($audio_dir, 0775, true); @chmod($audio_dir, 0775); }
    $path = $audio_dir . 'oc_' . md5($provider . '|' . $language . '|' . $gender . '|' . $rate . '|' . $text) . '.wav';
    if (file_exists($path) && filesize($path) > 44) return 'file:' . $path; // cache

    $ok = false;

    // ---- Google Cloud TTS ----
    if ($provider === 'google' && !empty($config['tts_google_key'])) {
        $lang_code = ($language === 'bn') ? 'bn-IN' : 'en-US';
        $voice = array('languageCode' => $lang_code, 'ssmlGender' => $gender);
        if ($lang_code === 'bn-IN') $voice['name'] = ($gender === 'MALE') ? 'bn-IN-Wavenet-B' : 'bn-IN-Wavenet-A';
        $speak_rate = ($rate === 'slow') ? 0.85 : (($rate === 'fast') ? 1.15 : 1.0);
        $payload = json_encode(array('input' => array('text' => $text), 'voice' => $voice,
            'audioConfig' => array('audioEncoding' => 'LINEAR16', 'sampleRateHertz' => 8000, 'speakingRate' => $speak_rate)));
        $ch = curl_init('https://texttospeech.googleapis.com/v1/text:synthesize?key=' . $config['tts_google_key']);
        curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json')));
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 200) { $j = json_decode($resp, true);
            if (!empty($j['audioContent'])) { file_put_contents($path, base64_decode($j['audioContent'])); $ok = true; } }
    }

    // ---- Azure Cognitive Speech ----
    elseif ($provider === 'azure' && !empty($config['tts_azure_key']) && !empty($config['tts_azure_region'])) {
        $region = $config['tts_azure_region'];
        if ($language === 'bn') { $lang = 'bn-BD'; $voice = ($gender === 'MALE') ? 'bn-BD-PradeepNeural' : 'bn-BD-NabanitaNeural'; }
        else { $lang = 'en-US'; $voice = ($gender === 'MALE') ? 'en-US-GuyNeural' : 'en-US-JennyNeural'; }
        $prosody = ($rate === 'slow') ? '-15%' : (($rate === 'fast') ? '+15%' : '0%');
        $ssml = "<speak version='1.0' xml:lang='$lang'><voice name='$voice'><prosody rate='$prosody'>"
              . htmlspecialchars($text, ENT_XML1, 'UTF-8') . "</prosody></voice></speak>";
        $ch = curl_init("https://$region.tts.speech.microsoft.com/cognitiveservices/v1");
        curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $ssml,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => array(
                'Ocp-Apim-Subscription-Key: ' . $config['tts_azure_key'],
                'Content-Type: application/ssml+xml',
                'X-Microsoft-OutputFormat: riff-8khz-16bit-mono-pcm',
                'User-Agent: fusionpbx-orderconfirm')));
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 200 && $resp && substr($resp, 0, 4) === 'RIFF') { file_put_contents($path, $resp); $ok = true; }
    }

    // ---- OpenAI TTS (returns WAV directly; supports speed) ----
    elseif ($provider === 'openai' && !empty($config['tts_openai_key'])) {
        $voice = !empty($config['tts_openai_voice']) ? $config['tts_openai_voice'] : 'nova';
        $speed = ($rate === 'slow') ? 0.9 : (($rate === 'fast') ? 1.15 : 1.0);
        $payload = json_encode(array('model' => 'tts-1', 'input' => $text, 'voice' => $voice,
            'response_format' => 'wav', 'speed' => $speed));
        $ch = curl_init('https://api.openai.com/v1/audio/speech');
        curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $config['tts_openai_key'], 'Content-Type: application/json')));
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 200 && $resp && substr($resp, 0, 4) === 'RIFF') { file_put_contents($path, $resp); $ok = true; }
    }

    // ---- ElevenLabs (mp3 output -> convert to 8kHz wav via sox) ----
    // Note: ElevenLabs FREE tier blocks API voice usage (HTTP 402); a paid
    // plan (Starter+) is required.
    elseif ($provider === 'elevenlabs' && !empty($config['tts_elevenlabs_key']) && !empty($config['tts_elevenlabs_voice_id'])) {
        $vid = $config['tts_elevenlabs_voice_id'];
        $payload = json_encode(array('text' => $text, 'model_id' => 'eleven_multilingual_v2'));
        $ch = curl_init("https://api.elevenlabs.io/v1/text-to-speech/$vid?output_format=mp3_44100_128");
        curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => array(
                'xi-api-key: ' . $config['tts_elevenlabs_key'], 'Content-Type: application/json', 'Accept: audio/mpeg')));
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 200 && $resp && strlen($resp) > 200 && substr($resp, 0, 1) !== '{') {
            $mp3 = $path . '.mp3';
            file_put_contents($mp3, $resp);
            shell_exec('sox ' . escapeshellarg($mp3) . ' -r 8000 -c 1 ' . escapeshellarg($path) . ' 2>/dev/null');
            @unlink($mp3);
            $ok = file_exists($path) && filesize($path) > 44;
        }
    }

    // ---- Emergency fallback (not user-selectable): if the configured cloud
    // provider fails (bad key, quota, network), speak something rather than
    // nothing, using the on-server offline engines. ----
    if (!$ok) {
        $piper_bin = '/opt/piper/piper/piper';
        $piper_model = '/opt/piper/voices/en_US-lessac-medium.onnx';
        $text = oc_expand_digits($text, $language);   // Piper/eSpeak: digits -> words
        if ($language === 'bn') {
            // -s words/min (lower = slower), -g inter-word gap (x10ms) for clarity
            $sp = ($rate === 'slow') ? 110 : (($rate === 'fast') ? 160 : 135);
            $gap = ($rate === 'slow') ? 12 : (($rate === 'fast') ? 3 : 6);
            shell_exec('espeak-ng -v bn -s ' . $sp . ' -g ' . $gap . ' -w ' . escapeshellarg($path) . ' ' . escapeshellarg($text) . ' 2>/dev/null');
        } else {
            if (is_file($piper_bin) && is_file($piper_model)) {
                // length_scale: bigger = slower; sentence_silence adds pauses between sentences
                $ls = ($rate === 'slow') ? '1.55' : (($rate === 'fast') ? '0.9' : '1.15');
                $ss = ($rate === 'slow') ? '0.6' : (($rate === 'fast') ? '0.2' : '0.4');
                shell_exec('echo ' . escapeshellarg($text) . ' | ' . escapeshellarg($piper_bin)
                    . ' --model ' . escapeshellarg($piper_model) . ' --length_scale ' . $ls
                    . ' --sentence_silence ' . $ss
                    . ' --output_file ' . escapeshellarg($path) . ' 2>/dev/null');
            }
            if (!(file_exists($path) && filesize($path) > 44)) {
                $sp = ($rate === 'slow') ? 110 : 140;
                shell_exec('espeak-ng -v en -s ' . $sp . ' -g 8 -w ' . escapeshellarg($path) . ' ' . escapeshellarg($text) . ' 2>/dev/null');
            }
        }
        $ok = file_exists($path) && filesize($path) > 44;
    }

    if ($ok) { @chmod($path, 0664); return 'file:' . $path; }
    return 'flite:' . $text;
}

/** Open an ESL socket to the local FreeSWITCH. Returns a stream or false. */
function oc_esl_connect() {
    $fp = @fsockopen('127.0.0.1', 8021, $errno, $errstr, 5);
    if (!$fp) return false;
    stream_get_line($fp, 4096, "\n\n");           // initial auth/request banner
    fputs($fp, "auth ClueCon\n\n");
    $auth = stream_get_line($fp, 4096, "\n\n");
    if (strpos($auth, '+OK') === false) { fclose($fp); return false; }
    return $fp;
}

/**
 * Originate the outbound call into the Lua IVR.
 * $call is the v_order_confirm_calls row (array). $config is the config row.
 * Returns array(ok=>bool, fs_uuid=>string, error=>string).
 */
function oc_originate($database, $call, $config) {
    $domain_uuid = $call['domain_uuid'];
    $dom = $database->select("SELECT domain_name FROM v_domains WHERE domain_uuid = :d",
        array('d' => $domain_uuid), 'row');
    $domain_name = $dom ? $dom['domain_name'] : 'default';

    // Find the domain's outbound gateway (the one its outbound routes bridge to).
    $gateway_uuid = null;
    $gwrow = $database->select(
        "SELECT dd.dialplan_detail_data AS d
           FROM v_dialplan_details dd
           JOIN v_dialplans dp ON dp.dialplan_uuid = dd.dialplan_uuid
          WHERE dp.domain_uuid = :d AND dd.dialplan_detail_data LIKE '%sofia/gateway/%'
          LIMIT 1", array('d' => $domain_uuid), 'row');
    if ($gwrow && preg_match('#sofia/gateway/([0-9a-fA-F\-]{36})/#', $gwrow['d'], $m)) {
        $gateway_uuid = $m[1];
    }
    if (!$gateway_uuid) {
        $g = $database->select("SELECT gateway_uuid FROM v_gateways WHERE domain_uuid = :d AND enabled = 'true' LIMIT 1",
            array('d' => $domain_uuid), 'row');
        $gateway_uuid = $g ? $g['gateway_uuid'] : null;
    }

    $language = !empty($call['language']) ? $call['language'] : ($config['default_language'] ?: 'en');
    $tmpl = ($language === 'bn') ? $config['message_template_bn'] : $config['message_template_en'];
    $ack_t = ($language === 'bn')
        ? (isset($config['ack_text_bn']) ? $config['ack_text_bn'] : 'ধন্যবাদ।')
        : (isset($config['ack_text_en']) ? $config['ack_text_en'] : 'Thank you, your response has been recorded.');

    $vars = oc_resolve_vars($call);
    $tts  = oc_generate_tts_chain($config, $tmpl, $vars, $language);
    $ack  = oc_generate_tts_chain($config, $ack_t, $vars, $language);

    // Dynamic DTMF option map. Each option: digit,label,action(callback|transfer|hangup),value.
    $opts = array();
    if (!empty($config['dtmf_options'])) {
        $decoded = is_array($config['dtmf_options']) ? $config['dtmf_options'] : json_decode($config['dtmf_options'], true);
        if (is_array($decoded)) $opts = $decoded;
    }
    if (empty($opts)) {
        $opts = array(
            array('digit'=>'1','label'=>'Confirm','action'=>'callback','value'=>'1'),
            array('digit'=>'2','label'=>'Cancel','action'=>'callback','value'=>'2'),
            array('digit'=>'0','label'=>'Support','action'=>'transfer','value'=>''),
        );
    }
    // Encode as "digit=action=value=label" lines for the Lua (no JSON dep).
    // The Lua only needs: what kind of action (api|transfer|hangup) and, for a
    // transfer, where to send the call. All API details (method/url/auth/payload)
    // are resolved by the worker from config, keyed by the pressed digit.
    // Fields separated by '~' (not in base64), records by newline:
    //   digit~action~transferDest~label~sayAudioB64
    // sayAudioB64 is this option's own spoken response (per language); if the
    // option has no say text, the global ack is used.
    $valid = ''; $lines = array();
    foreach ($opts as $o) {
        $d = isset($o['digit']) ? preg_replace('/[^0-9]/','',substr((string)$o['digit'],0,1)) : '';
        if ($d === '') continue;
        $a = isset($o['action']) ? $o['action'] : 'api';
        if ($a === 'callback') $a = 'api';                 // legacy alias
        $v = ($a === 'transfer')
            ? str_replace(array("~","\n"),'', (isset($o['transferTo']) ? $o['transferTo'] : (isset($o['value']) ? $o['value'] : '')))
            : '';
        $l = isset($o['label']) ? str_replace(array("~","\n"),'',$o['label']) : '';
        // per-option spoken response
        $sayt = ($language === 'bn') ? (isset($o['sayBn']) ? $o['sayBn'] : '') : (isset($o['sayEn']) ? $o['sayEn'] : '');
        $sayspec = ($sayt !== '')
            ? oc_generate_tts_chain($config, $sayt, $vars, $language)
            : $ack;
        $valid .= $d;
        $lines[] = "$d~$a~$v~$l~" . base64_encode($sayspec);
    }
    if ($valid === '') { $valid = '120'; $lines = array('1~api~1~Confirm~' . base64_encode($ack),
        '2~api~2~Cancel~' . base64_encode($ack), '0~transfer~~Support~' . base64_encode($ack)); }
    $opts_enc = implode("\n", $lines);

    $fs_uuid = uuid();
    $support = $call['support_number'] !== '' ? $call['support_number'] : $config['default_support_number'];
    $cid_num = $config['caller_id_number'] !== '' ? $config['caller_id_number'] : $call['phone'];
    $timeout = intval($config['call_timeout']) ?: 40;
    $amd     = ($config['amd_enabled'] === 'true' || $config['amd_enabled'] === true || $config['amd_enabled'] === 't') ? 'true' : 'false';

    $cid_name = str_replace("'", "", ($config['caller_id_name'] ?: 'Order Confirmation'));

    if (!$gateway_uuid) {
        $database->execute("UPDATE v_order_confirm_calls SET status='failed', disposition='no_gateway',
            attempts = attempts + 1, last_attempt_date = NOW() WHERE call_uuid = :c",
            array('c' => $call['call_uuid']));
        return array('ok' => false, 'error' => 'No outbound gateway found for domain');
    }

    // Channel variables. Values with commas/spaces are single-quoted so the
    // originate {} parser doesn't split them. TTS specs are base64.
    $vars  = "origination_uuid=$fs_uuid";
    $vars .= ",origination_caller_id_number=$cid_num";
    $vars .= ",origination_caller_id_name='$cid_name'";
    $vars .= ",effective_caller_id_number=$cid_num";
    $vars .= ",effective_caller_id_name='$cid_name'";
    $vars .= ",absolute_codec_string='PCMU,PCMA'";        // force PCMU/PCMA
    $vars .= ",call_timeout=$timeout";
    $vars .= ",oc_call_uuid=" . $call['call_uuid'];
    $vars .= ",oc_domain_uuid=$domain_uuid";
    $vars .= ",oc_domain_name=$domain_name";
    $vars .= ",oc_support=" . ($support ?: '');
    $vars .= ",oc_amd=$amd";
    $vars .= ",oc_answer_delay=" . (isset($config['answer_delay_ms']) ? intval($config['answer_delay_ms']) : 2000);
    $vars .= ",oc_msg_b64=" . base64_encode($tts);
    $vars .= ",oc_ack_b64=" . base64_encode($ack);
    $vars .= ",oc_valid=" . $valid;
    $vars .= ",oc_opts_b64=" . base64_encode($opts_enc);

    $fp = oc_esl_connect();
    if (!$fp) return array('ok' => false, 'error' => 'ESL connect failed');

    // Direct outbound call to the customer via the gateway; run the Lua IVR
    // on the customer channel when THEY answer (A-leg = the real call, so the
    // IVR only starts after pickup — not on a loopback that auto-answers).
    $cmd = "bgapi originate {" . $vars . "}sofia/gateway/$gateway_uuid/" . $call['phone']
         . " &lua(order-confirm-ivr.lua)";
    fputs($fp, "$cmd\n\n");
    $resp = stream_get_line($fp, 8192, "\n\n");
    fclose($fp);

    // Persist the resolved playback + fs uuid on the job.
    $database->execute(
        "UPDATE v_order_confirm_calls
            SET fs_call_uuid = :u, tts_spec = :t, status = 'calling',
                attempts = attempts + 1, last_attempt_date = NOW()
          WHERE call_uuid = :c",
        array('u' => $fs_uuid, 't' => $tts, 'c' => $call['call_uuid'])
    );

    return array('ok' => (strpos($resp, '+OK') !== false || strpos($resp, 'Job-UUID') !== false),
                 'fs_uuid' => $fs_uuid, 'raw' => trim($resp));
}

} // function_exists guard
