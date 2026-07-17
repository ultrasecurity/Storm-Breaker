<?php
session_start();
include "config.php";
include "./assets/components/login-arc.php";

if (has_valid_session()) {
    if (!isset($_SESSION['IAm-logined'])) {
        $_SESSION['IAm-logined'] = 'yes';
    }
    header("location: panel.php");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['username']) && isset($_POST['password'])) {
        $username = $_POST['username'];
        $password = $_POST['password'];

        if (isset($CONFIG[$username]) && password_verify($password, $CONFIG[$username]['password_hash'])) {
            $_SESSION['IAm-logined'] = $username;
            header("location: panel.php");
            exit;
        }

        $error = "Username or password is incorrect!";
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Storm Breaker</title>
    <link href="./assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="./assets/css/app.css">
</head>

<body>
  <div class="auth-page">
    <form class="card auth-card" action="" method="POST">
        <div class="auth-brand">
            <span class="auth-logo">&#127786;&#65039;</span>
            <h1>Storm Breaker</h1>
            <p>Sign in to the admin panel</p>
        </div>

        <?php if ($error): ?>
            <div class="auth-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <input type="text" class="form-control" name="username" placeholder="Username" required autofocus />
        <input type="password" class="form-control" name="password" placeholder="Password" required />
        <button class="btn btn-primary btn-block" type="submit">Login</button>
    </form>
  </div>
</body>
</html>
