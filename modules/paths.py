import os

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
WEB_DIR = os.path.join(BASE_DIR, "storm-web")
SETTINGS_FILE = os.path.join(WEB_DIR, "Settings.json")
