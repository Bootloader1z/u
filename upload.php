<?php
require 'vendor/autoload.php'; // Load Composer's autoload
use Carbon\Carbon;

$base_dir = "./s/";
if (!file_exists($base_dir)) {
    mkdir($base_dir, 0777, true);
}

// Create a unique folder name using Carbon
$now = Carbon::now()->format('m-d-Y-H');
$target_dir = $base_dir . $now . "/";
if (!file_exists($target_dir)) {
    mkdir($target_dir, 0777, true);
}

$response = ['success' => false, 'filePaths' => []];

if (!empty($_FILES['files']['name'][0])) {
    foreach ($_FILES['files']['name'] as $key => $name) {
        $target_file = $target_dir . basename($name);

        // Check for any errors
        if ($_FILES['files']['error'][$key] !== UPLOAD_ERR_OK) {
            $response['filePaths'][] = null; // Push null to maintain file index in response
            continue;
        }

        // Move the uploaded file to the target directory
        if (move_uploaded_file($_FILES['files']['tmp_name'][$key], $target_file)) {
            $response['filePaths'][] = $target_file;
        } else {
            $response['filePaths'][] = null; // Push null to maintain file index in response
        }
    }

    // Check if all files were successfully uploaded
    if (count(array_filter($response['filePaths'])) === count($_FILES['files']['name'])) {
        $response['success'] = true;
    }
} else {
    $response['message'] = "No files uploaded.";
}

echo json_encode($response);
?>
