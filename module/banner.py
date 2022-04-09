import os
from colorama import Fore
import time
from subprocess import Popen
def banner():
    os.system("clear")
    print("")
    Popen('neofetch')
    time.sleep(2)


def show_menu():
    time.sleep(0.1)
    print(Fore.RED+" ["+Fore.WHITE+"*"+Fore.RED+"]"+Fore.CYAN+" Choose one of the options below \n")
    time.sleep(0.1)
    print(Fore.RED+" [0]"+Fore.WHITE+" Get Normal Data "+Fore.YELLOW+"[Without Any Permissions]\n")
    time.sleep(0.1)
    print(Fore.RED+" [1]"+Fore.WHITE+" Get Location "+Fore.GREEN+"[SMARTPHONES]\n")
    time.sleep(0.1)
    print(Fore.RED+" [2]"+Fore.WHITE+" Access Webcam\n") 
    time.sleep(0.1)
    print(Fore.RED+" [3]"+Fore.WHITE+" Access Microphone \n")
    time.sleep(0.1)
    print(Fore.RED+" [4]"+Fore.WHITE+" Exit . . .\n")
