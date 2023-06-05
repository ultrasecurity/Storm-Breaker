#!/bin/sh


if test -f .ascii; then
    cat .ascii
fi

GRY='\033[1;30m'
RED='\033[0;31m'
BLU='\033[0;34m'
GRN='\033[0;32m'
PUL='\033[0;35m'
RST='\033[0m'

checkroot() {
    SAVE_LD_PRELOAD="$LD_PRELOAD"
    unset LD_PRELOAD
    if [ "$(id -u)" -ne 0 ]; then
        printf "${RED}Please, run as root!\n${RST}"
        exit 1
    fi
    LD_PRELOAD="$SAVE_LD_PRELOAD"
}

apt_based() {
    apt-get update
    apt-get install python3 python3-pip php
    if [ "$?" -ne 0 ]; then
        printf "${RED}An error occurred! seems apt-get doesn't work.\n${RST}"
        exit 1
    fi
}

pacman_based() {
    pacman -Sy
    pacman -S python python-pip php
    if [ "$?" -ne 0 ]; then
        printf "${RED}An error occurred! seems pacman doesn't work.\n${RST}"
        exit 1
    fi
}

yum_based() {
    yum update -y
    yum install -y python3 python3-pip php
    if [ "$?" -ne 0 ]; then
        printf "${RED}An error occurred! seems yum doesn't work.\n${RST}"
        exit 1
    fi
}

checkroot

KERNEL="$(uname -s | tr '[:upper:]' '[:lower:]')"
if [ "$KERNEL" = "linux" ]; then
    DISTRO="$(grep ^ID= /etc/os-release | cut -d= -f2 | tr '[:upper:]' '[:lower:]' | sed 's/\"//g')"

    case "$DISTRO" in

        "gentoo")
            emerge --sync
            emerge -av dev-lang/php dev-python/pip
            if [ "$?" -ne 0 ]; then
                printf "${RED}An error occurred! seems portage doesn't work.\n${RST}"
                exit 1
            fi
        ;;

        "debian" | "kali" | "ubuntu" | "linuxmint" | "parrot")
            apt_based
        ;;

        "arch" | "manjaro" | "arcolinux" | "garuda" | "artix")
            pacman_based
        ;;

        "fedora" | "centos")
            yum_based
        ;;

        *)
            printf "${RED}I couldn't detect your linux distribution!\n${RST}"
            printf "${BLU}This tool needs ${GRN}python3${BLU} and ${GRN}php${BLU}."
            printf " please install these packages on your os yourself.\n${RST}"
            printf "${BLU}Also install the required python libraries via ${RST}'${GRN}pip install -r requirements.txt${RST}'\n"
            exit 1
    esac

elif [ "$KERNEL" = "freebsd" ]; then
    pkg update
    pkg install python310 py310-pip php
    if [ "$?" -ne 0 ]; then
        printf "${RED}An error occurred! seems pkg doesn't work.\n${RST}"
        exit 1
    fi

elif [ "$KERNEL" = "openbsd"  ]; then
    pkg_add python py3-pip php
    if [ "$?" -ne 0 ]; then
        printf "${RED}An error occurred! seems pkg_add doesn't work.\n${RST}"
        exit 1
    fi
        
elif [ "$KERNEL" = "darwin" ]; then
    printf "${PUL}Which package manager do you have?\n${RST}"
    printf "  1) ${GRN}MacPorts${RST}       2) ${GRN}Homebrew\n${RST}"

    printf "\n(1 or 2) ${GRY}#${RST} "
    read -r pm
    if [ "$pm" = "1" ]; then
        port selfupdate
        port install python38 py38-pip php
        if [ "$?" -ne 0 ]; then
            printf "${RED}An error occurred! seems port doesn't work.\n${RST}"
            exit 1
        fi

        python3.8 -m pip install -r ./requirements.txt
        if [ "$?" -ne 0 ]; then
            printf "${RED}An error occurred! seems pip doesn't work.\n${RST}"
            exit 1
        fi

        printf "${PUL}I want to make python3.8 your default python3, Can I do it?\n${RST}"

        printf "\n(y or n) ${GRY}#${RST} "
        read -r op
        if [ "$op" = "y" ]; then
            port select --set python3 python38
            printf "${BLU}Reopen terminal emulator to apply changes\n${RST}"
            sleep 2
        elif [ "$op" = "n" ]; then
            printf "${BLU}You can run python v3.8 by \`python3.8\`\n${RST}"
            sleep 2
        else
            printf "${RED}Invalid input\n${RST}"
            exit 1
        fi

    elif [ "$pm" = "2" ]; then
        brew update
        brew install python php
        python3 -m pip install -r ./requirements.txt
        if [ "$?" -ne 0 ]; then
            printf "${RED}An error occurred! seems brew doesn't work.\n${RST}"
            exit 1
        fi
    else
        printf "${RED}Invalid input\n${RST}"
        exit 1
    fi
fi


if [ "$KERNEL" != "darwin" ]; then
    pythonV="$(python3 --version | grep -oP '(?<=\.)\d+(?=\.)')"
    status=1
    if [ "${pythonV}" -ge 11 ]; then
        env python3 -m pip install -r --break-system-packages ./requirements.txt
        status=$?
    else
        env python3 -m pip install -r ./requirements.txt
        status=$?
    fi

    if [ "${status}" -ne 0 ]; then
        printf "${RED}An error occurred! seems pip doesn't work.\n${RST}"
        exit 1
    fi
fi


ARCH="$(uname -m | tr '[:upper:]' '[:lower:]')"
URL=""
if [ "$KERNEL" = "linux" ]; then
    if [ "$ARCH" = "x86_64" ]; then
        URL="https://bin.equinox.io/c/4VmDzA7iaHb/ngrok-stable-linux-amd64.tgz"
    elif [ "$ARCH" = "amd64" ]; then
        URL="https://bin.equinox.io/c/4VmDzA7iaHb/ngrok-stable-linux-amd64.tgz"
    elif [ "$ARCH" = "aarch64" ]; then
        URL="https://bin.equinox.io/c/4VmDzA7iaHb/ngrok-stable-linux-arm64.tgz"
    elif [ "$ARCH" = "armv7l" ]; then
        URL="https://bin.equinox.io/c/4VmDzA7iaHb/ngrok-stable-linux-arm.tgz"
    else
        URL=""
    fi

elif [ "$KERNEL" = "freebsd" ]; then
     if [ "$ARCH" = "amd64" ]; then
        URL="https://bin.equinox.io/c/4VmDzA7iaHb/ngrok-stable-freebsd-amd64.tgz"
     elif [ "$ARCH" = "i386" ]; then
         URL="https://bin.equinox.io/c/4VmDzA7iaHb/ngrok-stable-freebsd-386.tgz"
     else
         URL=""
     fi

elif [ "$KERNEL" = "darwin" ]; then
    if [ "$ARCH" = "amd64" ]; then
        URL="https://bin.equinox.io/c/4VmDzA7iaHb/ngrok-stable-darwin-amd64.zip"
    elif [ "$ARCH" = "arm64" ]; then
        URL="https://bin.equinox.io/c/4VmDzA7iaHb/ngrok-stable-darwin-arm64.zip"
    else
        URL=""
    fi
fi

if [ "$URL" != "" ]; then
    if [ "$KERNEL" = "darwin" ]; then
        curl "$URL" -o ngrok.zip
        if [ "$?" -ne 0 ]; then
            printf "${RED}An error occurred! seems curl can not download the ngrok${RST}"
            exit 1
        fi
        unzip ngrok.zip
        rm ngrok.zip
        install ngrok /usr/local/bin/ngrok -m 0755
    else
        curl "$URL" -o ngrok.tgz
        if [ "$?" -ne 0 ]; then
            printf "${RED}An error occurred! seems curl can not download the ngrok${RST}"
            exit 1
        fi
        tar -xf ngrok.tgz
        rm ngrok.tgz
        install ngrok /usr/local/bin/ngrok -m 0755
    fi
fi

printf "${GRN}\nDependencies installed successfully.\n${RST}"
exit 0
