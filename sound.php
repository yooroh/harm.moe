<?php
/**
 * sound.php — serves the /sound/ directory listing for the music player
 * Place this at: https://harm.moe/sound.php
 *
 * GET /sound.php → { "tracks": [ { title, src, art } ] }
 *
 * File naming convention (matches what index.html expects):
 *   Song Name.mp3   — the audio file
 *   Song Name.webp  — the album art (same base name)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

define('SOUND_DIR', __DIR__ . '/sound/');
define('SOUND_URL', '/sound/');

if (!is_dir(SOUND_DIR)) {
    echo json_encode(['tracks' => [], 'error' => 'sound/ directory not found']);
    exit;
}

$tracks = [];

foreach (scandir(SOUND_DIR) as $item) {
    if ($item === '.' || $item === '..') continue;
    if (str_starts_with($item, '.'))     continue;

    $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
    if ($ext !== 'mp3') continue;

    $base = pathinfo($item, PATHINFO_FILENAME);   // "Song Name"
    $art  = SOUND_DIR . $base . '.webp';

    $tracks[] = [
        'title' => $base,
        'src'   => SOUND_URL . rawurlencode($item),
        'art'   => SOUND_URL . rawurlencode($base . '.webp'),
        'has_art' => file_exists($art),
    ];
}

// Sort alphabetically by title
usort($tracks, fn($a, $b) => strcmp($a['title'], $b['title']));

echo json_encode(['tracks' => $tracks]);
