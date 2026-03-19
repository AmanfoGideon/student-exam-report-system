<?php
// Simple secure thumbnail generator
// Usage: thumbnail.php?f=student.jpg OR f=D:\... or f=/uploads/student.jpg&w=96&h=96
declare(strict_types=1);

$projectRoot = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
$uploadsDir = realpath(__DIR__ . '/../../uploads') ?: ($projectRoot . '/uploads');
$thumbsDir = $uploadsDir . DIRECTORY_SEPARATOR . 'thumbs';
if (!is_dir($thumbsDir)) @mkdir($thumbsDir, 0755, true);

$requested = $_GET['f'] ?? '';
$width = max(32, intval($_GET['w'] ?? 96));
$height = max(32, intval($_GET['h'] ?? 96));

if ($requested === '' || $width <= 0 || $height <= 0) {
    http_response_code(400); echo 'Invalid parameters'; exit;
}

if (strpos($requested, '..') !== false) {
    http_response_code(400); echo 'Invalid filename'; exit;
}

// Try direct absolute path first (if provided)
$possibleAbs = $requested;
// handle urlencoded windows paths that may have backslashes
$possibleAbs = str_replace(['%5C','%2F'], ['\\','/'], $possibleAbs);

$sourcePath = '';
if (preg_match('~^[A-Za-z]:[\\\\/]~', $possibleAbs) || str_starts_with($possibleAbs, '/')) {
    $real = realpath($possibleAbs);
    if ($real && file_exists($real)) {
        // Security: only allow files inside project root
        if (str_starts_with(str_replace('\\','/',$real), str_replace('\\','/',$projectRoot))) {
            $sourcePath = $real;
        } else {
            http_response_code(403); echo 'Forbidden'; exit;
        }
    }
}

// If we don't have absolute source, treat input as filename or web path under uploads
if ($sourcePath === '') {
    $basename = basename($requested);
    $candidate = $uploadsDir . DIRECTORY_SEPARATOR . $basename;
    if (file_exists($candidate)) {
        $sourcePath = $candidate;
    } else {
        // try if requested started with /uploads/...
        if (str_starts_with($requested, '/')) {
            $maybe = realpath($projectRoot . DIRECTORY_SEPARATOR . ltrim($requested, '/'));
            if ($maybe && file_exists($maybe)) $sourcePath = $maybe;
        }
    }
}

// fallback to default image if still not found
if ($sourcePath === '' || !file_exists($sourcePath)) {
    $default = realpath(__DIR__ . '/../../assets/images/default_user.png');
    if ($default && file_exists($default)) $sourcePath = $default;
    else { http_response_code(404); echo 'Source not found'; exit; }
}

// Build cache key
$mtime = filemtime($sourcePath) ?: time();
$cacheName = preg_replace('/[^A-Za-z0-9_.-]/', '_', pathinfo($sourcePath, PATHINFO_FILENAME))
    . "_w{$width}h{$height}_m{$mtime}.jpg";
$cachePath = $thumbsDir . DIRECTORY_SEPARATOR . $cacheName;

if (file_exists($cachePath)) {
    $etag = '"' . md5_file($cachePath) . '"';
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) { http_response_code(304); exit; }
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    readfile($cachePath); exit;
}

$info = @getimagesize($sourcePath);
if (!$info) { http_response_code(415); exit('Unsupported image'); }
list($srcW, $srcH) = $info;
$mime = $info['mime'] ?? 'image/jpeg';
switch ($mime) {
    case 'image/jpeg': $srcImg = imagecreatefromjpeg($sourcePath); break;
    case 'image/png':  $srcImg = imagecreatefrompng($sourcePath); break;
    case 'image/gif':  $srcImg = imagecreatefromgif($sourcePath); break;
    default: $srcImg = false;
}
if (!$srcImg) { http_response_code(415); exit('Unsupported image type'); }

// Resize preserving aspect and center-crop
$ratio = max($width / $srcW, $height / $srcH);
$targetW = (int)max(1, floor($srcW * $ratio));
$targetH = (int)max(1, floor($srcH * $ratio));

$tmp = imagecreatetruecolor($targetW, $targetH);
imagefill($tmp, 0, 0, imagecolorallocate($tmp, 255, 255, 255));
if ($mime === 'image/png') { imagealphablending($tmp, false); imagesavealpha($tmp, true); }

imagecopyresampled($tmp, $srcImg, 0, 0, 0, 0, $targetW, $targetH, $srcW, $srcH);
$thumb = imagecreatetruecolor($width, $height);
imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
$cropX = max(0, intval(($targetW - $width) / 2));
$cropY = max(0, intval(($targetH - $height) / 2));
imagecopy($thumb, $tmp, 0, 0, $cropX, $cropY, $width, $height);

// Save optimized JPEG
imagejpeg($thumb, $cachePath, 86);
imagedestroy($srcImg); imagedestroy($tmp); imagedestroy($thumb);

$etag = '"' . md5_file($cachePath) . '"';
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
readfile($cachePath);
exit;
