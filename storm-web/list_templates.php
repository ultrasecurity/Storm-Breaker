<?php

$files = scandir("./templates");

echo json_encode(array_slice($files,2));



?>