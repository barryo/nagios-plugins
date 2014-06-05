#!/usr/bin/perl -w
#
# check_chassis_brocade.pl - nagios plugin
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


my $status;
my $timeout = 20;
my $state = "OK";
my $answer = "";
my $int;
my $snmpkey;
my $key;
my $community = "public";
my $port = 161;
my $perf = 0;

my $hostname = undef;
my $session;
my $error;
my $response = undef;

my $rebootWindow = 60;

my $ignorepsunotpresent = 0;

my $skipmem = 0;
my $skiptemp = 0;
my $skipfans = 0;
my $skippsu = 0;
my $skipreboot = 0;
my $skipcpuall = 0;
my %skipcpu = (
    '1sec' => 0,
    '5sec' => 0,
    '1min' => 0
);

my %threscpu = (
    '1secw' => 95, '1secc' => 98,
    '5secw' => 85, '5secc' => 95,
    '1minw' => 70, '1minc' => 90
);

my %threscpuarg;

my $verbose = 0;
my $help = 0;

my $memwarn  = 70;
my $memcrit  = 90;

my $tempwarn  = undef;
my $tempcrit  = undef;

my $memUsage  = undef;

my $uptime;
my $tempdata = undef;
my $fandata = undef;
my $psudata = undef;
my $cpudata = undef;
my $memdata = undef;

my $tempdataperf = undef;
my $cpudataperf = undef;
my $memdataperf = undef;

my $lastcheck = undef;

my $skipothers = 0;

$status = GetOptions(
            "hostname=s",       \$hostname,
            "community=s",      \$community,
            "port=i",           \$port,
            "lastcheck=i",      \$lastcheck,
            "skip-mem",         \$skipmem,
            "memwarn=i",        \$memwarn,
            "memcrit=i",        \$memcrit,
            "tempwarn=i",       \$tempwarn,
            "tempcrit=i",       \$tempcrit,
            "skip-temp",        \$skiptemp,
            "skip-fans",        \$skipfans,
            "skip-psu",         \$skippsu,
            "skip-reboot",      \$skipreboot,
            "skip-cpu",         \$skipcpuall,
            "skip-cpu-1sec",    \$skipcpu{'1sec'},
            "skip-cpu-5sec",    \$skipcpu{'5sec'},
            "skip-cpu-1min",    \$skipcpu{'1min'},
            "skip-others",      \$skipothers,
            "thres-cpu-1sec=s",      \$threscpuarg{'1sec'},
            "thres-cpu-5sec=s",      \$threscpuarg{'5sec'},
            "thres-cpu-1min=s",      \$threscpuarg{'1min'},
            "ignore-psu-notpresent", \$ignorepsunotpresent,
            "timeout=i",        \$timeout,
            "perf",             \$perf,
            "help|?",               \$help,
            "verbose",          \$verbose,
            "reboot=i",         \$rebootWindow
);

# Just in case of problems, let's not hang Nagios
$SIG{'ALRM'} = sub {
    print( "ERROR: No snmp response from $hostname\n" );
    exit $ERRORS{"UNKNOWN"};
};
alarm( $timeout);

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

if( !$skipothers ) {
    checkOthers();
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
}
else
{
    print "$answer";
}

if ($perf) {
    print "|";
    print $cpudataperf if( !$skipcpuall && defined( $cpudataperf ) );
    print $memdataperf if( !$skipmem && defined( $memdataperf ) );
    print $tempdataperf if( !$skiptemp && defined( $tempdataperf ) );
}

print( "\n" );

exit $ERRORS{$state};






sub checkTemperature
{
    my $snmpTempTable     = '1.3.6.1.4.1.1991.1.1.1.1';
    my $snmpTempActual    = '1.3.6.1.4.1.1991.1.1.1.1.18';
    my $snmpTempWarning   = '1.3.6.1.4.1.1991.1.1.1.1.19';
    my $snmpTempShutdown  = '1.3.6.1.4.1.1991.1.1.1.1.20';

    return if( !( $response = snmpGetTable( $snmpTempTable, 'temperature' ) ) );

    my $i = 0;

    foreach $snmpkey ( keys %{$response} )
    {
        # check each sensor (by iterating the actual)
        $snmpkey =~ /$snmpTempActual/ && do {

            my $t_index;

            if( $snmpkey =~ /$snmpTempActual\.(\d+)$/ )
            {
                $i++;
                $t_index = $1;

                my $t_actual   = convertToCel( $response->{$snmpTempActual   . '.' . $t_index} );
                my $t_warning  = convertToCel( $response->{$snmpTempWarning  . '.' . $t_index} );
                $t_warning = '64.0' if ( $t_warning == 0 ); 
                my $t_shutdown = convertToCel( $response->{$snmpTempShutdown . '.' . $t_index} );

                print( "Temp #$t_index: $t_actual (Warn: $t_warning    Shutdown: $t_shutdown)\n" ) if $verbose;
                $tempdata  = "Temp (A/W/C): " if( !defined( $tempdata ) );
                $tempdata .= sprintf( "%0.1f/%0.1f/%0.1f; ", $t_actual, $t_warning, $t_shutdown );
                $tempdataperf .= sprintf( "Temp=%0.1fdeg;%0.1f;%0.1f ", $t_actual, $t_warning, $t_shutdown );

                if( $t_actual >= $t_warning ) {
                    &setstate( 'CRITICAL', "Temperature approaching SHUTDOWN threshold: $t_actual/$t_shutdown" );
                };

                if( defined( $tempcrit ) && $t_actual >= $tempcrit ) {
                    &setstate( 'CRITICAL', "Temperature exceeds critical threshold: $t_actual/$tempcrit" );
                } elsif( defined( $tempwarn ) && $t_actual >= $tempwarn ) {
                    &setstate( 'WARNING', "Temperature exceeds warning threshold: $t_actual/$tempwarn" );
                }
            }
        }
    }
}



sub checkFans
{
    my $snmpFanTable     = '1.3.6.1.4.1.1991.1.1.1.3.1.1';
    my $snmpFanDesc      = '1.3.6.1.4.1.1991.1.1.1.3.1.1.2';
    my $snmpFanState     = '1.3.6.1.4.1.1991.1.1.1.3.1.1.3';

    my %FAN_STATES = (
        '1', 'OTHER',
        '2', 'NORMAL',
        '3', 'FAILURE'
    );

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
                my $t_state = $FAN_STATES{$response->{$snmpFanState . '.' . $t_index}};


                print( "Fan: $t_desc - $t_state\n" ) if $verbose;
                $fandata = "Fans:" if( !defined( $fandata ) );
                $fandata .= ( $t_state eq 'NORMAL' ? ' OK' : " $t_state" );

                if( $t_state =~ m/FAILURE/i ) {
                    &setstate( 'CRITICAL', "Fan state for $t_desc: $t_state" );
                } elsif( $t_state !~ m/^NORMAL$/i ) {
                    &setstate( 'WARNING', "Fan state for $t_desc: $t_state" );
                }
            }
        }
    }

    $fandata .= ". " if( defined( $fandata ) );
}





sub checkPower
{
    my $snmpPowerTable     = '1.3.6.1.4.1.1991.1.1.1.2.1.1';
    my $snmpPowerDesc      = '1.3.6.1.4.1.1991.1.1.1.2.1.1.2';
    my $snmpPowerState     = '1.3.6.1.4.1.1991.1.1.1.2.1.1.3';

    my %PSU_STATES = (
        '1', 'OTHER',
        '2', 'NORMAL',
        '3', 'FAILURE'
    );

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
                my $t_state = $PSU_STATES{$response->{$snmpPowerState . '.' . $t_index}};

		if ( $ignorepsunotpresent && ($t_desc =~ /not present/) ) { $t_state = "N/A"; }

                print( "PSU: $t_desc - $t_state\n" ) if $verbose;
                $psudata = "PSUs:" if( !defined( $psudata ) );
                $psudata .= ( $t_state eq 'NORMAL' ? ' OK' : " $t_state" );

                if( $t_state =~ m/FAILURE/i ) {
                    &setstate( 'CRITICAL', "PSU state for $t_desc: $t_state" );
                } elsif( $t_state !~ m/^NORMAL|N\/A$/i ) {
                    &setstate( 'WARNING', "PSU state for $t_desc: $t_state" );
                }
            }
        }
    }

    $psudata .= ". " if( defined( $psudata ) );
}






sub checkMemory
{
    my $snmpMemDynUsed  = '1.3.6.1.4.1.1991.1.1.2.1.53.0';

    return if( !( $response = snmpGetRequest( $snmpMemDynUsed, 'memory' ) ) );

    my $memused = $response->{$snmpMemDynUsed};

    printf( "Memory: %d%% used\n", $memused ) if $verbose;

    $memdataperf = sprintf( "Memory=%d%%;%d;%d ", $memused, $memwarn, $memcrit );

    if( $memused >= $memcrit ) {
        &setstate( 'CRITICAL', sprintf( "Memory Usage at %d%%", $memused ) );
    } elsif( $memused >= $memwarn ) {
        &setstate( 'WARNING', sprintf( "Memory Usage at %d%%",  $memused ) );
    } else {
        $memdata = sprintf( "Memory OK (%d%%). ", $memused );
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


sub checkOthers
{
    my $snmpGblTable = '1.3.6.1.4.1.1991.1.1.2.1';
    my $snmpGblQueueOverflow = '1.3.6.1.4.1.1991.1.1.2.1.30.0';
    my $snmpGblBufferShortage = '1.3.6.1.4.1.1991.1.1.2.1.31.0';
    my $snmpGblDmaFailure = '1.3.6.1.4.1.1991.1.1.2.1.32.0';
    my $snmpGblResourceLow = '1.3.6.1.4.1.1991.1.1.2.1.33.0';
    my $snmpGblExcessiveError = '1.3.6.1.4.1.1991.1.1.2.1.34.0';

    return if( !( $response = snmpGetTable( $snmpGblTable, 'Global Table' ) ) );

    foreach $snmpkey ( keys %{$response} )
    {
        $snmpkey =~ /$snmpGblQueueOverflow/ && do {
            printf "Global Table: Queue Overflow ($snmpkey): $response->{$snmpkey}\n" if $verbose;
            if( $response->{$snmpkey} == 1 ) {
                &setstate( 'CRITICAL', "Queue Overflow" );
            }
        };

        $snmpkey =~ /$snmpGblBufferShortage/ && do {
            printf "Global Table: Buffer Shortage ($snmpkey): $response->{$snmpkey}\n" if $verbose;
            if( $response->{$snmpkey} == 1 ) {
                &setstate( 'CRITICAL', "Buffer Shortage" );
            }
        };

        $snmpkey =~ /$snmpGblDmaFailure/ && do {
            printf "Global Table: DMA Failure ($snmpkey): $response->{$snmpkey}\n" if $verbose;
            if( $response->{$snmpkey} == 1 ) {
                &setstate( 'CRITICAL', "DMA Failure" );
            }
        };

        $snmpkey =~ /$snmpGblResourceLow/ && do {
            printf "Global Table: Low Resource Warning ($snmpkey): $response->{$snmpkey}\n" if $verbose;
            if( $response->{$snmpkey} == 1 ) {
                &setstate( 'WARNING', "Low Resource Warning" );
            }
        };

        $snmpkey =~ /$snmpGblExcessiveError/ && do {
            printf "Global Table: Excessive Errors Warning ($snmpkey): $response->{$snmpkey}\n" if $verbose;
            if( $response->{$snmpkey} == 1 ) {
                &setstate( 'WARNING', "Excessive Errors Warning" );
            }
        };
    }
}


sub checkCPU
{
    my $snmpCpuTable     = '1.3.6.1.4.1.1991.1.1.2.1';
    my %snmpCpu = (
         '1sec', '1.3.6.1.4.1.1991.1.1.2.1.50.0',
         '5sec', '1.3.6.1.4.1.1991.1.1.2.1.51.0',
         '1min', '1.3.6.1.4.1.1991.1.1.2.1.52.0'
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
        $cpudataperf .= "CPU_$t_time=$util%" . ";" . $threscpu{$t_time.'w'}. ";" . $threscpu{$t_time.'c'} . " ";

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
  printf "Perl Brocade switch chassis plugin for Nagios\n\n";
  printf "check_chassis_brocade.pl -c <READCOMMUNITY> -p <PORT> -h <HOSTNAME>\n\n";
  printf "Additional options:\n\n";
  printf "  --help                  This help message\n\n";
  printf "  --hostname              The hostname to check\n";
  printf "  --port                  The port to query SNMP on (using: $port)\n";
  printf "  --community             The SNMP access community (using: $community)\n\n";
  printf "  --timeout <integer>     Execution timeout in seconds (using: " . $timeout. ")\n";
  printf "  --perf                  Produce performance data output\n\n";
  printf "  --skip-mem              Skip memory checks\n";
  printf "  --memwarn <integer>     Percentage of memory usage for warning (using: " . $memwarn . ")\n";
  printf "  --memcrit <integer>     Percentage of memory usage for critical (using: " . $memcrit . ")\n\n";
  printf "  --skip-temp             Skip temperature checks\n";
  printf "  --tempwarn <integer>    Degrees Celsius for warning (in addition to device's setting)\n";
  printf "  --tempcrit <integer>    Degrees Celsius for critical (in addition to device's setting)\n";
  printf "  --skip-fans             Skip fan checks\n\n";
  printf "  --skip-psu              Skip PSU(s) checks\n";
  printf "  --ignore-psu-notpresent Ignore PSUs that are not installed\n\n";
  printf "  --skip-reboot           Skip reboot check\n";
  printf "  --lastcheck             Nagios \$LASTSERVICECHECK\$ macro. Used by reboot check such that if the\n";
  printf "                          last reboot was within the last check, then an alert if generated. Overrides\n";
  printf "                          --reboot to ensure reboots are caught\n\n";
  
  printf "  --skip-others           Skip 'other' checks\n\n";
  
  printf "  --skip-cpu              Skip all CPU utilisation checks\n";
  printf "  --skip-cpu-5sec         Skip 5sec CPU utilisation check\n";
  printf "  --skip-cpu-1min         Skip 1min CPU utilisation check\n";
  printf "  --skip-cpu-5min         Skip 5min CPU utilisation check\n";

  printf( "  --thres-cpu-1sec        CPU warning,critical thresholds for 1sec checks (using %d,%d)\n", $threscpu{'1secw'}, $threscpu{'1secc'} );
  printf( "  --thres-cpu-5sec        CPU warning,critical thresholds for 5sec checks (using %d,%d)\n", $threscpu{'5secw'}, $threscpu{'5secc'} );
  printf( "  --thres-cpu-1min        CPU warning,critical thresholds for 1min checks (using %d,%d)\n\n", $threscpu{'1minw'}, $threscpu{'1minc'} );

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

sub convertToCel {
    my $t = shift( @_ );

    # it seems Brocade reports in units of 0.5 Celsius
    return sprintf( "%0.1f", $t / 2.0 );
}
