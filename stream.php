<?php
/**
 * Stream Handler - Optimized for media streaming
 * Supports: Video, Audio, Images, PDF with range requests
 */

/**
 * Percent-encode a relative path while preserving '/' separators.
 * Used to build safe X-Accel-Redirect URIs.
 */
function rawurlencode_path($path) {
    return implode('/', array_map('rawurlencode', explode('/', str_replace('\\', '/', $path))));
}

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

// Security: validate path is within base_dir
$realBase = realpath($baseDir);
$realFile = realpath($filePath);

// Require the resolved path to live strictly inside $realBase to
// block edge cases like /var/www/html/s vs /var/www/html/s2/.
$baseWithSep = $realBase !== false
    ? rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    : '';
if (!$realFile || !$realBase || strpos($realFile, $baseWithSep) !== 0) {
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
    // Images - Common raster
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'jpe' => 'image/jpeg',
    'jif' => 'image/jpeg',
    'jfif' => 'image/jpeg',
    'jfi' => 'image/jpeg',
    'png' => 'image/png',
    'apng' => 'image/apng',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'bmp' => 'image/bmp',
    'dib' => 'image/bmp',
    'ico' => 'image/x-icon',
    'cur' => 'image/x-icon',
    'tif' => 'image/tiff',
    'tiff' => 'image/tiff',
    // Images - Modern / next-gen
    'avif' => 'image/avif',
    'avifs' => 'image/avif-sequence',
    'jxl' => 'image/jxl',
    'jp2' => 'image/jp2',
    'j2k' => 'image/jp2',
    'jpf' => 'image/jpx',
    'jpx' => 'image/jpx',
    'jpm' => 'image/jpm',
    'jpg2' => 'image/jp2',
    // Images - Mobile (iOS / Android)
    'heic' => 'image/heic',
    'heif' => 'image/heif',
    'heics' => 'image/heic-sequence',
    'heifs' => 'image/heif-sequence',
    'hif' => 'image/heif',
    // Images - Vector
    'svg' => 'image/svg+xml',
    'svgz' => 'image/svg+xml',
    // Images - Windows
    'wmf' => 'image/wmf',
    'emf' => 'image/emf',
    'emz' => 'image/emf',
    'dds' => 'image/vnd.ms-dds',
    'jxr' => 'image/jxr',
    'hdp' => 'image/jxr',
    'wdp' => 'image/jxr',
    // Images - macOS
    'icns' => 'image/icns',
    'pict' => 'image/x-pict',
    'pct' => 'image/x-pict',
    // Images - Linux / open formats
    'xpm' => 'image/x-xpixmap',
    'xbm' => 'image/x-xbitmap',
    'pbm' => 'image/x-portable-bitmap',
    'pgm' => 'image/x-portable-graymap',
    'ppm' => 'image/x-portable-pixmap',
    'pnm' => 'image/x-portable-anymap',
    'pam' => 'image/x-portable-arbitrarymap',
    'xcf' => 'image/x-xcf',
    // Images - HDR / graphics / legacy
    'tga' => 'image/x-tga',
    'targa' => 'image/x-tga',
    'exr' => 'image/x-exr',
    'hdr' => 'image/vnd.radiance',
    'pic' => 'image/x-pic',
    'pcx' => 'image/x-pcx',
    'djvu' => 'image/vnd.djvu',
    'djv' => 'image/vnd.djvu',
    'mng' => 'image/x-mng',
    'jng' => 'image/x-jng',
    'qoi' => 'image/qoi',
    'ktx' => 'image/ktx',
    'ktx2' => 'image/ktx2',
    'astc' => 'image/astc',
    // Images - Editor native
    'psd' => 'image/vnd.adobe.photoshop',
    'psb' => 'image/vnd.adobe.photoshop',
    'ai' => 'application/postscript',
    'eps' => 'application/postscript',
    'epsf' => 'application/postscript',
    'epsi' => 'application/postscript',
    // Images - Camera RAW
    'raw' => 'image/x-raw',
    'dng' => 'image/x-adobe-dng',
    'arw' => 'image/x-sony-arw',
    'sr2' => 'image/x-sony-sr2',
    'srf' => 'image/x-sony-srf',
    'cr2' => 'image/x-canon-cr2',
    'cr3' => 'image/x-canon-cr3',
    'crw' => 'image/x-canon-crw',
    'nef' => 'image/x-nikon-nef',
    'nrw' => 'image/x-nikon-nrw',
    'orf' => 'image/x-olympus-orf',
    'rw2' => 'image/x-panasonic-rw2',
    'raf' => 'image/x-fuji-raf',
    'pef' => 'image/x-pentax-pef',
    'srw' => 'image/x-samsung-srw',
    '3fr' => 'image/x-hasselblad-3fr',
    'erf' => 'image/x-epson-erf',
    'kdc' => 'image/x-kodak-kdc',
    'dcr' => 'image/x-kodak-dcr',
    'mos' => 'image/x-leaf-mos',
    'mrw' => 'image/x-minolta-mrw',
    'rwl' => 'image/x-leica-rwl',
    'x3f' => 'image/x-sigma-x3f',
    'iiq' => 'image/x-phaseone-iiq',
    'mef' => 'image/x-mamiya-mef',
    'mdc' => 'image/x-minolta-mdc',
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
$isInline = in_array($ext, [
    // Browser-renderable images
    'jpg', 'jpeg', 'jpe', 'jif', 'jfif', 'jfi',
    'png', 'apng', 'gif', 'webp', 'bmp', 'dib',
    'ico', 'cur', 'svg', 'svgz', 'avif', 'avifs', 'jxl',
    // Best-effort inline (browser support varies; Safari handles HEIC)
    'heic', 'heif', 'heics', 'heifs', 'hif',
    'tif', 'tiff', 'jp2', 'j2k', 'jpf', 'jpx', 'jpm', 'jpg2',
    'jxr', 'hdp', 'wdp',
    // Documents
    'pdf'
]);
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

// -----------------------------------------------------------------
// Accelerated delivery: when running behind nginx, hand the file off
// via X-Accel-Redirect so the kernel (sendfile + range) streams it.
// Falls through to the manual loop under Apache or any server that
// doesn't honour the header.
// -----------------------------------------------------------------
$useAccel = ($_SERVER['SERVER_SOFTWARE'] ?? '') !== ''
    && stripos($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false;

if ($useAccel) {
    // Resolve the file path relative to the /s/ base dir, then emit the
    // internal redirect. nginx sets Content-Length / Content-Range for us
    // and handles Range requests natively.
    $relative = ltrim(substr($realFile, strlen($realBase)), DIRECTORY_SEPARATOR . '/');
    // Clear the Content-Length we just set; nginx will recompute.
    header_remove('Content-Length');
    header('X-Accel-Redirect: /__send/' . rawurlencode_path($relative));
    exit;
}

// Stream the file (fallback path)
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
