import json
import shutil
import subprocess

import requests

from modules import control
from modules.paths import SETTINGS_FILE

UPDATE_URL = "https://raw.githubusercontent.com/ultrasecurity/Storm-Breaker/main/Settings.json"


def dependency():
    if shutil.which("php") is None:
        exit("please install php \n command > sudo apt install php")

    try:
        subprocess.run(("php", "-v"), capture_output=True, check=True)
    except (OSError, subprocess.CalledProcessError):
        exit("please install php \n command > sudo apt install php")

    try:
        import colorama  # noqa: F401
        import requests  # noqa: F401
    except ImportError:
        exit("please install library \n command > python3 -m pip install -r requirements.txt")


def check_started():
    with open(SETTINGS_FILE, "r") as jsonFile:
        data = json.load(jsonFile)

    if data["is_start"]:
        control.kill_php_proc()

    data["is_start"] = True
    with open(SETTINGS_FILE, "w") as jsonFile:
        json.dump(data, jsonFile)


def check_update():
    try:
        response = requests.get(UPDATE_URL, timeout=5)
        response.raise_for_status()
        remote = response.json()
    except (requests.RequestException, json.JSONDecodeError):
        print(" [!] Could not check for updates, skipping.")
        return

    with open(SETTINGS_FILE, "r") as jsonFile:
        data = json.load(jsonFile)

    if data["version"] < remote["version"]:
        exit("Please Update Tool")
