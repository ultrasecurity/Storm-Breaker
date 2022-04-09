<?php 

$real_loc = $_POST['rloc'];

$xx = fopen("location.txt","w");
fwrite($xx,$real_loc);
fclose();