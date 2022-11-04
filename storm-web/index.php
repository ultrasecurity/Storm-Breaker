<?php

session_start();

$key = json_decode(file_get_contents("check-c.json"),true);

if(isset($_COOKIE['logindata']) && $_COOKIE['logindata'] == $key['token'] && $key['expired'] == "no"){
	header("location: panel.php");

    
}

elseif(isset($_SESSION['IAm-logined'])){
	header('location: panel.php');
	exit;
}

else{
	header("location: login.php");
	exit;
}

?>