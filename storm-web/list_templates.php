<?php

session_start();
include "./assets/components/login-arc.php";

if (!has_valid_session()) {
    http_response_code(403);
    exit;
}

$templates = array_values(array_diff(scandir("./templates"), array('.', '..')));

echo json_encode($templates);

?>
