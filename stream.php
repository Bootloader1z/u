<?php
/**
 * Stream Handler - Optimized for media streaming
 * Supports: Video, Audio, Images, PDF with range requests
 */

// Disable output buffering
while (ob_get_level()) ob_end_clean();

// Disable compression
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
ini_set('zlib.output_compression', 'Off');
ini_set('max_execution_time', 0);
set_time_limit(0);

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: Range');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$baseDir = './s/';
$filePath = $_GET['file'] ?? '';

// Security validation
$realBase = realpath($baseDir);
$realFile = realpath($filePath);

if (!$realFile || !$realBase || strpos($realFile, $realBase) !== 0) {
    http_response_code(403);
    die('Access denied');
}

if (!file_exists($realFile) || !is_file($realFile)) {
    http_response_code(404);
    die('File not found');
}

$fileSize = filesize($realFile);
$fileName = basename($realFile);
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

// MIME type mapping
$mimeTypes = [
    // Video
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'ogg' => 'video/ogg',
    'mov' => 'video/quicktime',
    'avi' => 'video/x-msvideo',
    'mkv' => 'video/x-matroska',
    'm4v' => 'video/x-m4v',
    // Audio
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'flac' => 'audio/flac',
    'aac' => 'audio/aac',
    'm4a' => 'audio/mp4',
    'wma' => 'audio/x-ms-wma',
    // Images
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'svg' => 'image/svg+xml',
    'bmp' => 'image/bmp',
    'ico' => 'image/x-icon',
    // Documents
    'pdf' => 'application/pdf',
];

$contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

// Common headers
header('Content-Type: ' . $contentType);
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');

// For inline viewing (not download)
$isInline = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'pdf']);
$disposition = $isInline ? 'inline' : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . $fileName . '"');

// Handle range requests (essential for video/audio seeking)
$start = 0;
$end = $fileSize - 1;
$length = $fileSize;

if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
        $start = $matches[1] !== '' ? intval($matches[1]) : 0;
        $end = $matches[2] !== '' ? intval($matches[2]) : $fileSize - 1;
        
        // Validate range
        if ($start > $end || $start >= $fileSize || $end >= $fileSize) {
            http_response_code(416);
            header("Content-Range: bytes */$fileSize");
            exit;
        }
        
        $length = $end - $start + 1;
        
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$fileSize");
    }
} else {
    header('Content-Length: ' . $fileSize);
}

header('Content-Length: ' . $length);

// HEAD request - just headers
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}

// Stream the file
$handle = fopen($realFile, 'rb');

if ($start > 0) {
    fseek($handle, $start);
}

// Buffer size based on file type
$bufferSize = in_array($ext, ['mp4', 'webm', 'mov', 'mkv', 'avi']) 
    ? 2 * 1024 * 1024  // 2MB for video
    : 512 * 1024;       // 512KB for others

$remaining = $length;

while (!feof($handle) && $remaining > 0 && connection_status() === 0) {
    $readSize = min($bufferSize, $remaining);
    echo fread($handle, $readSize);
    $remaining -= $readSize;
    flush();
}

fclose($handle);
