#!/usr/bin/perl -w
#
# check_chassis_server.pl - nagios plugin
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

my %load;
my %loadthres;
my $loadwarn = .8;

my $memwarn  = 70;
my $memcrit  = 90;
my $swapwarn = 20;
my $swapcrit = 90;

my $memUsage  = undef;
my $swapUsage = undef;

my $uptime;

my %LOAD_INTERVALS = (
    '1MIN',  1,
    '5MIN',  2,
    '15MIN', 3
);

my $usage;
my $skipreboot = 0;
my $skipswap = 0;
my $skipmem = 0;
my $skipload = 0;
my $verbose = 0;
my $help = 0;



# Just in case of problems, let's not hang Nagios
$SIG{'ALRM'} = sub {
    print( "ERROR: No snmp response from $hostname\n" );
    exit $ERRORS{"UNKNOWN"};
};
alarm( $TIMEOUT );


$status = GetOptions(
            "hostname=s",  \$hostname,
            "community=s", \$community,
            "port=i",      \$port,
            "skipmem",     \$skipmem,
            "skipswap",    \$skipswap,
            "skipload",    \$skipload,
            "skipreboot",  \$skipreboot,
            "verbose",     \$verbose,
            "memwarn=i",   \$memwarn,
            "help|?",               \$help,
            "memcrit=i",   \$memcrit,
            "reboot=i",    \$rebootWindow
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

if( !$skipmem ) {
    checkMemory();
}

if( !$skipload ) {
    checkLoad();
}

$session->close;

if( $state eq 'OK' )
{
    print "OK - ";

    print "Load: $load{'1MIN'} $load{'5MIN'} $load{'15MIN'}; " if !$skipload;
    printf( "Mem: %0.2f%%; ", $memUsage ) if( !$skipmem );
    printf( "Swap: %0.2f%%; ", $swapUsage ) if( !$skipmem && !$skipswap && defined( $swapUsage ) );
    printf( "Up %0.2f days", $uptime ) if( !$skipreboot );
    print( "\n" );
}
else
{
    print "$answer\n";
}

exit $ERRORS{$state};






sub checkMemory
{
    my $snmpMemTable = '1.3.6.1.4.1.2021.4';
    my $snmpMemTotalSwap = '1.3.6.1.4.1.2021.4.3.0';
    my $snmpMemAvailSwap = '1.3.6.1.4.1.2021.4.4.0';
    my $snmpMemTotalReal = '1.3.6.1.4.1.2021.4.5.0';
    my $snmpMemAvailReal = '1.3.6.1.4.1.2021.4.6.0';
    my $snmpMemCached = '1.3.6.1.4.1.2021.4.15.0';
    my $snmpMemSwapError = '1.3.6.1.4.1.2021.4.100';
    my $snmpMemSwapErrorMsg = '1.3.6.1.4.1.2021.4.101';

    my $swaptotal    = undef;
    my $swapfree     = undef;
    my $swapError    = undef;
    my $swapErrorMsg = undef;

    my $realtotal = undef;
    my $realfree  = undef;
    my $cached    = undef;

    return if( !( $response = snmpGetTable( $snmpMemTable, 'memory' ) ) );

    foreach $snmpkey ( keys %{$response} )
    {
        $snmpkey =~ /$snmpMemTotalSwap/ && do {
            $swaptotal = $response->{$snmpkey};
        };

        $snmpkey =~ /$snmpMemAvailSwap/ && do {
            $swapfree = $response->{$snmpkey};
        };

        $snmpkey =~ /$snmpMemTotalReal/ && do {
            $realtotal = $response->{$snmpkey};
        };

        $snmpkey =~ /$snmpMemAvailReal/ && do {
            $realfree = $response->{$snmpkey};
        };

        $snmpkey =~ /$snmpMemCached/ && do {
            $cached = $response->{$snmpkey};
        };

        $snmpkey =~ /$snmpMemSwapError/ && do {
            $swapError = $response->{$snmpkey};
        };

        $snmpkey =~ /$snmpMemSwapErrorMsg/ && do {
            $swapErrorMsg = $response->{$snmpkey};
        };
    }

    if( !defined( $cached ) )
    {
        $cached = 0;
    }

    $memUsage = ( ( ( $realtotal - $realfree - $cached ) ) * 100 ) / $realtotal;
    printf( "Memory - total: $realtotal realfree: $realfree cache: $cached - Usage: %0.2f%%\n", $memUsage ) if $verbose;

    if( $memUsage >= $memcrit ) {
        &setstate( 'CRITICAL', "Memory Usage " . int( $memUsage ) . "%" );
    } elsif( $memUsage >= $memwarn ) {
        &setstate( 'WARNING', "Memory Usage " . int( $memUsage ) . "%" );
    }

    if( !$skipswap && defined( $swapError ) && $swapError ) {
        &setstate( 'CRITICAL', "Swap Error: " . $swapErrorMsg );
    }

    if( !$skipswap && defined( $swaptotal ) && $swaptotal )
    {
        $swapUsage = ( ( $swaptotal - $swapfree ) * 100 ) / $swaptotal;

        printf( "Swap - total: $swaptotal swapfree: $swapfree - Usage: %0.2f%%\n", $swapUsage ) if $verbose;

        if( $swapUsage >= $swapcrit ) {
            &setstate( 'CRITICAL', "Swap Usage " . int( $swapUsage ) . "%" );
        } elsif( $swapUsage >= $swapwarn ) {
            &setstate( 'WARNING', "Swap Usage " . int( $swapUsage ) . "%" );
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

    if( $sysuptime <= $rebootWindow ) {
            &setstate( 'WARNING', sprintf( "Device rebooted %0.1f minutes ago", $sysuptime ) );
    }

    $uptime = $sysuptime / 60.0 / 24.0;
}

sub checkLoad
{
    my $snmpLoadTable     = '1.3.6.1.4.1.2021.10.1';
    my $snmpLoadValues    = '1.3.6.1.4.1.2021.10.1.3.';
    my $snmpLoadThres     = '1.3.6.1.4.1.2021.10.1.4.';

    return if( !( $response = snmpGetTable( $snmpLoadTable, 'system load' ) ) );

    foreach $int ( keys %LOAD_INTERVALS )
    {
        $load{$int}      = $response->{$snmpLoadValues . $LOAD_INTERVALS{$int}};
        $loadthres{$int} = $response->{$snmpLoadThres . $LOAD_INTERVALS{$int}};
        printf( $int . ":\t" . $load{$int}  . "/" . $loadthres{$int} . "\n" ) if $verbose;

        if( $load{$int} >= $loadthres{$int} ) {
            &setstate( 'CRITICAL', $int . " load average is $load{$int}" );
        } elsif( $load{$int} >= ( $loadthres{$int} * $loadwarn ) ) {
            &setstate( 'WARNING', $int . " load average is $load{$int}" );
        }
    }
}

sub usage {
  printf "\nUsage:\n";
  printf "\n";
  printf "Perl server chassis plugin for Nagios\n\n";
  printf "check_chassis_server.pl -c <READCOMMUNITY> -p <PORT> <HOSTNAME>\n\n";
  printf "Checks:\n";
  printf "  * memory and swap space usage\n";
  printf "  * system load\n";
  printf "  * if the device was recently rebooted\n\n";
  printf "Additional options:\n\n";
  printf "  --help                  This help message\n\n";
  printf "  --hostname              The hostname to check\n";
  printf "  --community             The SNMP access community\n";
  printf "  --skipload              Skip server load checks\n";
  printf "  --skipmem               Skip memory checks\n";
  printf "  --skipswap              Skip swap but not real memory checks\n";
  printf "  --skipreboot            Skip reboot check\n";
  printf "  --reboot <integer>      How many minutes ago should we warn that the device has been rebooted (default: " . $rebootWindow . ")\n";
  printf "  --memwarn <integer>     Percentage of memory usage for warning (default: " . $memwarn . ")\n";
  printf "  --memcrit <integer>     Percentage of memory usage for critical (default: " . $memcrit . ")\n";
  printf "  --swapwarn <integer>    Percentage of swap usage for warning (default: " . $swapwarn . ")\n";
  printf "  --swapcrit <integer>    Percentage of swap usage for critical (default: " . $swapcrit . ")\n";
  printf "  --loadwarn <float>      Multiplier of load critical value for warning (default: " . $loadwarn . ")\n";
  printf "                          (critical load value is taken from SNMP)\n";
  printf "\nCopyright (c) 2011, Barry O'Donovan <barry\@opensolutions.ie>\n";
  printf "All rights reserved.\n\n";
  printf "check_chassis_server.pl comes with ABSOLUTELY NO WARRANTY\n";
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
