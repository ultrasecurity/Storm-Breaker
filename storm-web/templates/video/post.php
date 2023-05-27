<?php

// Generate a unique filename based on the current date and time
$date = date('dMYHis');
$filename = 'video_'.$date.'.webm';

// Retrieve the video data sent from the client
$videoData = $_POST['cat'];

// Decode the base64-encoded video data
$decodedData = base64_decode($videoData);

// Save the video data to a file on the server
$file = fopen('../../videos/'.$filename, 'wb');
fwrite($file, $decodedData);
fclose($file);

// Output a success message
$response = 'Video file was saved: /videos/'.$filename;
echo $response;
file_put_contents("result.txt","Image File Was Saved ! > /videos/".$data);
// Additional processing or storage logic can be added here

exit();
?>
