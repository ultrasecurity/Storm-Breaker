<?php

session_start();
include "./assets/components/login-arc.php";

if (!has_valid_session()) {
    http_response_code(403);
    exit;
}

$TEMPLATES_DIR = "./templates/";
$RESULT_FILE_NAME = "/result.txt";

if (isset($_POST['send_me_result'])) {

    $template_dirs = array_diff(scandir($TEMPLATES_DIR), array('.', '..'));

    foreach ($template_dirs as $dir) {
        $content = file_get_contents($TEMPLATES_DIR . $dir . $RESULT_FILE_NAME);
        if ($content != "") {
            echo $content;
            file_put_contents($TEMPLATES_DIR . $dir . $RESULT_FILE_NAME, ""); //clear result.txt
        }
    }

}

?>
