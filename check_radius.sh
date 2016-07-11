#!/bin/bash

# Barry O'Donovan - 20160711

# Copyright (c) 2004 - 2016, Barry O'Donovan <info@opensolutions.ie>
# All rights reserved.
#
# Redistribution and use in source and binary forms, with or without modification,
# are permitted provided that the following conditions are met:
#
# * Redistributions of source code must retain the above copyright notice, this
#   list of conditions and the following disclaimer.
#
# * Redistributions in binary form must reproduce the above copyright notice, this
#   list of conditions and the following disclaimer in the documentation and/or
#   other materials provided with the distribution.
#
# * Neither the name of Open Solutions nor the names of its contributors may be
#   used to endorse or promote products derived from this software without
#   specific prior written permission.
#                   
# THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
# ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
# WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
# IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
# INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
# BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
# DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF
# LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE
# OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED
# OF THE POSSIBILITY OF SUCH DAMAGE.
                  

RADCLIENT=$(which radclient)

if [ $? -ne 0 ] || [ ! -x $RADCLIENT ]; then
    echo UNKNOWN: You need to install FreeRADIUS client utilities
    exit 3
fi

username=""
password=""
server=""
port=1812
secret=""

function show_help {
    echo -n "USAGE: "
    echo -n ${0##*/}
    echo " -u username -p password -s radius_server -c shared_secret [-i port (1812)]"
}

OPTIND=1         # Reset in case getopts has been used previously in the shell.

while getopts "h?u:p:s:i:c:" opt; do
    case "$opt" in
        h|\?)
            show_help
            exit 0
            ;;
        u)  
            username=$OPTARG
            ;;
        p)  
            password=$OPTARG
            ;;
        s)  
            server=$OPTARG
            ;;
        i)  
            port=$OPTARG
            ;;
        c)  
            secret=$OPTARG
            ;;
    esac
done

if [ -z $username ] || [ -z $password ] || [ -z $server ] || [ -z $port ] || [ -z $secret ]; then
    show_help
    echo You tried: -u $username -p $password -s $server -i $port -c $secret
    exit 3
fi


echo "User-Name=${username},User-Password=${password}" | radclient -r 1 -t 2 ${server}:${port} auth ${secret} >/dev/null 2>&1

if [ $? -eq 0 ]; then
    echo "OK: RADIUS authentication request successful"
    exit 0;
fi

echo "CRITICAL: RADIUS authentication failed"
exit 2



