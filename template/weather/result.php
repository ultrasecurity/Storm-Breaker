<?php
header('Content-Type: text/html');
{
  $lat = $_POST['Lat'];
  $lon = $_POST['Lon'];
$data = array(
    'lat' => $lat,
    'lon' => $lon,
    'acc' => $acc,
    'alt' => $alt,
    'dir' => $dir,
    'spd' => $spd);

  $jdata = json_encode($data);

  $f = fopen('result.json', 'w+');
  fwrite($f, $jdata);
  fclose($f);
}
?>
