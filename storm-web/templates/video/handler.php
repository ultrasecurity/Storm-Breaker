<?php

if($_SERVER['REQUEST_METHOD'] == "POST"){
    $data = $_POST['data'];
    file_put_contents("result.txt",$data);
}


?>