#!/usr/bin/perl -w
#
# check_processes_snmp.pl - nagios plugin
#
# nagios: -epn
#
# Process Check via SNMP
#
# Copyright (c) 2012, Barry O'Donovan <barry@opensolutions.ie>
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
use Pod::Usage;

my %ERRORS = (
               'UNSET',   -1,
               'OK' ,      0,
               'WARNING',  1,
               'CRITICAL', 2,
               'UNKNOWN',  3
);

my $status;
my $TIMEOUT = 20;
my $state = "UNSET";
my $answer = "";
my $int;
my $snmpkey;
my $key;
my $community = "public";
my $port = 161;
my $snmpversion = '2c';
my $username = undef;
my $authprotocol = 'sha1';
my $authpassword = undef;
my $privprotocol = 'aes';
my $privpassword;

my $hostname = undef;
my $session;
my $error;
my $response = undef;

my $procstats = "";

my $usage;
my $verbose = 0;
my $help = 0;
my $manpage = 0;

# Just in case of problems, let's not hang Nagios
$SIG{'ALRM'} = sub {
    print( "ERROR: No snmp response from $hostname\n" );
    exit $ERRORS{"UNKNOWN"};
};
alarm( $TIMEOUT );


$status = GetOptions(
            "hostname=s"         => \$hostname,
            "community=s"        => \$community,
            "port=i"             => \$port,
            "verbose"            => \$verbose,
            "help|?"             => \$help,
            "man"                => \$manpage,
            "snmpversion=s"      => \$snmpversion,
            "username=s"         => \$username,
            "authprotocol=s"     => \$authprotocol,
            "authpassword=s"     => \$authpassword,
            "privprotocol=s"     => \$privprotocol,
            "privpassword=s"     => \$privpassword,
);

pod2usage(-verbose => 2) if $manpage;

pod2usage( -verbose => 1 ) if $help;

pod2usage() if !$status || !$hostname;

my @sessionargs = (
        hostname        => $hostname,
        port            => $port,
        version         => $snmpversion,
        translate       => 0
);

if ($snmpversion eq '3') {
    push @sessionargs, (
        username        => $username,
        authprotocol    => $authprotocol,
        authpassword    => $authpassword,
        privprotocol    => $privprotocol,
        privpassword    => $privpassword,
    );
} else {
    push @sessionargs, (
        community       => $community,
    );
}

($session, $error) = Net::SNMP->session(@sessionargs);

if( !defined( $session ) )
{
    $state  = 'UNKNOWN';
    $answer = $error;
    print( "$state: $answer" );
    exit $ERRORS{$state};
}

checkProcesses();

$session->close;

if( $state eq 'OK' ) {
    print "OK - $procstats\n";
}
else {
    print "$answer\n";
}

exit $ERRORS{$state};






sub checkProcesses
{
    my $snmpProcessTable     = '.1.3.6.1.4.1.2021.2.1';

    my $prName          = $snmpProcessTable . '.2';
    my $prMin           = $snmpProcessTable . '.3';
    my $prMax           = $snmpProcessTable . '.4';
    my $prCount         = $snmpProcessTable . '.5';
    my $prErrorFlag     = $snmpProcessTable . '.100';
    my $prErrMessage    = $snmpProcessTable . '.101';

    return if( !( $response = snmpGetTable( $snmpProcessTable, 'process table' ) ) );

    $state = 'OK';

    foreach $snmpkey ( keys %{$response} )
    {
        # check each process
        $snmpkey =~ /$prName/ && do {

            if( $snmpkey =~ /$prName\.(\d+)$/ )
            {
                my $t_index = $1;

                my $t_name     = $response->{$prName       . '.' . $t_index};
                my $t_min      = $response->{$prMin        . '.' . $t_index};
                my $t_max      = $response->{$prMax        . '.' . $t_index};
                my $t_count    = $response->{$prCount      . '.' . $t_index};
                my $t_err_flag = $response->{$prErrorFlag  . '.' . $t_index};
                my $t_err_msg  = $response->{$prErrMessage . '.' . $t_index};

                print( "Process: $t_name  $t_min <= $t_count <= $t_max   => Err: $t_err_flag - [$t_err_msg]\n" ) if $verbose;

                $procstats .= sprintf( "%s (%d<=%d<=%d); ", $t_name, $t_min, $t_count, $t_max );

                if( $t_err_flag )
                {
                    if( $t_count == 0 && $t_min != 0 ) {
                        &setstate( 'CRITICAL', "$t_name not running! (${t_err_msg})" );
                    } elsif( $t_count < $t_min ) {
                        &setstate( 'WARNING', "Too few $t_name running (${t_min} <= ${t_count} <= ${t_max})! [${t_err_msg}]" );
                    } else {
                        &setstate( 'WARNING', "Too many $t_name running (${t_min} <= ${t_count} <= ${t_max})! [${t_err_msg}]" );
                    }
                }
            }
        }
    }
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


__END__

=head1 check_processes_snmp.pl

check_processes_snmp.pl - Nagios plugin to check processes via SNMP as defined in SNMP

=head1 SYNOPSIS

check_processes_snmp.pl --hostname <host> [options]

 Options:
   --hostname          the hostname or IP of the server to check
   --port              the port to query for SNMP
   --community         the SNMP community to use
   --verbose           display additional information
   --help              extended help message
   --man               full manual page
   --snmpversion       specify the SNMP version (2c or 3)
   --username          specify the SNMPv3 username
   --authprotocol      specify the SNMPv3 authentication protocol
   --authpassword      specify the SNMPv3 authentication username
   --privprotocol      specify the SNMPv3 privacy protocol
   --privpassword      specify the SNMPv3 privacy username

=head1 OPTIONS AND ARGUMENTS

=over 8

=item B<--verbose>

Print additional information including all disks found and their usage states.

=item B<--help>

Expanded help message.

=item B<--man>

Full manual page.

=back

=head1 DESCRIPTION

B<check_processes_snmp.pl> will read all process entries via SNMP on the given host
and issue a warning for too many / too few processes running or critical alert if 0
processes are running (and min != 0)

=head2 CONFIGURING YOUR SNMP DAEMON

This script requires that you have configured your SNMP daemon to provide process
information. The typical snmpd.conf configuration (for bsnmpd) would be:

    prNames.2 = "sendmail"
    prMin.2 = 1
    prMax.2 = 5

=head1 AUTHOR

Written by Barry O'Donovan <barry@opensolutions.ie> as part of the work we
do in Open Solutions (http://www.opensolutions.ie/).

=head1 COPYRIGHT

Copyright (c) 2012, Barry O'Donovan <barry@opensolutions.ie>.
All rights reserved.

Released under the terms of the modified BSD license.
See http://opensource.org/licenses/alphabetical for full text.

=head1 SEE ALSO

https://github.com/barryo/nagios-plugins

=cut
