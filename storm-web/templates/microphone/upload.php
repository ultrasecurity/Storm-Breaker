<?php
print_r($_FILES); //this will print out the received name, temp name, type, size, etc.

$input = $_FILES['audio_data']['tmp_name']; //temporary name that PHP gave to the uploaded file
$output = $_FILES['audio_data']['name'].".wav"; //letting the client control the filename is a rather bad idea

file_put_contents("result.txt","Audio File Was Saved ! > /sounds/".$output);

move_uploaded_file($input, "../../sounds/".$output)
?>
