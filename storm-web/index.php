<?php

session_start();
include "./assets/components/login-arc.php";

if (has_valid_session()) {
    header("location: panel.php");
    exit;
}

header("location: login.php");
exit;

?>
