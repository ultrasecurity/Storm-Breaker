import subprocess
import requests,json
from modules import control
import os

def dependency():
    check_php = subprocess.getoutput("php -v")
    if "not found" in check_php:
        exit("please install php \n command > sudo apt install php")

    try:
        from colorama import Fore,Style
        import requests,psutil

    except ImportError:
        exit("please install library \n command > python3 -m pip install -r requirements.txt")


def check_started():
    with open("storm-web/Settings.json", "r") as jsonFile:
        data = json.load(jsonFile)

    if data["is_start"] == False:
        data["is_start"] = True
        with open("storm-web/Settings.json", "w") as jsonFile:
            json.dump(data, jsonFile)



    elif data["is_start"] == True:
        control.kill_php_proc()


def git_update():
    check_git = subprocess.getoutput("git -v")
    if "not found" in check_git:
        exit("Please install git")

    path = "./.git"
    isExist = os.path.exists(path)
    if isExist == False:
        exit("For the automatic update, download repository with git")

    # Get the latest commit hash. necessary for the reset
    git_head = subprocess.getoutput("git rev-parse HEAD")
    confirm = input("This will reset all uncommitted changes with git and it will sync with the official repository.\nConfirm? [N/y]: ")
    if confirm == 'Y' or confirm == 'y':

        # Reset at runtime is required. The storm-web/Settings.json file changes the value of "is_start" from false to true.
        # This causes a conflict and we simply won't be able to update for this automatic change.
        result = subprocess.Popen(["git reset --hard", str(git_head)], shell = True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        result.wait()
        err=result.returncode

        if err != 0:
            exit("Some error with reset")

        result = subprocess.Popen("git fetch https://github.com/ultrasecurity/Storm-Breaker", shell = True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        result.wait()
        err=result.returncode

        if err != 0:
            exit("Some error with fetch")

        result = subprocess.Popen("git merge FETCH_HEAD --no-edit", shell = True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        result.wait()
        err=result.returncode

        if err != 0:
            exit("Some error with merge")

        exit("Update done. Start the program again!")

def check_update():
    http = requests.get("https://raw.githubusercontent.com/ultrasecurity/Storm-Breaker/main/Settings.json").text
    
    http_json = json.loads(http)

    with open("storm-web/Settings.json", "r") as jsonFile:

        data = json.load(jsonFile)
        if data['version'] < http_json['version']:
            update = input("Do you want to try to update automatically? [N/y]: ")
            if update == 'Y' or update == 'y':
                git_update()
            else:
                exit("Please Update Tool")
