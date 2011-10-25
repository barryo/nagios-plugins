#!/usr/bin/perl -w
#
# check_chassis_cisco.pl - nagios plugin
#
# Linux / BSD Chassis Nagios Plugin
#
# Copyright (c) 2011, Barry O'Donovan <barry@opensolutions.ie>
# All rights reserved.
#
# Redistribution and use in source and binary forms, with or without modification,
# are permitted provided that the following conditions are met:
#
#  * Redistributions of source code must retain the above copyright notice, this
#    list of conditions and the following disclaimer.
#
#  * Redistributions in binary form must reproduce the above copyright notice, this
#    list of conditions and the following disclaimer in the documentation and/or
#    other materials provided with the distribution.
#
#  * Neither the name of Open Solutions nor the names of its contributors may be
#    used to endorse or promote products derived from this software without
#    specific prior written permission.
#
# THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
# ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
# WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
# IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
# INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
# BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
# DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF
# LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE
# OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED
# OF THE POSSIBILITY OF SUCH DAMAGE.
#

use strict;

use Net::SNMP;
use Getopt::Long;
&Getopt::Long::config( 'auto_abbrev' );

my %ERRORS = (
               'OK' ,      0,
               'WARNING',  1,
               'CRITICAL', 2,
               'UNKNOWN',  3
);

my %CISCO_ENVMON_STATES = (
    '1', 'NORMAL',
    '2', 'WARNING',
    '3', 'CRITICAL',
    '4', 'SHUTDOWN',
    '5', 'NOTPRESENT',
    '6', 'NOTFUNCTIONING'
);

my $status;
my $TIMEOUT = 20;
my $state = "OK";
my $answer = "";
my $int;
my $snmpkey;
my $key;
my $community = "public";
my $port = 161;

my $hostname = undef;
my $session;
my $error;
my $response = undef;

my $rebootWindow = 60;

my $skipmem = 0;
my $skiptemp = 0;
my $skipfans = 0;
my $skippsu = 0;
my $skipreboot = 0;
my $skipcpuall = 0;
my %skipcpu = (
    '5sec' => 0,
    '1min' => 0,
    '5min' => 0
);

my %threscpu = (
    '5secw' => 95, '5secc' => 98,
    '1minw' => 85, '1minc' => 95,
    '5minw' => 70, '5minc' => 90
);

my %threscpuarg;

my $verbose = 0;
my $help = 0;

my $memwarn  = 70;
my $memcrit  = 90;

my $memUsage  = undef;

my $uptime;
my $tempdata = undef;
my $fandata = undef;
my $psudata = undef;
my $cpudata = undef;
my $memdata = undef;

my $lastcheck = undef;




# Just in case of problems, let's not hang Nagios
$SIG{'ALRM'} = sub {
    print( "ERROR: No snmp response from $hostname\n" );
    exit $ERRORS{"UNKNOWN"};
};
alarm( $TIMEOUT );


$status = GetOptions(
            "hostname=s",       \$hostname,
            "community=s",      \$community,
            "port=i",           \$port,
            "lastcheck=i",      \$lastcheck,
            "skip-mem",         \$skipmem,
            "memwarn=i",        \$memwarn,
            "memcrit=i",        \$memcrit,
            "skip-temp",        \$skiptemp,
            "skip-fans",        \$skipfans,
            "skip-psu",         \$skippsu,
            "skip-reboot",      \$skipreboot,
            "skip-cpu",         \$skipcpuall,
            "skip-cpu-5sec",    \$skipcpu{'5sec'},
            "skip-cpu-1min",    \$skipcpu{'1min'},
            "skip-cpu-5min",    \$skipcpu{'5min'},
            "thres-cpu-5sec=s", \$threscpuarg{'5sec'},
            "thres-cpu-1min=s", \$threscpuarg{'1min'},
            "thres-cpu-5min=s", \$threscpuarg{'5min'},
            "help|?",               \$help,
            "verbose",          \$verbose,
            "reboot=i",         \$rebootWindow
);

if( !$status || $help ) {
    usage();
}


usage() if( !defined( $hostname ) );

( $session, $error ) = Net::SNMP->session(
    -hostname  => $hostname,
    -community => $community,
    -port      => $port,
    -translate => 0
);

if( !defined( $session ) )
{
    $state  = 'UNKNOWN';
    $answer = $error;
    print( "$state: $answer" );
    exit $ERRORS{$state};
}

# Now Query the OS Stats

if( !$skipreboot ) {
    checkReboot();
}

if( !$skiptemp ) {
    checkTemperature();
}

if( !$skipfans ) {
    checkFans();
}

if( !$skippsu ) {
    checkPower();
}

if( !$skipcpuall ) {
    checkCPU();
}

if( !$skipmem ) {
    checkMemory();
}


$session->close;

if( $state eq 'OK' )
{
    print "OK - ";

    print $cpudata if( defined( $cpudata ) && !$skipcpuall );
    print $memdata if( defined( $memdata ) && !$skipmem );
    print $tempdata if( defined( $tempdata ) && !$skiptemp );
    print $fandata if( defined( $fandata ) && !$skipfans );
    print $psudata if( defined( $psudata ) && !$skippsu );
    printf( "Up %0.2f days", $uptime ) if( !$skipreboot );
    print( "\n" );
}
else
{
    print "$answer\n";
}

exit $ERRORS{$state};






sub checkTemperature
{
    my $snmpTempTable     = '1.3.6.1.4.1.9.9.13.1.3.1';
    my $snmpTempDesc      = '1.3.6.1.4.1.9.9.13.1.3.1.2';
    my $snmpTempValue     = '1.3.6.1.4.1.9.9.13.1.3.1.3';
    my $snmpTempThres     = '1.3.6.1.4.1.9.9.13.1.3.1.4';
    my $snmpTempState     = '1.3.6.1.4.1.9.9.13.1.3.1.6';

    my %CISCO_TEMP_STATES = (
        '1', 'NORMAL',
        '2', 'WARNING',
        '3', 'CRITICAL',
        '4', 'SHUTDOWN',
        '5', 'NOTPRESENT',
        '6', 'NOTFUNCTIONING'
    );

    return if( !( $response = snmpGetTable( $snmpTempTable, 'temperature' ) ) );

    my $i = 0;

    foreach $snmpkey ( keys %{$response} )
    {
        # check each sensors (vy iterating the descriptions)
        $snmpkey =~ /$snmpTempDesc/ && do {

            my $t_index;
            if( $snmpkey =~ /$snmpTempDesc\.(\d+)$/ )
            {
                $i++;
                $t_index = $1;

                my $t_desc  = $response->{$snmpTempDesc  . '.' . $t_index};
                my $t_value = $response->{$snmpTempValue . '.' . $t_index};
                my $t_thres = $response->{$snmpTempThres . '.' . $t_index};
                my $t_state = $CISCO_ENVMON_STATES{$response->{$snmpTempState . '.' . $t_index}};


                print( "Temp: $t_desc $t_value/$t_thres $t_state\n" ) if $verbose;
                $tempdata = "Temp: " if( !defined( $tempdata ) );
                $tempdata .= "$t_value/$t_thres ";

                if( $t_state =~ m/WARNING/i || $t_state =~ m/SHUTDOWN/i || $t_state =~ m/NOTPRESENT/i || $t_state =~ m/NOTFUNCTIONING/i ) {
                    &setstate( 'WARNING', "Temperate state for $t_desc is: $t_state ($t_value/$t_thres)" );
                } elsif( $t_state =~ m/CRITICAL/i ) {
                    &setstate( 'CRITICAL', "Temperate state for $t_desc is: $t_state ($t_value/$t_thres)" );
                } elsif( $t_state !~ m/^NORMAL$/i ) {
                    &setstate( 'WARNING', "Temperate state for $t_desc is: $t_state ($t_value/$t_thres)" );
                }
            }
        }
    }
}



sub checkFans
{
    my $snmpFanTable     = '1.3.6.1.4.1.9.9.13.1.4.1';
    my $snmpFanDesc      = '1.3.6.1.4.1.9.9.13.1.4.1.2';
    my $snmpFanState     = '1.3.6.1.4.1.9.9.13.1.4.1.3';

    return if( !( $response = snmpGetTable( $snmpFanTable, 'fan(s)' ) ) );

    my $i = 0;

    foreach $snmpkey ( keys %{$response} )
    {
        # check each fan (by iterating the descriptions)
        $snmpkey =~ /$snmpFanDesc/ && do {

            my $t_index;
            if( $snmpkey =~ /$snmpFanDesc\.(\d+)$/ )
            {
                $i++;
                $t_index = $1;

                my $t_desc  = $response->{$snmpFanDesc  . '.' . $t_index};
                my $t_state = $CISCO_ENVMON_STATES{$response->{$snmpFanState . '.' . $t_index}};


                print( "Fan: $t_desc - $t_state\n" ) if $verbose;
                $fandata = "Fans:" if( !defined( $fandata ) );
                $fandata .= ( $t_state eq 'NORMAL' ? ' OK' : " $t_state" );

                if( $t_state =~ m/WARNING/i || $t_state =~ m/SHUTDOWN/i || $t_state =~ m/NOTPRESENT/i || $t_state =~ m/NOTFUNCTIONING/i ) {
                    &setstate( 'WARNING', "Fan state for $t_desc is: $t_state" );
                } elsif( $t_state =~ m/CRITICAL/i ) {
                    &setstate( 'CRITICAL', "Fan state for $t_desc is: $t_state" );
                } elsif( $t_state !~ m/^NORMAL$/i ) {
                    &setstate( 'WARNING', "Fan state for $t_desc is: $t_state" );
                }
            }
        }
    }

    $fandata .= ". " if( defined( $fandata ) );
}





sub checkPower
{
    my $snmpPowerTable     = '1.3.6.1.4.1.9.9.13.1.5.1';
    my $snmpPowerDesc      = '1.3.6.1.4.1.9.9.13.1.5.1.2';
    my $snmpPowerState     = '1.3.6.1.4.1.9.9.13.1.5.1.3';
    my $snmpPowerSource    = '1.3.6.1.4.1.9.9.13.1.5.1.4';

    return if( !( $response = snmpGetTable( $snmpPowerTable, 'PSU(s)' ) ) );

    my $i = 0;

    foreach $snmpkey ( keys %{$response} )
    {
        # check each PSU (by iterating the descriptions)
        $snmpkey =~ /$snmpPowerDesc/ && do {

            my $t_index;
            if( $snmpkey =~ /$snmpPowerDesc\.(\d+)$/ )
            {
                $i++;
                $t_index = $1;

                my $t_desc  = $response->{$snmpPowerDesc  . '.' . $t_index};
                my $t_state = $CISCO_ENVMON_STATES{$response->{$snmpPowerState . '.' . $t_index}};


                print( "PSU: $t_desc - $t_state\n" ) if $verbose;
                $psudata = "PSUs:" if( !defined( $psudata ) );
                $psudata .= ( $t_state eq 'NORMAL' ? ' OK' : " $t_state" );

                if( $t_state =~ m/WARNING/i || $t_state =~ m/SHUTDOWN/i || $t_state =~ m/NOTPRESENT/i || $t_state =~ m/NOTFUNCTIONING/i ) {
                    &setstate( 'WARNING', "PSU state for $t_desc is: $t_state" );
                } elsif( $t_state =~ m/CRITICAL/i ) {
                    &setstate( 'CRITICAL', "PSU state for $t_desc is: $t_state" );
                } elsif( $t_state !~ m/^NORMAL$/i ) {
                    &setstate( 'WARNING', "PSU state for $t_desc is: $t_state" );
                }
            }
        }
    }

    $psudata .= ". " if( defined( $psudata ) );
}






sub checkMemory
{
    my $snmpMemPoolTable = '1.3.6.1.4.1.9.9.48.1.1.1';
    my $snmpMemPoolName  = '1.3.6.1.4.1.9.9.48.1.1.1.2';
    my $snmpMemPoolUsed  = '1.3.6.1.4.1.9.9.48.1.1.1.5';
    my $snmpMemPoolFree  = '1.3.6.1.4.1.9.9.48.1.1.1.6';

    return if( !( $response = snmpGetTable( $snmpMemPoolTable, 'memory' ) ) );

    my %memused;
    my %memfree;
    my %memusage;
    my $usage;

    foreach $snmpkey ( keys %{$response} )
    {
        # Add up the memory totals first
        $snmpkey =~ /$snmpMemPoolUsed\.(\d+)/ && do {
            $memused{$1} += $response->{$snmpkey};
        };

        $snmpkey =~ /$snmpMemPoolFree\.(\d+)/ && do {
            $memfree{$1} += $response->{$snmpkey};
        };

        if( $1 && $memused{$1} && $memfree{$1} && !defined( $memusage{$1} ) )
        {
            $memusage{$1} = ( ( $memused{$1} * 100.0 ) / ( $memused{$1} + $memfree{$1} ) );
            my $poolNameOid = $snmpMemPoolName . '.' . $1;

            printf( "%s Memory: %d/%d %0.1f%%\n", $response->{$poolNameOid}, $memused{$1}, $memused{$1} + $memfree{$1}, $memusage{$1} ) if $verbose;

            if( $memusage{$1} >= $memcrit ) {
                &setstate( 'CRITICAL', sprintf( "$response->{$poolNameOid} Memory Usage at %0.1f%%", $memusage{$1} ) );
            } elsif( $memusage{$1} >= $memwarn ) {
                &setstate( 'WARNING', sprintf( "$response->{$poolNameOid} Memory Usage at %0.1f%%",  $memusage{$1} ) );
            } else {
                $memdata = "Memory OK. ";
            }
        }
    }
}

sub checkReboot
{
    my $snmpSysUpTime = '.1.3.6.1.2.1.1.3.0';
    my $sysuptime;

    return if( !( $response = snmpGetRequest( $snmpSysUpTime, 'system uptime' ) ) );

    # uptime in minutes
    $sysuptime = $response->{$snmpSysUpTime} / 100.0 / 60.0;

    if( defined( $lastcheck ) && $lastcheck && $sysuptime <= ( $lastcheck / 60.0 ) ) {
            &setstate( 'WARNING', sprintf( "Device rebooted %0.1f minutes ago", $sysuptime ) );
    } elsif( $sysuptime <= $rebootWindow ) {
            &setstate( 'WARNING', sprintf( "Device rebooted %0.1f minutes ago", $sysuptime ) );
    }

    $uptime = $sysuptime / 60.0 / 24.0;
}

sub checkCPU
{
    my $snmpCpuTable     = '1.3.6.1.4.1.9.9.109.1.1.1.1';
    my %snmpCpu = (
         '5sec', '1.3.6.1.4.1.9.9.109.1.1.1.1.3.1',
         '1min', '1.3.6.1.4.1.9.9.109.1.1.1.1.4.1',
         '5min', '1.3.6.1.4.1.9.9.109.1.1.1.1.5.1'
    );

    return if( !( $response = snmpGetTable( $snmpCpuTable, 'CPU utilisation' ) ) );

    while( my( $t_time, $t_oid ) = each( %snmpCpu ) )
    {
        if( $skipcpu{$t_time} ) {
            next;
        }

        my $util = $response->{$t_oid};

        print( "CPU: $t_time - $util%\n" ) if $verbose;
        $cpudata = "CPU:" if( !defined( $cpudata ) );
        $cpudata .= " $t_time $util%";

        # check for user supplied thresholds
        if( defined( $threscpuarg{$t_time} ) ) {
            if( $threscpuarg{$t_time} =~ /(\d+),(\d+)/ ) {
                $threscpu{$t_time . 'w'} = $1;
                $threscpu{$t_time . 'c'} = $2;
            } else {
                print( "ERROR: Bad parameters for CPU $t_time threshold. Correct example: --thres-cpu-1min 80,90\n" );
                exit $ERRORS{"UNKNOWN"};
            }
        }

        if( $util >= $threscpu{$t_time . 'c'} ) {
            &setstate( 'CRITICAL', "$t_time CPU Usage $util%" );
        } elsif( $util >= $threscpu{$t_time . 'w'} ) {
            &setstate( 'WARNING', "$t_time CPU Usage $util%" );
        }
    }

    $cpudata .= ". " if( defined( $cpudata ) );
}

sub usage {
  printf "\nUsage:\n";
  printf "\n";
  printf "Perl server chassis plugin for Nagios\n\n";
  printf "check_chassis_server.pl -c <READCOMMUNITY> -p <PORT> -h <HOSTNAME>\n\n";
  printf "Checks:\n";
  printf "  * memory and swap space usage\n";
  printf "  * system load\n";
  printf "  * if the device was recently rebooted\n\n";
  printf "Additional options:\n\n";
  printf "  --help                  This help message\n\n";
  printf "  --hostname              The hostname to check\n";
  printf "  --port                  The port to query SNMP on (using: $port)\n";
  printf "  --community             The SNMP access community (using: $community)\n\n";
  printf "  --skip-mem              Skip memory checks\n";
  printf "  --memwarn <integer>     Percentage of memory usage for warning (using: " . $memwarn . ")\n";
  printf "  --memcrit <integer>     Percentage of memory usage for critical (using: " . $memcrit . ")\n\n";
  printf "  --skip-temp             Skip temperature checks\n\n";
  printf "  --skip-fans             Skip fan checks\n\n";
  printf "  --skip-psu              Skip PSU(s) checks\n\n";
  printf "  --skip-reboot           Skip reboot check\n";
  printf "  --lastcheck             Nagios \$LASTSERVICECHECK\$ macro. Used by reboot check such that if the\n";
  printf "                          last reboot was within the last check, then an alert if generated. Overrides\n";
  printf "                          --reboot to ensure reboots are caught\n\n";
  printf "  --skip-cpu              Skip all CPU utilisation checks\n";
  printf "  --skip-cpu-5sec         Skip 5sec CPU utilisation check\n";
  printf "  --skip-cpu-1min         Skip 1min CPU utilisation check\n";
  printf "  --skip-cpu-5min         Skip 5min CPU utilisation check\n";

  printf( "  --thres-cpu-5sec        CPU warning,critical thresholds for 5sec checks (using %d,%d)\n", $threscpu{'5secw'}, $threscpu{'5secc'} );
  printf( "  --thres-cpu-1min        CPU warning,critical thresholds for 1min checks (using %d,%d)\n", $threscpu{'1minw'}, $threscpu{'1minc'} );
  printf( "  --thres-cpu-5min        CPU warning,critical thresholds for 5min checks (using %d,%d)\n\n", $threscpu{'5minw'}, $threscpu{'5minc'} );

  printf "  --reboot <integer>      How many minutes ago should we warn that the device has been rebooted (using: " . $rebootWindow . ")\n\n";
  printf "\nCopyright (c) 2011, Barry O'Donovan <barry\@opensolutions.ie>\n";
  printf "All rights reserved.\n\n";
  printf "This script comes with ABSOLUTELY NO WARRANTY\n";
  printf "This programm is licensed under the terms of the ";
  printf "BSD New License (check source code for details)\n";
  printf "\n\n";

  exit $ERRORS{"UNKNOWN"} if !$help;
  exit 0;
}

sub setstate {
    my $newstate = shift( @_ );
    my $message  = shift( @_ );

    if( $ERRORS{$newstate} > $ERRORS{$state} )
    {
        $state = $newstate;
    }
    elsif( $newstate eq 'UNKNOWN' && $state eq 'OK' )
    {
        $state = $newstate;
    }

    if( $answer ) { $answer .= "<br />\n"; }

    $answer .= $message;
}

sub snmpGetTable {
    my $oid   = shift( @_ );
    my $check = shift( @_ );

    if( !defined( $response = $session->get_table( $oid ) ) )
    {
        if( $session->error_status() == 2 || $session->error() =~ m/requested table is empty or does not exist/i )
        {
            print "OID not supported for $check ($oid).\n" if $verbose;
            return 0;
        }

        $answer = $session->error();
        $session->close;

        $state = 'CRITICAL';
        print( "$state: $answer (in check for $check with OID: $oid)\n" );
        exit $ERRORS{$state};
    }

    return $response;
}

sub snmpGetRequest {
    my $oid   = shift( @_ );
    my $check = shift( @_ );

    if( !defined( $response = $session->get_request( $oid ) ) )
    {
        if( $session->error_status() == 2 || $session->error() =~ m/requested table is empty or does not exist/i )
        {
            print "OID not supported for $check ($oid).\n" if $verbose;
            return 0;
        }

        $answer = $session->error();
        $session->close;

        $state = 'CRITICAL';
        print( "$state: $answer (in check for $check with OID: $oid)\n" );
        exit $ERRORS{$state};
    }

    return $response;
}
