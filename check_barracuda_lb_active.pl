#!/usr/bin/perl -w
#
# check_barracuda_lb_active.pl - nagios plugin
#
# Check if a Barracuda Load Balance in High Avilability mode is active or not.
#
# Ideally, I'd like a warning on failover but I need to monitor a live system
# without test lab. So, for a first pass, I will check of a resource pool IP
# is or is not set on the system. In time, I'd like to check the following MIBs:
#
# IP-MIB::ipAddressCreated.ipv4."x.x.x.x" = Timeticks: (0) 0:00:00.00
# IP-MIB::ipAddressLastChanged.ipv4."x.x.x.x" = Timeticks: (0) 0:00:00.00
#
# which are shown above for a specific IP where no failover has occured since
# boot and where the above MIBs are (at least initially) NOT present on the
# inactive box.
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
               'UNSET',    -1,
               'OK' ,      0,
               'WARNING',  1,
               'CRITICAL', 2,
               'UNKNOWN',  3
);

my $status;
my $TIMEOUT = 20;
my $state = "UNSET";
my $answer = "";
my $snmpkey;
my $community = "public";
my $port = 161;
my $hostname = undef;
my $session;
my $error;
my $response = undef;

my $ip = undef;
my $verbose = 0;
my $help = 0;
my $notexpected = 0;

# Just in case of problems, let's not hang Nagios
$SIG{'ALRM'} = sub {
    print( "ERROR: No snmp response from $hostname\n" );
    exit $ERRORS{"UNKNOWN"};
};
alarm( $TIMEOUT );


$status = GetOptions(
            "hostname=s",           \$hostname,
            "community=s",          \$community,
            "port=i",               \$port,
            "verbose",              \$verbose,
            "ip=s"     ,            \$ip,
            "help|?",               \$help,
            "inactive",             \$notexpected
);

if( !$status || $help ) {
    usage();
}


usage() if( !defined( $hostname ) || !defined( $ip ));

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


my $snmpIPv4AddresIfIndexTable = '.1.3.6.1.2.1.4.34.1.3.1.4';

my $ifIndex = snmpGetRequest( $snmpIPv4AddresIfIndexTable . '.' . $ip, 'IPv4 Address If Index' );

if( $ifIndex != 0 && $notexpected ) {
    &setstate( 'WARNING', "In active state for $ip when expecting inactive state" );
} elsif( $ifIndex == 0 && !$notexpected ) {
    &setstate( 'WARNING', "In inactive state for $ip when expecting active state" );
} elsif( $ifIndex == 0 ) {
    &setstate( 'OK', "In inactive state for $ip" );
} elsif( $ifIndex != 0 ) {
    &setstate( 'OK', "In active state for $ip" );
}

$session->close;

print "$answer\n";
exit $ERRORS{$state};





sub usage {
  printf "Script to check the high availibility state of a Barracuda Load Balancer\n\n";
  printf "check_barracuda_lb_active.pl --community <READCOMMUNITY> --hostname <HOSTNAME> --ip x.x.x.x\n\n";
  printf "Usage:\n\n";
  printf "Additional options:\n\n";
  printf "  --help                  This help message\n\n";
  printf "  --hostname              The hostname to check\n";
  printf "  --port                  The port to query SNMP on (using: $port)\n";
  printf "  --community             The SNMP access community (using: $community)\n\n";
  printf "  --ip                    The IP address to check for the presence of\n\n";
  printf "  --inactive              You expect NOT to find the IP - confirming inactive state\n\n";
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
    my $response;

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
