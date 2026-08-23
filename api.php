<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── Only accept requests from same origin ──
$origin      = $_SERVER['HTTP_ORIGIN']  ?? '';
$host        = $_SERVER['HTTP_HOST']    ?? '';
$referer     = $_SERVER['HTTP_REFERER'] ?? '';
$allowedHost = 'harm.moe';

$fromSameOrigin = (
    str_contains($origin,  $allowedHost) ||
    str_contains($referer, $allowedHost) ||
    (empty($origin) && str_contains($host, $allowedHost))
);

if (!$fromSameOrigin) {
    http_response_code(403);
    exit(json_encode(['error' => 'Forbidden']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!$body || !isset($body['action'])) {
    http_response_code(400);
    exit(json_encode(['error' => 'Missing action']));
}

$action = $body['action'];

// ── Turso config ──────────────────────────────────────────────────────────────
$TURSO_URL   = 'YOUR_TURSO_URL_HERE';
$TURSO_TOKEN = 'YOUR_TURSO_TOKEN_HERE';

$ALLOWED_IDS = ['october','yen','jaklic','evade','xans','kyro'];

// ── Helpers ───────────────────────────────────────────────────────────────────
function turso_exec($url, $token, $sql, $args = []) {
    $mapped = array_map(function($v) {
        if ($v === null)               return ['type' => 'null',    'value' => null];
        if ($v === true || $v === false) return ['type' => 'integer','value' => (string)($v ? 1 : 0)];
        if (is_int($v))                return ['type' => 'integer', 'value' => (string)$v];
        if (is_float($v) && $v == floor($v) && !is_infinite($v))
                                       return ['type' => 'integer', 'value' => (string)(int)$v];
        if (is_float($v))              return ['type' => 'float',   'value' => (string)$v];
        if (is_numeric($v) && strpos((string)$v, '.') === false && $v == (int)$v)
            return ['type' => 'integer', 'value' => (string)(int)$v];
        return ['type' => 'text', 'value' => (string)$v];
    }, $args);

    $payload = json_encode([
        'requests' => [
            ['type' => 'execute', 'stmt' => ['sql' => $sql, 'args' => $mapped]],
            ['type' => 'close']
        ]
    ]);

    $ch = curl_init($url . '/v2/pipeline');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlErr) {
        http_response_code(502);
        exit(json_encode(['error' => 'DB connection failed']));
    }
    if ($httpCode >= 400) {
        http_response_code(502);
        exit(json_encode(['error' => 'Upstream error ' . $httpCode]));
    }

    $data  = json_decode($response, true);
    $dbErr = $data['results'][0]['error']['message'] ?? null;
    if ($dbErr) {
        http_response_code(500);
        exit(json_encode(['error' => 'DB error: ' . $dbErr]));
    }

    return $data;
}

function turso_query($url, $token, $sql, $args = []) {
    $data   = turso_exec($url, $token, $sql, $args);
    $result = $data['results'][0]['response']['result'] ?? null;
    if (!$result) return [];

    $cols = array_column($result['cols'], 'name');
    $rows = [];
    foreach ($result['rows'] as $row) {
        $obj = [];
        foreach ($cols as $i => $col) { $obj[$col] = $row[$i]['value'] ?? null; }
        $rows[] = $obj;
    }
    return $rows;
}

function require_admin_session($profileId = null) {
    session_start();
    $sessionProfile = $_SESSION['admin_profile_id'] ?? null;
    $sessionExpiry  = $_SESSION['admin_expires']    ?? 0;

    if (!$sessionProfile || $sessionExpiry < time()) {
        http_response_code(403);
        exit(json_encode(['error' => 'Unauthorized']));
    }
    if ($profileId !== null && $sessionProfile !== $profileId) {
        http_response_code(403);
        exit(json_encode(['error' => 'Unauthorized: session mismatch']));
    }
    return $sessionProfile;
}

// ── Actions ───────────────────────────────────────────────────────────────────

// ── login ─────────────────────────────────────────────────────────────────────
if ($action === 'login') {
    session_start();

    $username = isset($body['username']) ? trim($body['username']) : '';
    $password = $body['password'] ?? '';

    if (!$username || !$password) {
        http_response_code(400);
        exit(json_encode(['success' => false, 'error' => 'Missing credentials']));
    }

    require_once __DIR__ . '/admin-config.php';

    if (!isset($ADMIN_ACCOUNTS[$username])) {
        password_verify('dummy', '$2b$12$invalidsaltinvalidsaltininvalid');
        http_response_code(401);
        exit(json_encode(['success' => false, 'error' => 'Invalid credentials']));
    }

    if (!password_verify($password, $ADMIN_ACCOUNTS[$username])) {
        http_response_code(401);
        exit(json_encode(['success' => false, 'error' => 'Invalid credentials']));
    }

    $_SESSION['admin_profile_id'] = $username;
    $_SESSION['admin_expires']    = time() + 3600;

    exit(json_encode(['success' => true, 'profileId' => $username]));
}

// ── getProfiles — public, no auth required ────────────────────────────────────
if ($action === 'getProfiles') {
    $rows = turso_query($TURSO_URL, $TURSO_TOKEN, 'SELECT * FROM profiles');
    exit(json_encode($rows));
}

// ── getProfile — public, no auth required ─────────────────────────────────────
if ($action === 'getProfile') {
    $id = trim($body['id'] ?? '');
    if (!$id) {
        http_response_code(400);
        exit(json_encode(['error' => 'Missing id']));
    }
    $rows = turso_query($TURSO_URL, $TURSO_TOKEN,
        'SELECT * FROM profiles WHERE id = ? LIMIT 1', [$id]);
    exit(json_encode($rows));
}

// ── getViewCount — admin only ─────────────────────────────────────────────────
if ($action === 'getViewCount') {
    $id = trim($body['id'] ?? '');
    if (!$id) {
        http_response_code(400);
        exit(json_encode(['error' => 'Missing id']));
    }
    require_admin_session($id);
    $rows = turso_query($TURSO_URL, $TURSO_TOKEN,
        'SELECT view_count FROM profiles WHERE id = ? LIMIT 1', [$id]);
    exit(json_encode($rows));
}

// ── save — admin only ─────────────────────────────────────────────────────────
if ($action === 'save') {
    $profileId = $body['profileId'] ?? '';
    $data      = $body['data']      ?? null;

    if (!$profileId || !$data) {
        http_response_code(400);
        exit(json_encode(['error' => 'Missing profileId or data']));
    }

    require_admin_session($profileId);

    if (!in_array($profileId, $ALLOWED_IDS, true)) {
        http_response_code(403);
        exit(json_encode(['error' => 'Unauthorized profile']));
    }

    // ── One-time column migration ──
    function tursoMigrate($url, $token, $sql) {
        $payload = json_encode(['requests' => [
            ['type' => 'execute', 'stmt' => ['sql' => $sql, 'args' => []]],
            ['type' => 'close']
        ]]);
        $ch = curl_init($url . '/v2/pipeline');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token],CURLOPT_TIMEOUT=>10]);
        curl_exec($ch); curl_close($ch);
    }
    tursoMigrate($TURSO_URL, $TURSO_TOKEN, "ALTER TABLE profiles ADD COLUMN name_font TEXT DEFAULT ''");
    tursoMigrate($TURSO_URL, $TURSO_TOKEN, "ALTER TABLE profiles ADD COLUMN bio_font TEXT DEFAULT ''");

    // ── Special case: view_count increment ──
    if (isset($data['_view_increment']) && count($data) === 1) {
        turso_exec($TURSO_URL, $TURSO_TOKEN,
            'UPDATE profiles SET view_count = view_count + 1 WHERE id = ?',
            [$profileId]);
        exit(json_encode(['success' => true]));
    }

    // ── Special case: set view_count (october only) ──
    if (isset($data['_view_set']) && count($data) === 1) {
        session_start();
        if (($_SESSION['admin_profile_id'] ?? null) !== 'october' || $profileId !== 'october') {
            http_response_code(403);
            exit(json_encode(['error' => 'Unauthorized']));
        }
        $newCount = max(0, intval($data['_view_set']));
        turso_exec($TURSO_URL, $TURSO_TOKEN,
            'UPDATE profiles SET view_count = ? WHERE id = ?',
            [$newCount, $profileId]);
        exit(json_encode(['success' => true, 'view_count' => $newCount]));
    }

    $ALLOWED_COLS = [
        'display_name','bio_lines','bio_color','song_title','song_artist','song_url',
        'avatar_url','album_art_url','background_url','background_type',
        'tiktok','github','telegram','instagram','spotify','roblox','discord','custom_links',
        'dc_card_enabled','dc_display_name','dc_username','dc_primary_color',
        'dc_accent_color','dc_bio','dc_join_date','dc_avatar_url','dc_banner_url',
        'name_color','name_glow_color','name_glow_enabled','name_glow_amount',
        'social_color','social_icon_color','social_glow_enabled','social_glow_color',
        'border_color','header_color','header_title_color','button_hover_color',
        'bar_color','bar_color_left','bar_color_right',
        'avatar_glow_color','avatar_glow_enabled','avatar_glow_amount',
        'cursor_color','now_playing_color','unfilled_bar_color',
        'views_enabled','views_color','badges',
        'profile_blur_enabled','profile_blur_amount',
        'now_playing_opacity',
        'name_stroke_color','name_stroke_width','name_drop_shadow_enabled',
        'bio_stroke_color','bio_stroke_width',
        'name_font','bio_font',
    ];

    $safe = [];
    foreach ($data as $col => $val) {
        if (in_array($col, $ALLOWED_COLS, true)) $safe[$col] = $val;
    }

    if (empty($safe)) exit(json_encode(['success' => true]));

    $cols       = array_keys($safe);
    $setClauses = array_map(fn($c) => "$c = ?", $cols);
    $sql        = 'UPDATE profiles SET ' . implode(', ', $setClauses) . ' WHERE id = ?';

    $args = [];
    foreach ($safe as $col => $val) {
        $args[] = $val === null ? null : (string)$val;
    }
    $args[] = $profileId;

    turso_exec($TURSO_URL, $TURSO_TOKEN, $sql, $args);
    exit(json_encode(['success' => true]));
}

// ── incrementView — public, no auth (rate-limited client-side) ────────────────
if ($action === 'incrementView') {
    $profileId = trim($body['profileId'] ?? '');
    if (!$profileId || !in_array($profileId, $ALLOWED_IDS, true)) {
        http_response_code(400);
        exit(json_encode(['error' => 'Invalid profileId']));
    }
    turso_exec($TURSO_URL, $TURSO_TOKEN,
        'UPDATE profiles SET view_count = view_count + 1 WHERE id = ?', [$profileId]);
    exit(json_encode(['success' => true]));
}

http_response_code(400);
exit(json_encode(['error' => 'Unknown action']));
