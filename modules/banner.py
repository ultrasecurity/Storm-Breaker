from colorama import Fore,Back,Style
import platform,os

OsName = platform.uname()[0]

def banner():
    if OsName == "Windows":
      os.system("cls")
    else:
      os.system("clear")
    print(Fore.LIGHTWHITE_EX+" (               )   (       *                (                     )       (")   
    print(Fore.LIGHTWHITE_EX+" )\ )  *   )  ( /(   )\ )  (  `           (   )\ )       (       ( /(       )\ )")  
    print(Fore.LIGHTWHITE_EX+"(()/(` )  /(  )\()) (()/(  )\))(        ( )\ (()/( (     )\      )\()) (   (()/( " )
    print(Fore.LIGHTWHITE_EX+"/(_))( )(_))((_)\   /(_)_)()\       ___  )((_) /(_)))\ ((((_)(  |((_)\  )\   /(_)) ")
    print(Fore.CYAN+" (_)) (_(_())   ((_) (_))  (_()((_)|___|((_)_ (_)) ((_) )\ _ )\ |_ ((_)((_) (_)) "  )
    print(Fore.CYAN+"/ __||_   _|  / _ \ | _ \ |  \/  |      | _ )| _ \| __|(_)_\(_)| |/ / | __|| _ \ " )
    print(Fore.CYAN+"\__ \  | |   | (_) ||   / | |\/| |      | _ \|   /| _|  / _ \    ' <  | _| |   / " )
    print(Fore.CYAN+"|___/  |_|    \___/ |_|_\ |_|  |_|      |___/|_|_\|___|/_/ \_\  _|\_\ |___||_|_\\")
    print(Style.RESET_ALL)

banner()