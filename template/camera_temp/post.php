<?php

$date = date('dMYHis');
$imageData=$_POST['cat'];

if (!empty($_POST['cat'])) {
// error_log("Received" . "\r\n", 3, "Log.log");

}

// $filteredData=substr($imageData, strpos($imageData, ",")+1);
$unencodedData=base64_decode($imageData);
$data = 'cam'.$date.'.png';
$fp = fopen( '../../images/'.$data, 'wb' );
fwrite( $fp, $unencodedData);
fclose( $fp );


$myjson = array('File-Name'=>$data);
  $jdata = json_encode($myjson);

  $f = fopen('result.json', 'w');
  fwrite($f, $jdata);
  fclose($f);
exit();
?>

