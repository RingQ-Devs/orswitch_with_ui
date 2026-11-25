<?php
require __DIR__ . '/lib.php';

// Sign in / out of the switch as an EXTENSION (the WebRTC phone registers that extension).
// The switch's session cookie is handed to the browser so that its WebSocket
// (proxied through this web server, see apache-proxy.conf) is authenticated.
$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (isset($_POST['logout'])) {
            setcookie('rqsession', '', ['expires' => 1, 'path' => '/']);
        } else {
            if ($CFG['mock']) throw new RuntimeException('WebRTC needs a real switch: set mock to false in config.php.');
            $cookie = or_login(trim($_POST['user'] ?? ''), $_POST['password'] ?? '');
            setcookie('rqsession', $cookie, ['expires' => time() + 12 * 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
            $_SESSION['webrtc_user'] = trim($_POST['user']);
        }
        header('Location: webrtc.php');
        exit;
    } catch (Throwable $e) { $err = $e; }
}

page_head('WebRTC phone', 'webrtc.php');
if ($err) show_error($err);
$signedIn = !empty($_COOKIE['rqsession']);
?>
<?php if (!$signedIn): ?>
  <p>Sign in with an extension that has <b>web access</b> (create one on the Extensions page). Its audio goes
     browser → switch over WebRTC; the switch registers the extension as a normal SIP phone.</p>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Extension <input name="user" required placeholder="1001"></label>
    <label>Web password <input name="password" type="password" required></label>
    <button>Sign in</button>
  </form>
<?php else: ?>
  <div id="phone" class="phone">
    <div class="status"><span id="reg" class="badge">connecting…</span> <span id="media" class="muted"></span></div>
    <input id="number" placeholder="Number to call, e.g. 3000 (queue) or 5000 (IVR)" autocomplete="off">
    <div class="pad">
      <?php foreach (str_split('123456789*0#') as $k) echo '<button type="button" data-k="' . h($k) . '">' . h($k) . '</button>'; ?>
    </div>
    <div class="actions">
      <button id="dial" type="button" class="green">Call</button>
      <button id="answer" type="button" class="green" hidden>Answer</button>
      <button id="hangup" type="button" class="red" hidden>Hang up</button>
      <button id="mute" type="button" hidden>Mute</button>
    </div>
    <div id="call" class="muted"></div>
    <audio id="audio" autoplay></audio>
  </div>
  <form method="post"><?= csrf_field() ?><button name="logout" value="1" class="link">Sign out</button></form>
  <script>window.WS_PATH = <?= json_encode($CFG['ws_path']) ?>;</script>
  <script src="assets/softphone.js"></script>
<?php endif; ?>
<p class="muted">Needs: this page opened as <code>http://localhost/…</code> (microphone access requires localhost or HTTPS),
   the proxy from <code>apache-proxy.conf</code>, and UDP/TCP port 8443 of the switch reachable from the browser.</p>
<?php page_foot();
