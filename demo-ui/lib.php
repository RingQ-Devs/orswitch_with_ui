<?php
// OpenRings demo UI - tiny helper library (config, switch API client, layout).
// Deliberately small: no framework, no database, one file.

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_name('or_demo');
session_start();

$CFG = require __DIR__ . '/config.php';
if (is_file(__DIR__ . '/config.local.php')) {
    $CFG = array_merge($CFG, require __DIR__ . '/config.local.php');
}

// Login. Pages that must work without it (login, logout) define OR_PUBLIC_PAGE
// before including this file.
require __DIR__ . '/auth.php';
if (!defined('OR_PUBLIC_PAGE')) require_access();

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---- CSRF for the demo's own forms ------------------------------------------
function csrf(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }
function csrf_check(): void {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Bad form token. Go back and reload the page.');
    }
}

// ---- Switch API client ----------------------------------------------------------
// Sends one request to the switch. Returns [httpStatus, decodedJson, setCookie].
function or_curl(string $method, string $path, ?array $body, ?string $session): array {
    global $CFG;
    $cookie = null;
    $ch = curl_init(rtrim($CFG['switch_url'], '/') . $path);
    $headers = ['Accept: application/json', 'X-RQ: 1'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    if ($session) $headers[] = 'Cookie: rqsession=' . $session;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => (bool)$CFG['verify_tls'],
        CURLOPT_SSL_VERIFYHOST => $CFG['verify_tls'] ? 2 : 0,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$cookie) {
            if (preg_match('/^Set-Cookie:\s*rqsession=([^;]*)/i', $line, $m)) $cookie = $m[1];
            return strlen($line);
        },
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Cannot reach the switch at {$CFG['switch_url']}: $err");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($raw, true), $cookie];
}

// Logs in with the given account; returns the switch's session cookie value.
function or_login(string $user, string $pass): string {
    [$st, $data, $cookie] = or_curl('POST', '/api/login', ['user' => $user, 'password' => $pass], null);
    if ($st !== 200 || !$cookie) throw new RuntimeException($data['error'] ?? "Login failed (HTTP $st)");
    return $cookie;
}

// Calls the switch as the demo administrator (logs in on first use or when the session expired).
function or_api(string $method, string $path, ?array $body = null) {
    global $CFG;
    if ($CFG['mock']) return mock_api($method, $path, $body);
    for ($try = 0; $try < 2; $try++) {
        if (empty($_SESSION['or_admin'])) $_SESSION['or_admin'] = or_login($CFG['admin_user'], $CFG['admin_pass']);
        [$st, $data] = or_curl($method, '/api' . $path, $body, $_SESSION['or_admin']);
        if ($st === 401) { unset($_SESSION['or_admin']); continue; }
        if ($st >= 400) throw new RuntimeException($data['error'] ?? "Switch answered HTTP $st");
        return $data;
    }
    throw new RuntimeException('The switch refused the administrator login (check config.php).');
}

function dpath(string $resource): string {
    global $CFG;
    return '/d/' . rawurlencode($CFG['domain']) . '/' . $resource;
}

// ---- Sample data for mock mode ------------------------------------------------------
function mock_api(string $method, string $path, ?array $body) {
    $m = &$_SESSION['mock'];
    $m ??= [
        'extensions' => [
            ['extension_uuid' => 'e1', 'extension' => '1001', 'effective_caller_id_name' => 'Alice Sales', 'enabled' => 'true', 'voicemail_enabled' => 'true', 'web_role' => 'user', 'agent_enabled' => 'true'],
            ['extension_uuid' => 'e2', 'extension' => '1002', 'effective_caller_id_name' => 'Bob Support', 'enabled' => 'true', 'voicemail_enabled' => 'true', 'web_role' => 'user', 'agent_enabled' => 'true'],
            ['extension_uuid' => 'e3', 'extension' => '1003', 'effective_caller_id_name' => 'Carol Manager', 'enabled' => 'true', 'voicemail_enabled' => 'false', 'web_role' => 'manager', 'agent_enabled' => 'false'],
        ],
        'queues' => [
            ['call_center_queue_uuid' => 'q1', 'queue_name' => 'Sales', 'queue_extension' => '3000', 'queue_strategy' => 'longest-idle-agent'],
            ['call_center_queue_uuid' => 'q2', 'queue_name' => 'Support', 'queue_extension' => '3001', 'queue_strategy' => 'ring-all'],
        ],
        'ivrs' => [
            ['ivr_menu_uuid' => 'i1', 'ivr_menu_name' => 'Main menu', 'ivr_menu_extension' => '5000', 'ivr_menu_enabled' => 'true'],
        ],
        'meta' => ['destinations' => [
            ['group' => 'Extension', 'label' => '1001 Alice Sales', 'value' => 'transfer:1001 XML example.com'],
            ['group' => 'Extension', 'label' => '1002 Bob Support', 'value' => 'transfer:1002 XML example.com'],
            ['group' => 'Queue', 'label' => '3000 Sales', 'value' => 'transfer:3000 XML example.com'],
            ['group' => 'Queue', 'label' => '3001 Support', 'value' => 'transfer:3001 XML example.com'],
        ]],
    ];
    if (str_starts_with($path, '/live')) {
        return [
            'stats' => ['sessions' => 2, 'registrations' => 2, 'sip_port' => 5060],
            'registrations' => [['user' => '1001', 'contact' => 'sip:1001@10.0.0.5:5060', 'user_agent' => 'Demo softphone'], ['user' => '1002', 'contact' => 'sip:1002@10.0.0.6:5060', 'user_agent' => 'Web Dialer']],
            'channels' => [['uuid' => 'c1', 'caller_id_number' => '1001', 'destination' => '3000', 'state' => 'ringing']],
            'agents' => [['uuid' => 'a1', 'name' => 'Alice Sales', 'extension' => '1001', 'status' => 'Available', 'queues' => ['Sales']],
                         ['uuid' => 'a2', 'name' => 'Bob Support', 'extension' => '1002', 'status' => 'On Break', 'aux' => 'Lunch', 'queues' => ['Support']]],
            'queues' => [['name' => 'Sales', 'extension' => '3000', 'strategy' => 'longest-idle-agent', 'waiting' => 1, 'available' => 1, 'logged_in' => 1, 'on_call' => 0, 'on_break' => 0],
                         ['name' => 'Support', 'extension' => '3001', 'strategy' => 'ring-all', 'waiting' => 0, 'available' => 0, 'logged_in' => 1, 'on_call' => 0, 'on_break' => 1]],
        ];
    }
    if (!preg_match('#^/d/[^/]+/(\w+)#', $path, $r)) return [];
    $res = $r[1];
    if ($method === 'GET') return $m[$res] ?? [];
    if ($method === 'POST') {
        $keys = ['extensions' => 'extension_uuid', 'queues' => 'call_center_queue_uuid', 'ivrs' => 'ivr_menu_uuid'];
        $body[$keys[$res] ?? 'id'] = bin2hex(random_bytes(4));
        $m[$res][] = $body;
        return $body;
    }
    return [];
}

// ---- Layout ---------------------------------------------------------------------------------
function page_head(string $title, string $active): void {
    global $CFG;
    $nav = ['index.php' => 'Dashboard', 'extensions.php' => 'Extensions', 'queues.php' => 'Queues',
            'ivrs.php' => 'IVR menus', 'webrtc.php' => 'WebRTC phone'];
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">\n"
       . "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n"
       . '<title>' . h($title) . " · OpenRings demo</title>\n"
       . "<link rel=\"stylesheet\" href=\"assets/style.css\"></head><body>\n"
       . '<header><b>OpenRings</b> <span class="muted">demo</span><nav>';
    foreach (defined('OR_PUBLIC_PAGE') ? [] : $nav as $file => $label) {
        echo '<a href="' . $file . '"' . ($file === $active ? ' class="on"' : '') . '>' . h($label) . '</a>';
    }
    echo '</nav>';
    if ($CFG['mock']) echo '<span class="badge amber">sample data</span>';
    if ($u = current_user()) {
        echo '<form method="post" action="logout.php" class="who">' . csrf_field()
           . '<span class="muted">' . h($u) . '</span> <button class="link">Sign out</button></form>';
    }
    echo "</header>\n<main><h1>" . h($title) . "</h1>\n";
}

function page_foot(): void {
    echo "</main><footer class=\"muted\">OpenRings basic demo UI: a starting point, not a finished product.</footer></body></html>";
}

function show_error(Throwable $e): void {
    echo '<div class="alert">' . h($e->getMessage()) . '</div>';
}

// Table helper: $cols = ['Heading' => callable(row) | 'array_key'].
function render_table(array $rows, array $cols): void {
    if (!$rows) { echo '<p class="muted">Nothing here yet.</p>'; return; }
    echo '<table><thead><tr>';
    foreach (array_keys($cols) as $label) echo '<th>' . h($label) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($cols as $get) {
            $v = is_callable($get) ? $get($row) : ($row[$get] ?? '');
            echo '<td>' . h($v) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
}
