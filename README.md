Nagios Plugins :: nagios-plugins
================================

Introduction
------------

`nagios-plugins` is a collection of various plugins I have written and expanded
over the last ten years or so. They main goal of Nagios plugins that I write
and release are:

* BSD (or BSD like) license so you can hack away to wield into something that
  may be more suitable for your own environment;
* scalable in that if I am polling power supply units (PSUs) in a Cisco switch
  then it should not matter if there is one or a hundred - the script should
  handle them all;
* WARNINGs are designed for email notifications during working hours; CRITICAL
  means an out of hours text / SMS message :(
* Perl is not my first language but all scripts are written in Perl. Forgive
  my inelegence (and give me C / C++ / PHP anyday!)
* each script should be an independant unit with no dependancies on each
  other or unusual Perl modules;
* the scripts should all be run with the `--verbose` on new kit. This will
  provide an inventory of what it finds as well as show anything that is being
  skipped. OIDs searched for by the script but reported as not supported on
  the target device should really be skipped via various `--skip-xxx` options.
* useful help available via `--help` or `-?` or `--man``

Installation
------------

```sh
git clone https://github.com/barryo/nagios-plugins.git
cd nagios-plugins/
git submodule init
git submodule update
```

License
-------

Unless stated otherwise at the top of the script of its help output, all scripts
are:

    Copyright (c) 2004 - 2014, Barry O'Donovan <info@opensolutions.ie>
    Copyright (c) 2004 - 2014, Open Source Solutions Limited <info@opensolutions.ie>
    All rights reserved.

    Redistribution and use in source and binary forms, with or without modification,
    are permitted provided that the following conditions are met:

     * Redistributions of source code must retain the above copyright notice, this
       list of conditions and the following disclaimer.

     * Redistributions in binary form must reproduce the above copyright notice, this
       list of conditions and the following disclaimer in the documentation and/or
       other materials provided with the distribution.

     * Neither the name of Open Solutions nor the names of its contributors may be
       used to endorse or promote products derived from this software without
       specific prior written permission.

    THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
    ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
    WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
    IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
    INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
    BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
    DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF
    LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE
    OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED
    OF THE POSSIBILITY OF SUCH DAMAGE.

About the Author
----------------

Please see my company website at http://www.opensolutions.ie/ or my own personal
site at http://www.barryodonovan.com/.



Overview of Available Plugins
-----------------------------

### check_rsnapshot.php

A script to check backups made via rsnapshot. See https://github.com/barryo/nagios-plugins/wiki/check_rsnapshot.php.

### check_barracuda_lb_active.pl

A script to check if a given Barracuda load balance in high availability state
is in active (or inactive) mode for a given IP address.

### check_chassis_cisco.pl

This script polls a Cisco switch or router and checks and generates alerts on the following items:

* a warning if the device was recently rebooted;
* a warning / critical if any found temperature sensors are in a non-normal state;
* a warning / critical if any found fans are in a non-normal state;
* a warning / critical if any found PSUs are in a non-normal state;
* a warning / critical if the 5 sec / 1 min / 5 min CPU utilisation is above set thresholds;
* a warning / critical if the memory utilisation is above set thresholds.

### check_chassis_brocade.pl / .php

**NB:** the .php version is more modern.

This script polls a Brocade switch or router and checks and generates alerts on the following items:

* a warning if the device was recently rebooted;
* a warning / critical if any found temperature sensors are in a non-normal state;
* a warning / critical if any found fans are in a non-normal state;
* a warning / critical if any found PSUs are in a non-normal state;
* a warning / critical if the 5 sec / 1 min / 5 min CPU utilisation is above set thresholds;
* a warning / critical if the memory utilisation is above set thresholds.

### check_chassis_extreme.php

This script polls an Extreme Networks switch or router and checks and generates alerts on the following items:

* a warning if the device was recently rebooted;
* a warning / critical if any found temperature sensors are in a non-normal state;
* a warning / critical if any found fans are in a non-normal state;
* a warning / critical if any found PSUs are in a non-normal state;
* a warning / critical if the 5 sec CPU utilisation is above set thresholds;
* a warning / critical if the memory utilisation is above set thresholds.

### check_chassis_server.pl

This script polls a Linux / BSD server and  checks and generates alerts on the following items:

* a warning if the device was recently rebooted;
* a warning / critical if the 1/5/15 min load average is above thresholds set in the servers SNMP config file;
* a warning / critical if the memory or swap utilisation is above set thresholds.

Note that you may want to set swap warning threshold low (default is 20%) as you will want to know
if you're using swap. If anyone is very familiar with the BSD memory model, I'd appreciate it if they
could look that section over as it's more suited for Linux right now.

### check_disk_snmp.pl

This script polls a Linux / BSD server and checks and generates alerts if disk utilisation
approaches warning and critical thresholds. Example Nagios output:

    OK - /var (/dev/sda7) 70%; /home (/dev/sda10) 74%; /tmp (/dev/sda8) 3%; /usr (/dev/sda5) 76%; /usr/local (/dev/sda6) 4%; / (/dev/sda9) 30%; /boot (/dev/sda2) 64%;

There is comprehensive help via the `--man` switch.


### check_portsecurity.pl

This script checks all ports on a Cisco switch and issues a critical alert if port security has
been triggered resulting in a shutdown port on the device.


### check_portstatus.pl

This script will issue warnings if the port status on any Ethernet (by default) port on a Cisco
switch has changed within the last hour (by default). I.e. a port up or a port down event.


### notify-by-pushover.php

This script sends Nagios plugins to Pushover - see https://pushover.net/ and 
http://www.barryodonovan.com/index.php/2013/05/31/nagios-icinga-alerts-via-pushover


