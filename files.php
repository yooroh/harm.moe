<?php
/**
 * files.php — serves the /files/ directory listing + view counts
 * Place this at: https://harm.moe/files.php
 *
 * GET  /files.php           → { "files": [ { name, url, views, size } ] }
 * POST /files.php?action=view&file=Name.ext  → increments view count
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// ---------- config ----------
define('FILES_DIR',    __DIR__ . '/files/');   // physical path to /files/ folder
define('FILES_URL',    '/files/');             // public URL prefix
define('VIEWS_FILE',   __DIR__ . '/file_views.json');  // persisted view counts

// Extensions to show (add/remove as needed)
$ALLOWED_EXT = ['mp4','webm','mov','avi','mkv',
                 'jpg','jpeg','png','gif','webp','bmp','avif',
                 'mp3','wav','ogg','flac','aac','m4a',
                 'pdf','doc','docx','txt','md',
                 'zip','rar','7z'];

// ---------- helpers ----------
function loadViews(): array {
    if (!file_exists(VIEWS_FILE)) return [];
    $data = json_decode(file_get_contents(VIEWS_FILE), true);
    return is_array($data) ? $data : [];
}

function saveViews(array $views): void {
    file_put_contents(VIEWS_FILE, json_encode($views), LOCK_EX);
}

function humanSize(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576,    1) . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024,       1) . ' KB';
    return $bytes . ' B';
}

// ---------- handle POST (increment view) ----------
$action = $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'view') {
    $file   = basename($_GET['file'] ?? '');
    if ($file) {
        $views        = loadViews();
        $views[$file] = ($views[$file] ?? 0) + 1;
        saveViews($views);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ---------- handle GET (list files) ----------
global $ALLOWED_EXT;

if (!is_dir(FILES_DIR)) {
    echo json_encode(['files' => [], 'error' => 'files/ directory not found']);
    exit;
}

$views = loadViews();
$files = [];

$items = scandir(FILES_DIR);
foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;
    if (str_starts_with($item, '.'))     continue;   // skip hidden files

    $path = FILES_DIR . $item;
    if (!is_file($path)) continue;

    $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
    if (!in_array($ext, $ALLOWED_EXT)) continue;

    $files[] = [
        'name'  => $item,
        'url'   => FILES_URL . rawurlencode($item),
        'views' => (int)($views[$item] ?? 0),
        'size'  => humanSize(filesize($path)),
        'mtime' => filemtime($path),
    ];
}

// Sort newest first
usort($files, fn($a, $b) => $b['mtime'] - $a['mtime']);

// Strip mtime from output (internal only)
$out = array_map(fn($f) => ['name' => $f['name'], 'url' => $f['url'], 'views' => $f['views'], 'size' => $f['size']], $files);

echo json_encode(['files' => $out]);
