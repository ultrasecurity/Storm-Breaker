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


KERNEL="$(uname -s | tr '[:upper:]' '[:lower:]')"
if [ "$KERNEL" = "linux" ]; then
    DISTRO="$(grep ^NAME= /etc/os-release | cut -d= -f2 | tr '[:upper:]' '[:lower:]')"
    if [ "$DISTRO" = "gentoo" ]; then
        emerge --sync
        emerge -av dev-lang/php dev-python/pip app-misc/neofetch
    fi
fi
