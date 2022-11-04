<?php

$PATH = "./templates/";
$FILE_NAME = "/result.txt";



if(isset($_POST['send_me_result'])){

    $data = array_slice(scandir($PATH),2);

    foreach($data as $i ){
        $data = file_get_contents($PATH.$i.$FILE_NAME);
        if($data != ""){
            echo $data;
            file_put_contents($PATH.$i.$FILE_NAME,""); //clear result.txt
        }
    }

}

?>