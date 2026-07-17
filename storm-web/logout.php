<?php
session_start();
include "./assets/components/login-arc.php";

log_out();

header("location: login.php");
exit;

?>
