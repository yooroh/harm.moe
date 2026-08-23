<?php
// ── comments.php — Turso DB-backed comment storage ───────────────────────────
// Comments stored in Turso DB (comments table: id, profile, username, message, ip, posted_at)
// Admins can delete and hide comments via DELETE/PATCH endpoints

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Config ────────────────────────────────────────────────────────────────────
$TURSO_URL   = 'YOUR_TURSO_URL_HERE';
$TURSO_TOKEN = 'YOUR_TURSO_TOKEN_HERE';
$ALLOWED_HOST = 'YOUR_DOMAIN_HERE';
$ALLOWED_IDS  = ['october','yen','jaklic','notthegamergod','evade','xans'];

// ── Helpers ───────────────────────────────────────────────────────────────────
function get_ip() {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function turso_exec($url, $token, $sql, $args = []) {
    $mapped = array_map(function($v) {
        if ($v === null)      return ['type' => 'null',    'value' => null];
        if (is_int($v))       return ['type' => 'integer', 'value' => (string)$v];
        if (is_float($v))     return ['type' => 'float',   'value' => (string)$v];
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

    $data = json_decode($response, true);
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

// ── Route ─────────────────────────────────────────────────────────────────────
$method  = $_SERVER['REQUEST_METHOD'];
$profile = preg_replace('/[^a-z0-9]/', '', strtolower($_GET['profile'] ?? ''));

if (!$profile) {
    http_response_code(400);
    echo json_encode(['error' => 'missing profile']);
    exit;
}

// ── GET — fetch comments for a profile ───────────────────────────────────────
if ($method === 'GET') {
    // Admins see hidden comments too; everyone else only sees visible ones
    session_start();
    $isAdmin = (
        isset($_SESSION['admin_profile_id']) &&
        $_SESSION['admin_profile_id'] === $profile &&
        ($_SESSION['admin_expires'] ?? 0) > time()
    );

    if ($isAdmin) {
        $rows = turso_query($TURSO_URL, $TURSO_TOKEN,
            'SELECT id, username, message, posted_at, hidden FROM comments WHERE profile = ? ORDER BY posted_at DESC LIMIT 100',
            [$profile]
        );
    } else {
        $rows = turso_query($TURSO_URL, $TURSO_TOKEN,
            'SELECT id, username, message, posted_at FROM comments WHERE profile = ? AND hidden = 0 ORDER BY posted_at DESC LIMIT 100',
            [$profile]
        );
    }

    echo json_encode(['comments' => $rows]);
    exit;
}

// ── POST — submit a comment ───────────────────────────────────────────────────
if ($method === 'POST') {
    $data     = json_decode(file_get_contents('php://input'), true);
    $username = trim($data['username'] ?? '');
    $message  = trim($data['message']  ?? '');
    $ip       = get_ip();
    $now      = time();

    if (!$username || !$message) {
        http_response_code(400);
        echo json_encode(['error' => 'missing fields']);
        exit;
    }
    if (mb_strlen($username) > 32) {
        http_response_code(400);
        echo json_encode(['error' => 'username too long']);
        exit;
    }
    if (mb_strlen($message) > 500) {
        http_response_code(400);
        echo json_encode(['error' => 'message too long']);
        exit;
    }

    // Rate limit: 1 comment per IP per hour across all profiles
    $hourAgo = $now - 3600;
    $recent  = turso_query($TURSO_URL, $TURSO_TOKEN,
        'SELECT id FROM comments WHERE ip = ? AND posted_at > ? LIMIT 1',
        [$ip, $hourAgo]
    );
    if (count($recent) > 0) {
        http_response_code(429);
        echo json_encode(['error' => 'rate_limited', 'message' => 'You can only post once per hour.']);
        exit;
    }

    // Sanitize
    $username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $message  = htmlspecialchars($message,  ENT_QUOTES, 'UTF-8');

    turso_exec($TURSO_URL, $TURSO_TOKEN,
        'INSERT INTO comments (profile, username, message, ip, posted_at, hidden) VALUES (?, ?, ?, ?, ?, 0)',
        [$profile, $username, $message, $ip, $now]
    );

    echo json_encode(['ok' => true]);
    exit;
}

// ── DELETE — admin deletes a comment permanently ──────────────────────────────
if ($method === 'DELETE') {
    session_start();
    $sessionProfile = $_SESSION['admin_profile_id'] ?? null;
    $sessionExpiry  = $_SESSION['admin_expires']    ?? 0;

    if (!$sessionProfile || $sessionProfile !== $profile || $sessionExpiry < time()) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $body      = json_decode(file_get_contents('php://input'), true);
    $commentId = trim($body['id'] ?? '');

    if (!$commentId) {
        http_response_code(400);
        echo json_encode(['error' => 'missing comment id']);
        exit;
    }

    // Only allow deleting comments on the admin's own profile
    turso_exec($TURSO_URL, $TURSO_TOKEN,
        'DELETE FROM comments WHERE id = ? AND profile = ?',
        [$commentId, $profile]
    );

    echo json_encode(['ok' => true]);
    exit;
}

// ── PATCH — admin toggles hidden on a comment ─────────────────────────────────
if ($method === 'PATCH') {
    session_start();
    $sessionProfile = $_SESSION['admin_profile_id'] ?? null;
    $sessionExpiry  = $_SESSION['admin_expires']    ?? 0;

    if (!$sessionProfile || $sessionProfile !== $profile || $sessionExpiry < time()) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $body      = json_decode(file_get_contents('php://input'), true);
    $commentId = trim($body['id']     ?? '');
    $hidden    = isset($body['hidden']) ? (int)(bool)$body['hidden'] : null;

    if (!$commentId || $hidden === null) {
        http_response_code(400);
        echo json_encode(['error' => 'missing id or hidden']);
        exit;
    }

    turso_exec($TURSO_URL, $TURSO_TOKEN,
        'UPDATE comments SET hidden = ? WHERE id = ? AND profile = ?',
        [$hidden, $commentId, $profile]
    );

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
