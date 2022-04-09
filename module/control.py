import json,subprocess

def kill_php_proc():
    with open("Settings.json", "r") as jsonFile:
        data = json.load(jsonFile)
        pid = data["pid"]

    try:
        for i in pid:
            subprocess.getoutput(f"kill -9 {i}")


        
        else:
            pid.clear()
            data["pid"] = []
            with open("Settings.json", "w") as jsonFile:
                json.dump(data, jsonFile)

    except:
        pass

