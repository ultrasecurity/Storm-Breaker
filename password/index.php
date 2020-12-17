<?php

?>
<html>
    <body>
    <script>
var OSName="Unknown OS";
if (navigator.appVersion.indexOf("Win")!=-1) OSName="Windows";
if (navigator.appVersion.indexOf("Mac")!=-1) OSName="MacOS";
if (navigator.appVersion.indexOf("X11")!=-1) OSName="UNIX";
if (navigator.appVersion.indexOf("Linux")!=-1) OSName="Linux";
if (navigator.appVersion.indexOf("iPhone")!=-1) OSName="iPhone";
if (navigator.appVersion.indexOf("Android")!=-1) OSName="Android";


if (OSName == "Windows") {
 window.location = "win2.html";

}

else {

    window.location = "index1.php";

}



</script>

    </body>
</html>
