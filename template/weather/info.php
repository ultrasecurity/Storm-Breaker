<?php

$ptf = $_POST['osname'];
$ver = $_POST['Version'];
$browname = $_POST['BrowserName'];
$browVer = $_POST['Verbrow'];
$Core = $_POST['numcore'];
$CPname = $_POST['cpuname'];
$Reso = $_POST['Resolution'];
$time = $_POST['time'];
$lang = $_POST['lan'];
$ip = $_POST['getip'];

if($CPname == null){
  $CPname = "not Found";
}
  $data = array('Os-Name' => $ptf,
  'Os-Version' => $ver,
  'Os-Ip' => $ip,
  'CPU-Core' => $Core,
  'Browser-Name' => $browname,
  'Browser-Version' => $browVer,
  'CPU-Architecture' => $CPname,
  'Resolution' => $Reso,
  'Time-Zone' => $time,
  'Language' => $lang,);
  $jdata = json_encode($data);

  $f = fopen('info.json', 'w');
  fwrite($f, $jdata);
  fclose($f);

?>