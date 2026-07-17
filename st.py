from colorama import Back, Fore, Style

from modules import banner, check, control

check.dependency()
check.check_started()
check.check_update()

PORT = 2525

banner.banner()
control.run_php_server(PORT)
try:
    input(" " + Fore.WHITE + Back.RED + "If You Want Exit And Turn Off localhost / press enter or CTRL+C " + Style.RESET_ALL)
except KeyboardInterrupt:
    pass
finally:
    control.kill_php_proc()