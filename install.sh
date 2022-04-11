#!/bin/sh

checkroot() {
    SAVE_LD_PRELOAD="$LD_PRELOAD"
    unset LD_PRELOAD
    if [ "$(id -u)" -ne 0 ]; then
        printf "\e[1;77mPlease, run as root!\n\e[0m"
        exit 1
     fi
     LD_PRELOAD="$SAVE_LD_PRELOAD"
}

checkroot

apt_based() {
    apt-get update
    apt-get install python3 python3-pip php neofetch
    if [ "$?" -ne 0 ]; then
        printf "An error occurred! apt-get seems not works.\n"
        exit 1
    fi
}

KERNEL="$(uname -s | tr '[:upper:]' '[:lower:]')"
if [ "$KERNEL" = "linux" ]; then
    DISTRO="$(grep ^ID= /etc/os-release | cut -d= -f2 | tr '[:upper:]' '[:lower:]')"
    if [ "$DISTRO" = "gentoo" ]; then
        emerge --sync
        emerge -av dev-lang/php dev-python/pip app-misc/neofetch
    elif [ "$DISTRO" = "debian" ]; then
        apt_based
    elif [ "$DISTRO" = "kali" ]; then
        apt_based
    elif [ "$DISTRO" = "ubuntu" ]; then
        apt_based
    elif [ "$DISTRO" = "linuxmint" ]; then
        apt_based
    fi
fi
