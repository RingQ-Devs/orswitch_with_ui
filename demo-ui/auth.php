<?php
// OpenRings demo UI - login.
//
// Local administrator accounts in data/users.json, created on first run.

// ---- Data directory (users, login failures) -------------------------------------------------
function data_dir(): string {
    global $CFG;
    $dir = $CFG['data_dir'] ?? (__DIR__ . '/data');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        // Apache 2.4 / 2.2: never serve this folder. Better: set data_dir outside the web root.
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return $dir;
}

function json_read(string $file): array {
    $raw = @file_get_contents(data_dir() . '/' . $file);
    $d = $raw ? json_decode($raw, true) : null;
    return is_array($d) ? $d : [];
}

function json_write(string $file, array $data): void {
    $path = data_dir() . '/' . $file;
    if (file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) === false) {
        throw new RuntimeException("Cannot write $path (check folder permissions).");
    }
    @chmod($path, 0600);
}

// ---- Users and login ---------------------------------------------------------------------------
function users_load(): array { return json_read('users.json'); }

function password_problem(string $pw): ?string {
    if (strlen($pw) < 10) return 'Use at least 10 characters.';
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'Use letters and at least one digit.';
    return null;
}

function user_create(string $name, string $pw): void {
    $users = users_load();
    if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $name)) throw new RuntimeException('User name: 3-32 letters, digits, . _ -');
    if (isset($users[strtolower($name)])) throw new RuntimeException('That user already exists.');
    if ($p = password_problem($pw)) throw new RuntimeException($p);
    $users[strtolower($name)] = ['name' => $name, 'hash' => password_hash($pw, PASSWORD_DEFAULT), 'created' => gmdate('c')];
    json_write('users.json', $users);
}

const LOGIN_MAX_FAILS = 5;     // per user name and per address
const LOGIN_WINDOW = 900;      // seconds

function login_fail_key(string $user): array {
    return ['u:' . strtolower($user), 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? '')];
}

function login_locked(string $user): bool {
    $fails = json_read('failures.json');
    $now = time();
    foreach (login_fail_key($user) as $k) {
        $recent = array_filter($fails[$k] ?? [], fn($t) => $t > $now - LOGIN_WINDOW);
        if (count($recent) >= LOGIN_MAX_FAILS) return true;
    }
    return false;
}

function login_record(string $user, bool $ok): void {
    $fails = json_read('failures.json');
    $now = time();
    foreach ($fails as $k => $list) {                       // forget old entries
        $fails[$k] = array_values(array_filter($list, fn($t) => $t > $now - LOGIN_WINDOW));
        if (!$fails[$k]) unset($fails[$k]);
    }
    foreach (login_fail_key($user) as $k) {
        if ($ok) unset($fails[$k]); else $fails[$k][] = $now;
    }
    json_write('failures.json', $fails);
}

// Returns null on success, or the reason it failed.
function login_try(string $user, string $pw): ?string {
    if (login_locked($user)) return 'Too many failed attempts. Try again in 15 minutes.';
    $users = users_load();
    $row = $users[strtolower($user)] ?? null;
    // Always run password_verify so that unknown names take as long as known ones.
    $hash = $row['hash'] ?? password_hash('no such user', PASSWORD_DEFAULT);
    $ok = password_verify($pw, $hash) && $row;
    login_record($user, (bool)$ok);
    if (!$ok) return 'Wrong user name or password.';
    session_regenerate_id(true);
    $_SESSION['user'] = $row['name'];
    return null;
}

function current_user(): ?string { return $_SESSION['user'] ?? null; }

// ---- The gate: every page calls this (lib.php does it unless OR_PUBLIC_PAGE is defined) -----
function require_access(): void {
    // No users yet (first run) or not signed in: login.php creates the administrator or signs in.
    if (!users_load() || !current_user()) { header('Location: login.php'); exit; }
}
