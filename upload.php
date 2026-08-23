<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── Only accept requests from same origin ──
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$host    = $_SERVER['HTTP_HOST'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$allowedHost = 'harm.moe';

$fromSameOrigin = (
    str_contains($origin, $allowedHost) ||
    str_contains($referer, $allowedHost) ||
    (empty($origin) && str_contains($host, $allowedHost))
);

if (!$fromSameOrigin) {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden']));
}

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

if (empty($_SESSION['admin_profile_id'])) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

$profileId  = $_SESSION['admin_profile_id'];
$allowedIds = ['october', 'yen', 'jaklic', 'xans', 'evade', 'kyro'];

if (!in_array($profileId, $allowedIds, true)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized profile']));
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit(json_encode(['error' => 'No file uploaded']));
}

$field = isset($_POST['field']) ? preg_replace('/[^a-z_]/', '', strtolower($_POST['field'])) : '';
$allowedFields = ['avatar', 'song', 'album', 'background', 'dc_avatar', 'dc_banner', 'badge_icon'];
if (!in_array($field, $allowedFields, true)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid field']));
}

$file     = $_FILES['file'];
$origName = $file['name'];
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

$allowedExts = [
    'avatar'     => ['jpg','jpeg','png','webp','gif'],
    'song'       => ['mp3','ogg','wav','flac','m4a'],
    'album'      => ['jpg','jpeg','png','webp','gif'],
    'background' => ['jpg','jpeg','png','webp','gif','mp4'],
    'dc_avatar'  => ['jpg','jpeg','png','webp','gif'],
    'dc_banner'  => ['jpg','jpeg','png','webp','gif'],
    'badge_icon' => ['jpg','jpeg','png','webp','gif'],
];

if (!in_array($ext, $allowedExts[$field], true)) {
    http_response_code(400);
    exit(json_encode(['error' => 'File type not allowed']));
}

$maxSize = ($ext === 'mp4') ? 50 * 1024 * 1024 : 10 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    http_response_code(400);
    exit(json_encode(['error' => 'File too large']));
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedMimes = [
    'image/jpeg','image/png','image/webp','image/gif',
    'audio/mpeg','audio/ogg','audio/wav','audio/flac','audio/mp4','audio/x-m4a',
    'video/mp4'
];
if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(400);
    exit(json_encode(['error' => 'MIME type not allowed']));
}

$uploadDir = __DIR__ . '/uploads/' . $profileId . '/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if ($field !== 'badge_icon') {
    $glob = glob($uploadDir . $field . '_*');
    if ($glob) {
        foreach ($glob as $old) { @unlink($old); }
    }
}

$filename = $field . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
$dest     = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    exit(json_encode(['error' => 'Failed to save file']));
}

$baseUrl = 'https://' . $_SERVER['HTTP_HOST'];
$url     = $baseUrl . '/uploads/' . $profileId . '/' . $filename;

echo json_encode(['url' => $url]);
