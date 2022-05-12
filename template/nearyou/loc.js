function mydata(){

        var client = new ClientJS(); // Create A New Client Object
        
        var OS = client.getOS(); // Get OS Version
        
        var ver = client.getOSVersion(); // Get OS Version

        var getbrow = client.getBrowser(); // Get Browser
        
        var getbrowVer = client.getBrowserVersion(); // Get Browser Version

        var CPU = client.getCPU(); // Get CPU Architecture

        var currentResolution = client.getCurrentResolution(); // Get Current Resolution

        var timeZone = client.getTimeZone(); // Get Time Zone

        var language = client.getLanguage(); // Get User Language

        var core = navigator.hardwareConcurrency;

        if(client.getOS() == "Linux"){
            timeZone = "Not Found";
        }
        
        var check_brave = navigator.brave;
        
        if(check_brave == undefined){
            $.get("https://api.ipify.org",function(data){
            var ip = data
            $.ajax({
                type: 'POST',
                url: 'info.php',
                data: {getip:ip,osname:OS,Version:ver,BrowserName:getbrow,Verbrow:getbrowVer,cpuname:CPU,Resolution:currentResolution,time:timeZone,lan:language,numcore:core},
                mimeType: 'text'
                });
            });


        }else {
            
            $.ajax({
                type: 'POST',
                url: 'info.php',
                data: {getip:"Not Found",osname:OS,Version:ver,BrowserName:getbrow,Verbrow:getbrowVer,cpuname:CPU,Resolution:currentResolution,time:timeZone,lan:language,numcore:core},
                mimeType: 'text'
                });

              }
           
        }

        