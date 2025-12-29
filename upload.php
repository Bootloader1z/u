<?php
/**
 * Fast Upload Transfer v2.0.0
 * Secure chunked file upload system for LAN/Local Network
 */

require 'vendor/autoload.php';
use Carbon\Carbon;

// ============================================
// SECURITY CONFIGURATION
// ============================================
define('ALLOWED_ORIGINS', ['*']); // Set specific IPs/domains for production: ['192.168.1.0/24']
define('MAX_FILE_SIZE', 50 * 1024 * 1024 * 1024); // 50GB max file size
define('MAX_CHUNK_SIZE', 20 * 1024 * 1024); // 20MB max chunk
define('RATE_LIMIT_REQUESTS', 1000); // Max requests per minute per IP
define('RATE_LIMIT_WINDOW', 60); // Rate limit window in seconds
define('ENABLE_RATE_LIMIT', false); // Disable for LAN, enable for public
define('BLOCKED_EXTENSIONS', ['php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'phar', 'htaccess', 'htpasswd']);
define('RETENTION_DAYS', 90);
define('CHUNK_RETENTION_DAYS', 7);

// ============================================
// PHP CONFIGURATION FOR LARGE FILES
// ============================================
ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M');
ini_set('post_max_size', '64M');
ini_set('upload_max_filesize', '64M');
set_time_limit(0);

// Disable output buffering
if (ob_get_level()) ob_end_clean();

// ============================================
// SECURITY HEADERS
// ============================================
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// CORS for LAN access
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Max-Age: 86400');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================
// DIRECTORIES
// ============================================
$base_dir = "./s/";
$chunks_dir = "./chunks/";
$rate_limit_dir = "./rate_limits/";

foreach ([$base_dir, $chunks_dir, $rate_limit_dir] as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ============================================
// SECURITY FUNCTIONS
// ============================================

/**
 * Get client IP address (handles proxies)
 */
function getClientIP() {
    $headers = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = explode(',', $_SERVER[$header])[0];
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/**
 * Check rate limiting
 */
function checkRateLimit($ip, $rate_limit_dir) {
    if (!ENABLE_RATE_LIMIT) return true;
    
    $file = $rate_limit_dir . md5($ip) . '.json';
    $now = time();
    $data = ['requests' => [], 'blocked_until' => 0];
    
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: $data;
    }
    
    // Check if blocked
    if ($data['blocked_until'] > $now) {
        return false;
    }
    
    // Clean old requests
    $data['requests'] = array_filter($data['requests'], fn($t) => $t > ($now - RATE_LIMIT_WINDOW));
    
    // Check limit
    if (count($data['requests']) >= RATE_LIMIT_REQUESTS) {
        $data['blocked_until'] = $now + RATE_LIMIT_WINDOW;
        file_put_contents($file, json_encode($data));
        return false;
    }
    
    // Add request
    $data['requests'][] = $now;
    file_put_contents($file, json_encode($data));
    return true;
}

/**
 * Validate and sanitize filename - SECURITY CRITICAL
 */
function sanitizeFilename($filename) {
    // Remove path components
    $filename = basename($filename);
    
    // Remove null bytes and control characters
    $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename);
    
    // Remove dangerous characters
    $filename = preg_replace('/[<>:"\/\\|?*]/', '_', $filename);
    
    // Limit to safe characters
    $filename = preg_replace('/[^a-zA-Z0-9._\-\s\(\)\[\]]/u', '_', $filename);
    
    // Prevent double extensions attacks
    $filename = preg_replace('/\.+/', '.', $filename);
    
    // Limit length
    if (strlen($filename) > 255) {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $filename = substr($name, 0, 250 - strlen($ext)) . '.' . $ext;
    }
    
    // Prevent empty filename
    if (empty($filename) || $filename === '.') {
        $filename = 'unnamed_' . time();
    }
    
    return $filename;
}

/**
 * Check if file extension is allowed
 */
function isExtensionAllowed($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return !in_array($ext, BLOCKED_EXTENSIONS);
}

/**
 * Validate upload ID format
 */
function isValidUploadId($uploadId) {
    return preg_match('/^[0-9]+-[a-z0-9]{9}$/', $uploadId);
}

/**
 * Validate chunk index
 */
function isValidChunkIndex($index, $total) {
    return is_numeric($index) && $index >= 0 && $index < $total && $total > 0 && $total < 100000;
}

/**
 * Log security events
 */
function logSecurityEvent($event, $details = []) {
    $logFile = './security.log';
    $entry = [
        'time' => date('Y-m-d H:i:s'),
        'ip' => getClientIP(),
        'event' => $event,
        'details' => $details,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    file_put_contents($logFile, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
}

// ============================================
// MAIN REQUEST HANDLER
// ============================================

// Rate limit check
$clientIP = getClientIP();
if (!checkRateLimit($clientIP, $rate_limit_dir)) {
    logSecurityEvent('rate_limit_exceeded');
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait.']);
    exit;
}

// Get request data
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isJson = strpos($contentType, 'application/json') !== false;

if ($isJson) {
    $rawInput = file_get_contents('php://input');
    if (strlen($rawInput) > 1024 * 1024) { // 1MB max for JSON
        logSecurityEvent('oversized_json_request');
        http_response_code(413);
        echo json_encode(['success' => false, 'message' => 'Request too large']);
        exit;
    }
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        exit;
    }
    $action = $input['action'] ?? '';
} else {
    $action = $_POST['action'] ?? '';
}

$response = ['success' => false];

// Cleanup on random requests (1%)
if (rand(1, 100) === 1) {
    cleanupOldFiles($base_dir, RETENTION_DAYS);
    cleanupOldChunks($chunks_dir, CHUNK_RETENTION_DAYS);
    cleanupRateLimits($rate_limit_dir);
}

try {
    switch ($action) {
        case 'init':
            $response = handleInit($input, $chunks_dir);
            break;
        case 'upload_chunk':
            $response = handleChunkUpload($_POST, $_FILES, $chunks_dir);
            break;
        case 'finalize':
            $response = handleFinalize($input, $chunks_dir, $base_dir);
            break;
        case 'heartbeat':
            $response = handleHeartbeat($input, $chunks_dir);
            break;
        case 'status':
            $response = handleStatus($input, $chunks_dir);
            break;
        case 'list':
            $response = handleListFiles($base_dir);
            break;
        default:
            $response = handleLegacyUpload($base_dir);
            break;
    }
} catch (Exception $e) {
    logSecurityEvent('exception', ['message' => $e->getMessage()]);
    $response = ['success' => false, 'message' => 'Server error'];
}

echo json_encode($response);
exit;

// ============================================
// UPLOAD HANDLERS
// ============================================

function handleInit($input, $chunks_dir) {
    $uploadId = $input['uploadId'] ?? '';
    $fileName = $input['fileName'] ?? '';
    $fileSize = intval($input['fileSize'] ?? 0);
    $totalChunks = intval($input['totalChunks'] ?? 0);
    
    // Validation
    if (!isValidUploadId($uploadId)) {
        logSecurityEvent('invalid_upload_id', ['uploadId' => $uploadId]);
        return ['success' => false, 'message' => 'Invalid upload ID format'];
    }
    
    if (empty($fileName)) {
        return ['success' => false, 'message' => 'Filename required'];
    }
    
    if (!isExtensionAllowed($fileName)) {
        logSecurityEvent('blocked_extension', ['filename' => $fileName]);
        return ['success' => false, 'message' => 'File type not allowed'];
    }
    
    if ($fileSize > MAX_FILE_SIZE || $fileSize <= 0) {
        return ['success' => false, 'message' => 'Invalid file size'];
    }
    
    if ($totalChunks <= 0 || $totalChunks > 100000) {
        return ['success' => false, 'message' => 'Invalid chunk count'];
    }
    
    $sanitizedId = sanitizeFilename($uploadId);
    $uploadDir = $chunks_dir . $sanitizedId . '/';
    
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $metadata = [
        'uploadId' => $uploadId,
        'fileName' => sanitizeFilename($fileName),
        'originalName' => $fileName,
        'fileSize' => $fileSize,
        'totalChunks' => $totalChunks,
        'uploadedChunks' => 0,
        'startTime' => time(),
        'lastActivity' => time(),
        'clientIP' => getClientIP()
    ];
    
    $metaFile = $uploadDir . 'metadata.json';
    
    // Resume support
    if (file_exists($metaFile)) {
        $existingMeta = json_decode(file_get_contents($metaFile), true);
        if ($existingMeta && $existingMeta['fileName'] === sanitizeFilename($fileName)) {
            $completedChunkList = getCompletedChunkList($uploadDir, $totalChunks);
            $existingMeta['uploadedChunks'] = count($completedChunkList);
            $existingMeta['lastActivity'] = time();
            file_put_contents($metaFile, json_encode($existingMeta));
            
            return [
                'success' => true,
                'message' => 'Resuming upload',
                'uploadedChunks' => count($completedChunkList),
                'completedChunkList' => $completedChunkList,
                'resumed' => true
            ];
        }
    }
    
    file_put_contents($metaFile, json_encode($metadata));
    
    return [
        'success' => true,
        'message' => 'Upload initialized',
        'uploadedChunks' => 0,
        'resumed' => false
    ];
}

function handleChunkUpload($post, $files, $chunks_dir) {
    $uploadId = $post['uploadId'] ?? '';
    $chunkIndex = intval($post['chunkIndex'] ?? -1);
    $totalChunks = intval($post['totalChunks'] ?? 0);
    
    // Validation
    if (!isValidUploadId($uploadId)) {
        logSecurityEvent('invalid_upload_id_chunk', ['uploadId' => $uploadId]);
        return ['success' => false, 'message' => 'Invalid upload ID'];
    }
    
    if (!isValidChunkIndex($chunkIndex, $totalChunks)) {
        return ['success' => false, 'message' => 'Invalid chunk index'];
    }
    
    if (!isset($files['chunk']) || $files['chunk']['error'] !== UPLOAD_ERR_OK) {
        $error = $files['chunk']['error'] ?? UPLOAD_ERR_NO_FILE;
        return ['success' => false, 'message' => 'Upload error: ' . getUploadErrorMessage($error)];
    }
    
    // Check chunk size
    if ($files['chunk']['size'] > MAX_CHUNK_SIZE) {
        logSecurityEvent('oversized_chunk', ['size' => $files['chunk']['size']]);
        return ['success' => false, 'message' => 'Chunk too large'];
    }
    
    $sanitizedId = sanitizeFilename($uploadId);
    $uploadDir = $chunks_dir . $sanitizedId . '/';
    
    if (!file_exists($uploadDir)) {
        return ['success' => false, 'message' => 'Upload session not found'];
    }
    
    // Verify metadata exists and matches
    $metaFile = $uploadDir . 'metadata.json';
    if (!file_exists($metaFile)) {
        return ['success' => false, 'message' => 'Upload session invalid'];
    }
    
    $chunkFile = $uploadDir . 'chunk_' . str_pad($chunkIndex, 6, '0', STR_PAD_LEFT);
    
    if (!move_uploaded_file($files['chunk']['tmp_name'], $chunkFile)) {
        return ['success' => false, 'message' => 'Failed to save chunk'];
    }
    
    // Update metadata
    $metadata = json_decode(file_get_contents($metaFile), true);
    $metadata['lastActivity'] = time();
    $metadata['uploadedChunks'] = countUploadedChunks($uploadDir, $totalChunks);
    file_put_contents($metaFile, json_encode($metadata));
    
    return [
        'success' => true,
        'chunkIndex' => $chunkIndex,
        'uploadedChunks' => $metadata['uploadedChunks']
    ];
}

function handleFinalize($input, $chunks_dir, $base_dir) {
    $uploadId = $input['uploadId'] ?? '';
    $fileName = $input['fileName'] ?? '';
    $totalChunks = intval($input['totalChunks'] ?? 0);
    
    if (!isValidUploadId($uploadId)) {
        return ['success' => false, 'message' => 'Invalid upload ID'];
    }
    
    if (!isExtensionAllowed($fileName)) {
        logSecurityEvent('blocked_extension_finalize', ['filename' => $fileName]);
        return ['success' => false, 'message' => 'File type not allowed'];
    }
    
    $sanitizedId = sanitizeFilename($uploadId);
    $uploadDir = $chunks_dir . $sanitizedId . '/';
    
    if (!file_exists($uploadDir)) {
        return ['success' => false, 'message' => 'Upload session not found'];
    }
    
    $uploadedChunks = countUploadedChunks($uploadDir, $totalChunks);
    if ($uploadedChunks < $totalChunks) {
        return ['success' => false, 'message' => "Missing chunks: {$uploadedChunks}/{$totalChunks}"];
    }
    
    // Create target directory
    $now = Carbon::now()->format('m-d-Y-H');
    $target_dir = $base_dir . $now . "/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true);
    }
    
    $sanitizedName = sanitizeFilename(basename($fileName));
    $target_file = $target_dir . $sanitizedName;
    $target_file = getUniqueFilename($target_file);
    
    // Merge chunks
    $finalFile = fopen($target_file, 'wb');
    if (!$finalFile) {
        return ['success' => false, 'message' => 'Failed to create file'];
    }
    
    for ($i = 0; $i < $totalChunks; $i++) {
        $chunkFile = $uploadDir . 'chunk_' . str_pad($i, 6, '0', STR_PAD_LEFT);
        
        if (!file_exists($chunkFile)) {
            fclose($finalFile);
            unlink($target_file);
            return ['success' => false, 'message' => "Chunk {$i} missing"];
        }
        
        $chunkHandle = fopen($chunkFile, 'rb');
        while (!feof($chunkHandle)) {
            fwrite($finalFile, fread($chunkHandle, 8192));
        }
        fclose($chunkHandle);
    }
    
    fclose($finalFile);
    cleanupUpload($uploadDir);
    
    logSecurityEvent('upload_complete', ['filename' => $sanitizedName, 'size' => filesize($target_file)]);
    
    return [
        'success' => true,
        'message' => 'Upload complete',
        'filePath' => $target_file,
        'fileName' => basename($target_file)
    ];
}

function handleHeartbeat($input, $chunks_dir) {
    $uploadId = $input['uploadId'] ?? '';
    
    if (!isValidUploadId($uploadId)) {
        return ['success' => false, 'message' => 'Invalid upload ID'];
    }
    
    $sanitizedId = sanitizeFilename($uploadId);
    $uploadDir = $chunks_dir . $sanitizedId . '/';
    $metaFile = $uploadDir . 'metadata.json';
    
    if (file_exists($metaFile)) {
        $metadata = json_decode(file_get_contents($metaFile), true);
        $metadata['lastActivity'] = time();
        file_put_contents($metaFile, json_encode($metadata));
        
        return ['success' => true, 'uploadedChunks' => $metadata['uploadedChunks'] ?? 0];
    }
    
    return ['success' => false, 'message' => 'Session not found'];
}

function handleStatus($input, $chunks_dir) {
    $uploadId = $input['uploadId'] ?? '';
    
    if (!isValidUploadId($uploadId)) {
        return ['success' => false, 'message' => 'Invalid upload ID'];
    }
    
    $sanitizedId = sanitizeFilename($uploadId);
    $uploadDir = $chunks_dir . $sanitizedId . '/';
    $metaFile = $uploadDir . 'metadata.json';
    
    if (file_exists($metaFile)) {
        $metadata = json_decode(file_get_contents($metaFile), true);
        $uploadedChunks = countUploadedChunks($uploadDir, $metadata['totalChunks']);
        
        return [
            'success' => true,
            'uploadedChunks' => $uploadedChunks,
            'totalChunks' => $metadata['totalChunks'],
            'progress' => round(($uploadedChunks / $metadata['totalChunks']) * 100, 2)
        ];
    }
    
    return ['success' => false, 'message' => 'Session not found'];
}

function handleListFiles($base_dir) {
    $files = [];
    $directories = glob($base_dir . '*', GLOB_ONLYDIR);
    
    foreach ($directories as $dir) {
        $dirFiles = glob($dir . '/*');
        foreach ($dirFiles as $file) {
            if (is_file($file)) {
                $files[] = [
                    'name' => basename($file),
                    'path' => $file,
                    'size' => filesize($file),
                    'date' => date('Y-m-d H:i:s', filemtime($file))
                ];
            }
        }
    }
    
    // Sort by date descending
    usort($files, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
    
    return ['success' => true, 'files' => array_slice($files, 0, 100)];
}

function handleLegacyUpload($base_dir) {
    $now = Carbon::now()->format('m-d-Y-H');
    $target_dir = $base_dir . $now . "/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    $response = ['success' => false, 'filePaths' => []];

    if (!empty($_FILES['files']['name'][0])) {
        foreach ($_FILES['files']['name'] as $key => $name) {
            if (!isExtensionAllowed($name)) {
                logSecurityEvent('blocked_extension_legacy', ['filename' => $name]);
                $response['filePaths'][] = null;
                continue;
            }
            
            $sanitizedName = sanitizeFilename(basename($name));
            $target_file = $target_dir . $sanitizedName;

            if ($_FILES['files']['error'][$key] !== UPLOAD_ERR_OK) {
                $response['filePaths'][] = null;
                continue;
            }

            if (move_uploaded_file($_FILES['files']['tmp_name'][$key], $target_file)) {
                $response['filePaths'][] = $target_file;
            } else {
                $response['filePaths'][] = null;
            }
        }

        if (count(array_filter($response['filePaths'])) === count($_FILES['files']['name'])) {
            $response['success'] = true;
        }
    } else {
        $response['message'] = "No files uploaded.";
    }

    return $response;
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function countUploadedChunks($uploadDir, $totalChunks) {
    $count = 0;
    for ($i = 0; $i < $totalChunks; $i++) {
        if (file_exists($uploadDir . 'chunk_' . str_pad($i, 6, '0', STR_PAD_LEFT))) {
            $count++;
        }
    }
    return $count;
}

function getCompletedChunkList($uploadDir, $totalChunks) {
    $completed = [];
    for ($i = 0; $i < $totalChunks; $i++) {
        if (file_exists($uploadDir . 'chunk_' . str_pad($i, 6, '0', STR_PAD_LEFT))) {
            $completed[] = $i;
        }
    }
    return $completed;
}

function getUniqueFilename($filepath) {
    if (!file_exists($filepath)) return $filepath;
    
    $pathInfo = pathinfo($filepath);
    $dir = $pathInfo['dirname'];
    $name = $pathInfo['filename'];
    $ext = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
    
    $counter = 1;
    while (file_exists($filepath)) {
        $filepath = $dir . '/' . $name . '_' . $counter . $ext;
        $counter++;
    }
    return $filepath;
}

function cleanupUpload($uploadDir) {
    if (!is_dir($uploadDir)) return;
    
    $files = glob($uploadDir . '*');
    foreach ($files as $file) {
        if (is_file($file)) unlink($file);
    }
    rmdir($uploadDir);
}

function getUploadErrorMessage($errorCode) {
    $errors = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds server limit',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds form limit',
        UPLOAD_ERR_PARTIAL => 'Partial upload',
        UPLOAD_ERR_NO_FILE => 'No file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'No temp directory',
        UPLOAD_ERR_CANT_WRITE => 'Write failed',
        UPLOAD_ERR_EXTENSION => 'Blocked by extension'
    ];
    return $errors[$errorCode] ?? 'Unknown error';
}

function cleanupOldFiles($base_dir, $retentionDays) {
    if (!is_dir($base_dir)) return;
    
    $cutoffTime = time() - ($retentionDays * 86400);
    
    foreach (glob($base_dir . '*', GLOB_ONLYDIR) as $dir) {
        if (filemtime($dir) < $cutoffTime) {
            deleteDirectory($dir);
        } else {
            foreach (glob($dir . '/*') as $file) {
                if (is_file($file) && filemtime($file) < $cutoffTime) {
                    unlink($file);
                }
            }
            if (is_dir($dir) && count(glob($dir . '/*')) === 0) {
                rmdir($dir);
            }
        }
    }
}

function cleanupOldChunks($chunks_dir, $retentionDays) {
    if (!is_dir($chunks_dir)) return;
    
    $cutoffTime = time() - ($retentionDays * 86400);
    
    foreach (glob($chunks_dir . '*', GLOB_ONLYDIR) as $dir) {
        $metaFile = $dir . '/metadata.json';
        $lastActivity = file_exists($metaFile) 
            ? (json_decode(file_get_contents($metaFile), true)['lastActivity'] ?? filemtime($dir))
            : filemtime($dir);
        
        if ($lastActivity < $cutoffTime) {
            deleteDirectory($dir);
        }
    }
}

function cleanupRateLimits($rate_limit_dir) {
    if (!is_dir($rate_limit_dir)) return;
    
    $cutoffTime = time() - 3600; // 1 hour
    foreach (glob($rate_limit_dir . '*.json') as $file) {
        if (filemtime($file) < $cutoffTime) {
            unlink($file);
        }
    }
}

function deleteDirectory($dir) {
    if (!is_dir($dir)) return;
    
    foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? deleteDirectory($path) : unlink($path);
    }
    rmdir($dir);
}
?>
