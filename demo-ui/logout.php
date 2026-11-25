<?php
define('OR_PUBLIC_PAGE', true);
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $_SESSION = [];
    session_destroy();
    // Also drop the browser's switch session from the WebRTC page, if any.
    setcookie('rqsession', '', ['expires' => 1, 'path' => '/']);
}
header('Location: login.php');
