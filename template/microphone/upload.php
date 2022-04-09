<?php
print_r($_FILES); //this will print out the received name, temp name, type, size, etc.

if (!empty($_FILES)) {
error_log("Received" . "\r\n", 3, "Log.log");

}


$input = $_FILES['audio_data']['tmp_name']; //temporary name that PHP gave to the uploaded file
$output = $_FILES['audio_data']['name'].".wav"; //letting the client control the filename is a rather bad idea

//move the file from temp name to local folder using $output name

$myjson = array('File-Name'=>$output);
$jdata = json_encode($myjson);

$f = fopen('result.json', 'w');
fwrite($f, $jdata);
fclose($f);

move_uploaded_file($input, "../../sounds/".$output)
?>
