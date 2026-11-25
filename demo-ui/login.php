<?php
define('OR_PUBLIC_PAGE', true);
require __DIR__ . '/lib.php';

$first = !users_load();                       // first run: create the administrator
if (current_user() && !$first) { header('Location: index.php'); exit; }

$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $user = trim($_POST['user'] ?? '');
        $pw = $_POST['password'] ?? '';
        if ($first) {
            if ($pw !== ($_POST['password2'] ?? '')) throw new RuntimeException('The two passwords differ.');
            user_create($user, $pw);
        }
        if ($e = login_try($user, $pw)) throw new RuntimeException($e);
        header('Location: index.php');
        exit;
    } catch (Throwable $e) { $err = $e; }
}

page_head($first ? 'Create the administrator' : 'Sign in', '');
if ($err) show_error($err);
?>
<form method="post" class="form">
  <?= csrf_field() ?>
  <?php if ($first): ?><p>First run: choose the administrator for this demo.</p><?php endif; ?>
  <label>User name <input name="user" required autofocus autocomplete="username"></label>
  <label>Password <input name="password" type="password" required autocomplete="<?= $first ? 'new-password' : 'current-password' ?>"></label>
  <?php if ($first): ?>
    <label>Repeat password <input name="password2" type="password" required autocomplete="new-password"></label>
    <p class="muted">At least 10 characters, with letters and a digit.</p>
  <?php endif; ?>
  <button><?= $first ? 'Create and sign in' : 'Sign in' ?></button>
</form>
<?php page_foot();
