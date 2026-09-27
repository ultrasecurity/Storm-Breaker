from subprocess import getoutput
import json
import os

try:
    import requests
except ImportError:  # pragma: no cover - handled at runtime
    requests = None

from modules import control


def dependency():
    check_php = getoutput("php -v")
    if "not found" in check_php.lower() or "not recognized" in check_php.lower():
        exit("please install php \n command > sudo apt install php")

    try:
        import colorama  # noqa: F401
        import psutil  # noqa: F401
        if requests is None:
            raise ImportError
    except ImportError:
        exit("please install library \n command > python3 -m pip install -r requirements.txt")


def check_started():
    settings_path = "storm-web/Settings.json"
    if not os.path.exists(settings_path):
        raise FileNotFoundError(f"Missing settings file: {settings_path}")

    with open(settings_path, "r") as jsonFile:
        data = json.load(jsonFile)

    if data.get("is_start") is False:
        data["is_start"] = True
        with open(settings_path, "w") as jsonFile:
            json.dump(data, jsonFile)
    elif data.get("is_start") is True:
        control.kill_php_proc()


def check_update():
    if requests is None:
        return

    try:
        response = requests.get(
            "https://raw.githubusercontent.com/ultrasecurity/Storm-Breaker/main/Settings.json",
            timeout=10,
        )
        response.raise_for_status()
        http_json = response.json()
    except Exception:
        return

    settings_path = "storm-web/Settings.json"
    if not os.path.exists(settings_path):
        return

    with open(settings_path, "r") as jsonFile:
        data = json.load(jsonFile)

    if data.get("version") is not None and http_json.get("version") is not None:
        if data["version"] < http_json["version"]:
            exit("Please Update Tool")
