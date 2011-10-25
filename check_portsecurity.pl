#!/usr/bin/perl -w
#
# check_portsecurity.pl - nagios plugin
#
# Port Security Status Nagios Plugin
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

my %CISCO_PORTSECURITY_STATES = (
    '1', 'SECUREUP',
    '2', 'SECUREDOWN',
    '3', 'SHUTDOWN'
);

my %port_states = (
    '1', 0,
    '2', 0,
    '3', 0
);

my $status;
my $TIMEOUT = 20;
my $state = "OK";
my $answer = "";
my $snmpkey;
my $community = "public";
my $port = 161;
my $hostname = undef;
my $session;
my $error;
my $response = undef;

my $alertOnSecureDown = 0;

my $verbose = 0;
my $help = 0;

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
            "help|?",               \$help,
            "alert-on-secure-down", \$alertOnSecureDown
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



my $snmpPortSecurityTable  = '1.3.6.1.4.1.9.9.315.1.2.1.1.2';
my $snmpPortNameTable      = '1.3.6.1.2.1.31.1.1.1.1';
my $snmpPortAliasTable     = '1.3.6.1.2.1.31.1.1.1.18';

my $securityTable = snmpGetTable( $snmpPortSecurityTable, 'port security' );
my $nameTable     = snmpGetTable( $snmpPortNameTable,     'port name'     );
my $aliasTable    = snmpGetTable( $snmpPortAliasTable,    'port alias'    );

if( $securityTable && $nameTable && $aliasTable )
{
    foreach $snmpkey ( keys %{$securityTable} )
    {
        if( $snmpkey =~ /$snmpPortSecurityTable\.(\d+)$/ )
        {
            my $t_index = $1;

            my $t_state = $securityTable->{$snmpPortSecurityTable  . '.' . $t_index};
            my $t_name  = $nameTable->{$snmpPortNameTable  . '.' . $t_index};
            my $t_alias = $aliasTable->{$snmpPortAliasTable  . '.' . $t_index};

            print( "Port Security for $t_name - $t_alias - $CISCO_PORTSECURITY_STATES{$t_state}\n" ) if $verbose;

            if( !defined( $port_states{$t_state} ) ) {
                $port_states{$t_state} = 0;
            }
            $port_states{$t_state}++;

            if( $t_state == 2 && $alertOnSecureDown ) {
                &setstate( 'WARNING', "Secure down alert for $t_name - $t_alias" );
            } elsif( $t_state == 0 ) {
                &setstate( 'CRITICAL', "Shutdown alert for $t_name - $t_alias" );
            } elsif( $t_state != 1 && $t_state != 2 ) {
                &setstate( 'WARNING', "Unknown port security state ($t_state) for $t_name - $t_alias" );
            }
        }
    }
}



$session->close;

if( $state eq 'OK' ) {
    print "OK - ";

    while( my ( $key, $value ) = each( %port_states ) ) {
        print "$value $CISCO_PORTSECURITY_STATES{$key}; ";
    }
    print "\n";
}
else {
    print "$answer\n";
}

exit $ERRORS{$state};



sub usage {
  printf "\nUsage:\n";
  printf "\n";
  printf "Perl Cisco port security plugin for Nagios\n\n";
  printf "check_portsecurity.pl -c <READCOMMUNITY> -p <PORT> -h <HOSTNAME>\n\n";
  printf "Checks the operational status of the port security feature on an interface\n";
  printf "  secureup(1) - This indicates port security is operational.\n";
  printf "  securedown(2) - This indicates port security is not operational. This\n";
  printf "     happens when port security is configured to be enabled but could\n";
  printf "     not be enabled due to certain reasons such as conflict with other\n";
  printf "     features.\n";
  printf "  shutdown(3) - This indicates that the port is shutdown due to port\n";
  printf "     security violation when the object cpsIfViolationAction is of type\n";
  printf "     'shutdown'.\n\n";
  printf "Additional options:\n\n";
  printf "  --help                  This help message\n\n";
  printf "  --hostname              The hostname to check\n";
  printf "  --port                  The port to query SNMP on (using: $port)\n";
  printf "  --community             The SNMP access community (using: $community)\n\n";
  printf "  --alert-on-secure-down  If port is in SECUREDOWN state, generate a warning alert\n\n";
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
