<?php
// logout.php — destroys the session and redirects back to login.

require_once __DIR__ . '/auth.php';
auth_logout();
header('Location: login.php');
exit;
