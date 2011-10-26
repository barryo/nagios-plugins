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

my %CISCO_PORT_OPER_STATES = (
    '1' => 'UP',
    '2' => 'DOWN',
    '3' => 'TESTING',
    '4' => 'UNKNOWN',
    '5' => 'DORMANT',
    '6' => 'NOT_PRESENT',
    '7' => 'LOWER_LAYER_DOWN'
);

my %port_states;
my %port_admin_states = (
    '1' => 0,
    '2' => 0,
    '3' => 0
);

my $window = 3600;
my $sysuptime = 0;

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

my $allports = 0;

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
            "all-ports",            \$allports,
            "help|?",               \$help,
            "window=i",             \$window
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



if( !( $sysuptime = getSysUptime() ) ) {
    $state  = 'UNKNOWN';
    $answer = "Could not get system uptime";
    print( "$state: $answer" );
    exit $ERRORS{$state};
}

printf( "System up time: %f\n", $sysuptime ) if $verbose;

my $snmpPortOperStatusTable   = '1.3.6.1.2.1.2.2.1.8';
my $snmpPortAdminStatusTable  = '1.3.6.1.2.1.2.2.1.7';
my $snmpPortNameTable         = '1.3.6.1.2.1.31.1.1.1.1';
my $snmpPortAliasTable        = '1.3.6.1.2.1.31.1.1.1.18';
my $snmpPortTypeTable         = '1.3.6.1.2.1.2.2.1.3';
my $snmpPortLastChangeTable   = '1.3.6.1.2.1.2.2.1.9';
my $snmpPortChangeReasonTable = '1.3.6.1.4.1.9.9.276.1.1.2.1.3';

my $operStatus   = snmpGetTable( $snmpPortOperStatusTable,   'port operational status' );
my $adminStatus  = snmpGetTable( $snmpPortAdminStatusTable,  'port admin status' );
my $name         = snmpGetTable( $snmpPortNameTable,         'port name'     );
my $alias        = snmpGetTable( $snmpPortAliasTable,        'port alias'    );
my $type         = snmpGetTable( $snmpPortTypeTable,         'port type'    );
my $lastChange   = snmpGetTable( $snmpPortLastChangeTable,   'port last change at' );
my $changeReason = snmpGetTable( $snmpPortChangeReasonTable, 'port change reason' );

if( $operStatus && $adminStatus && $name && $alias && $lastChange )
{
    foreach $snmpkey ( keys %{$name} )
    {
        if( $snmpkey =~ /$snmpPortNameTable\.(\d+)$/ )
        {
            my $t_index = $1;

            my $t_state        = $operStatus->{$snmpPortOperStatusTable . '.' . $t_index};
            my $t_admin        = $adminStatus->{$snmpPortAdminStatusTable . '.' . $t_index};
            my $t_name         = $name->{$snmpPortNameTable . '.' . $t_index};
            my $t_alias        = $alias->{$snmpPortAliasTable . '.' . $t_index};
            my $t_type         = $type->{$snmpPortTypeTable . '.' . $t_index};
            my $t_lastChange   = $lastChange->{$snmpPortLastChangeTable . '.' . $t_index} / 100.0;
            my $t_changeReason = $changeReason->{$snmpPortChangeReasonTable . '.' . $t_index} if $changeReason;

            if( int( $t_type ) != 6 && !$allports )
            {
                printf( "Skipping $t_name - $t_alias of type $t_type as only checking Ethernet ports\n" ) if $verbose;
                next;
            }

            if( !$t_changeReason ) {
                $t_changeReason = '';
            }

            print( "Port State for $t_name - $t_alias - $CISCO_PORT_OPER_STATES{$t_state} - changed " . ( $sysuptime - $t_lastChange ) . " secs ago because $t_changeReason\n" ) if $verbose;

            if( !defined( $port_states{$t_state} ) ) {
                $port_states{$t_state} = 0;
            }
            $port_states{$t_state}++;

            if( !defined( $port_admin_states{$t_admin} ) ) {
                $port_admin_states{$t_admin} = 0;
            }
            $port_admin_states{$t_admin}++;

            if( ( $sysuptime - $t_lastChange ) <= $window && ( $sysuptime - $t_lastChange ) >= 0 ) {
                &setstate( 'WARNING',
                    sprintf( "Port state change to $CISCO_PORT_OPER_STATES{$t_state} %0.1f mins ago for ${t_name} [DESC: ${t_alias}] [Reason: %s]",
                        $sysuptime - $t_lastChange, defined( $t_changeReason ) ? $t_changeReason : ''
                    )
                );
            }
        }
    }
}



$session->close;

if( $state eq 'OK' ) {
    print "OK - (oper/admin) ";

    while( my ( $key, $value ) = each( %port_states ) ) {
        printf( "%d%s %s; ", $value, defined( $port_admin_states{$key} ) ? "/$port_admin_states{$key}" : '', $CISCO_PORT_OPER_STATES{$key} );
    }
    print "\n";
}
else {
    print "$answer\n";
}

exit $ERRORS{$state};




sub getSysUptime
{
    my $snmpSysUpTime = '.1.3.6.1.2.1.1.3.0';

    return 0 if( !( $response = snmpGetRequest( $snmpSysUpTime, 'system uptime' ) ) );

    return $response->{$snmpSysUpTime} / 100.0;
}


sub usage {
  printf "\nUsage:\n";
  printf "\n";
  printf "Perl Cisco port status plugin for Nagios\n\n";
  printf "check_portstatus.pl -c <READCOMMUNITY> -p <PORT> -h <HOSTNAME>\n\n";
  printf "Checks the operational status of the port and alerts if it changed within\n";
  printf "  \$window seconds ago (default: $window).\n\n";
  printf "Additional options:\n\n";
  printf "  --help                  This help message\n\n";
  printf "  --hostname              The hostname to check\n";
  printf "  --port                  The port to query SNMP on (using: $port)\n";
  printf "  --community             The SNMP access community (using: $community)\n\n";
  printf "  --window                If change occured within \$window seconds ago, then alert\n\n";
  printf "  --all-ports             By default, we only examine Ethernet ports\n\n";
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
