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

pacman_based() {
    pacman -Sy
    pacman -S python python-pip php neofetch
    if [ "$?" -ne 0 ]; then
        printf "An error occurred! pacman seems not works.\n"
        exit 1
    fi
}

yum_based() {
    yum update -y
    yum install -y python3 python3-pip php neofetch
    if [ "$?" -ne 0 ]; then
        printf "An error occurred! yum seems not works.\n"
        exit 1
    fi
}

KERNEL="$(uname -s | tr '[:upper:]' '[:lower:]')"
if [ "$KERNEL" = "linux" ]; then
    DISTRO="$(grep ^ID= /etc/os-release | cut -d= -f2 | tr '[:upper:]' '[:lower:]' | sed 's/\"//g')"
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
    elif [ "$DISTRO" = "arch" ]; then
        pacman_based
    elif [ "$DISTRO" = "manjaro" ]; then
        pacman_based
    elif [ "$DISTRO" = "arcolinux" ]; then
        pacman_based
    elif [ "$DISTRO" = "garuda" ]; then
        pacman_based
    elif [ "$DISTRO" = "artix" ]; then
        pacman_based
    elif [ "$DISTRO" = "fedora" ]; then
        yum_based
    elif [ "$DISTRO" = "centos" ]; then
        yum_based
    else
        printf "I couldn't detect your linux distribution!\n"
        printf "This tool needs python3, pip, php and neofetch. please install these packages on your os yourself.\n"
        exit 1
    fi

elif [ "$KERNEL" = "freebsd" ]; then
    pkg update
    pkg install python310
    pkg install py310-pip
    pkg install php
    pkg install neofetch
fi
