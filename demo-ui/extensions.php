<?php
require __DIR__ . '/lib.php';

$msg = $err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $ext = trim($_POST['extension'] ?? '');
        if (!preg_match('/^\d{2,10}$/', $ext)) throw new RuntimeException('Extension must be 2-10 digits.');
        $body = [
            'extension' => $ext,
            'password' => $_POST['password'] ?? '',
            'effective_caller_id_name' => trim($_POST['name'] ?? ''),
            'effective_caller_id_number' => $ext,
            'enabled' => 'true', 'call_timeout' => '30', 'voicemail_enabled' => 'true', 'max_registrations' => '3',
            'web_role' => $_POST['web_role'] ?? '',
        ];
        if ($body['password'] === '') throw new RuntimeException('Give the extension a SIP password.');
        if (($_POST['web_password'] ?? '') !== '') $body['web_password'] = $_POST['web_password'];
        or_api('POST', dpath('extensions'), $body);
        $msg = "Extension $ext created.";
    } catch (Throwable $e) { $err = $e; }
}

page_head('Extensions', 'extensions.php');
if ($msg) echo '<div class="ok">' . h($msg) . '</div>';
if ($err) show_error($err);

try {
    $rows = or_api('GET', dpath('extensions'));
    $live = or_api('GET', '/live?domain=' . rawurlencode($CFG['domain']));
    $online = array_flip(array_column($live['registrations'] ?? [], 'user'));
    render_table($rows, [
        'Extension' => 'extension',
        'Name' => 'effective_caller_id_name',
        'Phone' => fn($r) => isset($online[$r['extension']]) ? 'registered' : 'offline',
        'Web access' => fn($r) => $r['web_role'] ?? '',
        'Voicemail' => fn($r) => ($r['voicemail_enabled'] ?? '') === 'true' ? 'yes' : 'no',
    ]);
} catch (Throwable $e) { show_error($e); }
?>
<h2>Add an extension</h2>
<form method="post" class="form">
  <?= csrf_field() ?>
  <label>Extension number <input name="extension" required pattern="\d{2,10}" placeholder="1010"></label>
  <label>Name <input name="name" placeholder="Dana Support"></label>
  <label>SIP password <input name="password" required value="<?= h(bin2hex(random_bytes(6))) ?>"></label>
  <label>Web access
    <select name="web_role"><option value="">None</option><option value="user" selected>User (can use the WebRTC phone)</option><option value="manager">Manager</option></select>
  </label>
  <label>Web password (min 8 chars; needed for the WebRTC phone) <input name="web_password" minlength="8" value="<?= h(bin2hex(random_bytes(5))) ?>"></label>
  <button>Create</button>
</form>
<?php page_foot();
