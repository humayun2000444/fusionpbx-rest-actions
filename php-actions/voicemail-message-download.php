<?php

// Download/stream a voicemail message audio file

$required_params = array('messageUuid');

function do_action($body) {
    $message_uuid = $body->messageUuid;
    $database = new database;

    // Get the message with audio data
    $sql = "SELECT vm.voicemail_message_uuid, vm.message_base64, vm.caller_id_name,
                   vm.caller_id_number, vm.created_epoch, vm.message_length,
                   v.voicemail_id
            FROM v_voicemail_messages vm
            JOIN v_voicemails v ON v.voicemail_uuid = vm.voicemail_uuid
            WHERE vm.voicemail_message_uuid = :message_uuid";

    $row = $database->select($sql, array('message_uuid' => $message_uuid), 'row');

    if (empty($row)) {
        return array('success' => false, 'message' => 'Message not found');
    }

    if (empty($row['message_base64'])) {
        return array('success' => false, 'message' => 'No audio data available');
    }

    // Mark as read
    $sql = "UPDATE v_voicemail_messages SET message_status = 'saved', read_epoch = :epoch
            WHERE voicemail_message_uuid = :message_uuid AND (message_status IS NULL OR message_status = '')";
    $database->execute($sql, array(
        'message_uuid' => $message_uuid,
        'epoch' => time()
    ));

    return array(
        'success' => true,
        'messageUuid' => $row['voicemail_message_uuid'],
        'voicemailId' => $row['voicemail_id'],
        'callerIdName' => $row['caller_id_name'],
        'callerIdNumber' => $row['caller_id_number'],
        'createdEpoch' => (int)$row['created_epoch'],
        'messageLengthSeconds' => (int)$row['message_length'],
        'audioBase64' => $row['message_base64'],
        'audioContentType' => 'audio/wav'
    );
}
