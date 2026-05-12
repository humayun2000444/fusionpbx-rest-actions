<?php
$required_params = array("vendor");

function do_action($body) {
    global $domain_uuid, $domain_name;

    $vendor = strtolower(trim($body->vendor));
    $base_dir = '/var/www/fusionpbx/app/provision/resources/templates/provision/';
    $target_dir = $base_dir . $vendor . '/';

    // Validate vendor name (alphanumeric and hyphens only)
    if (!preg_match('/^[a-z0-9\-]+$/', $vendor)) {
        return array("error" => "Invalid vendor name. Use only lowercase letters, numbers, and hyphens.");
    }

    // GitHub API context with user-agent (required by GitHub)
    $context = stream_context_create(array(
        'http' => array(
            'header' => "User-Agent: FusionPBX-Provisioning\r\n",
            'timeout' => 30,
        ),
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
        ),
    ));

    // GitHub API URL for vendor template directory
    $api_url = 'https://api.github.com/repos/fusionpbx/fusionpbx-provisioning/contents/resources/templates/provision/' . $vendor;

    // Fetch directory listing from GitHub
    $response = @file_get_contents($api_url, false, $context);
    if ($response === false) {
        // Check for HTTP response headers
        $error_msg = "Failed to fetch template listing from GitHub.";
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (stripos($header, '404') !== false) {
                    $error_msg = "Vendor '$vendor' not found in FusionPBX provisioning repository.";
                    break;
                }
                if (stripos($header, '403') !== false) {
                    $error_msg = "GitHub API rate limit exceeded. Please try again later.";
                    break;
                }
            }
        }
        return array("error" => $error_msg);
    }

    $items = json_decode($response, true);
    if (!is_array($items)) {
        return array("error" => "Invalid response from GitHub API.");
    }

    // Create target directory
    if (!is_dir($target_dir)) {
        if (!@mkdir($target_dir, 0755, true)) {
            return array("error" => "Failed to create directory: $target_dir. Check write permissions.");
        }
    }

    // Download files recursively
    $files_installed = 0;
    $errors = array();
    download_directory($items, $target_dir, $context, $files_installed, $errors);

    // Set ownership on the vendor directory
    @exec('chown -R www-data:www-data ' . escapeshellarg($target_dir));

    if ($files_installed === 0 && !empty($errors)) {
        return array(
            "error" => "Failed to install templates for vendor '$vendor'.",
            "details" => $errors,
        );
    }

    $result = array(
        "success" => true,
        "message" => "Installed $files_installed files for $vendor",
        "vendor" => $vendor,
        "filesInstalled" => $files_installed,
    );

    if (!empty($errors)) {
        $result['warnings'] = $errors;
    }

    return $result;
}

/**
 * Recursively download files from a GitHub directory listing
 */
function download_directory($items, $target_dir, $context, &$files_installed, &$errors) {
    foreach ($items as $item) {
        $name = $item['name'];
        $type = $item['type'];

        if ($type === 'dir') {
            // It's a subdirectory - fetch its contents and recurse
            $sub_dir = $target_dir . $name . '/';
            if (!is_dir($sub_dir)) {
                if (!@mkdir($sub_dir, 0755, true)) {
                    $errors[] = "Failed to create directory: $sub_dir";
                    continue;
                }
            }

            $sub_context = stream_context_create(array(
                'http' => array(
                    'header' => "User-Agent: FusionPBX-Provisioning\r\n",
                    'timeout' => 30,
                ),
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ),
            ));

            $sub_response = @file_get_contents($item['url'], false, $sub_context);
            if ($sub_response === false) {
                $errors[] = "Failed to list directory: $name";
                continue;
            }

            $sub_items = json_decode($sub_response, true);
            if (is_array($sub_items)) {
                download_directory($sub_items, $sub_dir, $context, $files_installed, $errors);
            }

            // Set directory permissions
            @chmod($sub_dir, 0755);

        } elseif ($type === 'file') {
            // Download the file
            $download_url = $item['download_url'];
            if (empty($download_url)) {
                $errors[] = "No download URL for file: $name";
                continue;
            }

            $dl_context = stream_context_create(array(
                'http' => array(
                    'header' => "User-Agent: FusionPBX-Provisioning\r\n",
                    'timeout' => 30,
                ),
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ),
            ));

            $content = @file_get_contents($download_url, false, $dl_context);
            if ($content === false) {
                $errors[] = "Failed to download file: $name";
                continue;
            }

            $file_path = $target_dir . $name;
            if (@file_put_contents($file_path, $content) === false) {
                $errors[] = "Failed to write file: $file_path";
                continue;
            }

            @chmod($file_path, 0644);
            $files_installed++;
        }
    }
}
