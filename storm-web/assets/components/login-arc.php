<?php


$key = json_decode(file_get_contents("check-c.json"),true);


function generate_token(){
    $uniqid = md5(uniqid());
	$api_key = implode("-",str_split($uniqid,5));
	return $api_key;

}


function change_token($token){
    $key = json_decode(file_get_contents("check-c.json"),true);

    $key['token'] = $token;
    $key['expired'] = "no";
    file_put_contents("check-c.json",json_encode($key));
}


?>