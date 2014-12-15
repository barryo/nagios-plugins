#!/usr/bin/perl -w
#
# check_disk_snmp.pl - nagios plugin
#
# nagios: -epn
#
# Linux / BSD Disk Space Nagios Plugin
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

my $hostname = undef;
my $session;
my $error;
my $response = undef;

my $diskstats = '';

my $usage;
my $verbose = 0;
my $help = 0;
my $manpage = 0;
my $devicesonly = 0;

my $warning = 10;
my $nowarningrecalc = 0;

my $calcmethod = 1;

# Just in case of problems, let's not hang Nagios
$SIG{'ALRM'} = sub {
    print( "ERROR: No snmp response from $hostname\n" );
    exit $ERRORS{"UNKNOWN"};
};
alarm( $TIMEOUT );


$status = GetOptions(
            "hostname=s",        \$hostname,
            "community=s",       \$community,
            "port=i",            \$port,
            "verbose",           \$verbose,
            "warning=i",         \$warning,
            "no-warning-recalc", \$nowarningrecalc,
            "devices-only",      \$devicesonly,
            "calc-method=i",     \$calcmethod,
            "help|?",            \$help,
            "man",               \$manpage
);

pod2usage(-verbose => 2) if $manpage;

pod2usage( -verbose => 1 ) if $help;

pod2usage() if !$status || !$hostname;



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

checkDiskSpace();

$session->close;

if( $state eq 'OK' ) {
    print "OK - $diskstats\n";
}
else {
    print "$answer\n";
}

exit $ERRORS{$state};






sub checkDiskSpace
{
    my $snmpDiskTable     = '1.3.6.1.4.1.2021.9.1';

    my $snmpPath         = $snmpDiskTable . '.2';
    my $snmpDevice       = $snmpDiskTable . '.3';
    my $snmpMinPercent   = $snmpDiskTable . '.5';
    my $snmpTotal        = $snmpDiskTable . '.6';
    my $snmpAvail        = $snmpDiskTable . '.7';
    my $snmpUsed         = $snmpDiskTable . '.8';
    my $snmpPercentUsed  = $snmpDiskTable . '.9';
    my $snmpPercentINode = $snmpDiskTable . '.10';

    return if( !( $response = snmpGetTable( $snmpDiskTable, 'disk table' ) ) );

    $state = 'OK';

    foreach $snmpkey ( keys %{$response} )
    {
        # check each disk (by iterating the paths)
        $snmpkey =~ /$snmpPath/ && do {

            if( $snmpkey =~ /$snmpPath\.(\d+)$/ )
            {
                my $t_index = $1;

                my $t_path         = $response->{$snmpPath          . '.' . $t_index};
                my $t_device       = $response->{$snmpDevice        . '.' . $t_index};
                my $t_minpercent   = $response->{$snmpMinPercent    . '.' . $t_index};
                my $t_total        = $response->{$snmpTotal         . '.' . $t_index};
                my $t_avail        = $response->{$snmpAvail         . '.' . $t_index};
                my $t_used         = $response->{$snmpUsed          . '.' . $t_index};
                my $t_percentused  = $response->{$snmpPercentUsed   . '.' . $t_index};
                my $t_percentinode = $response->{$snmpPercentINode  . '.' . $t_index};


                if( !defined( $t_minpercent ) ) {
                    $t_minpercent = 0;
                }

                if( $devicesonly && $t_device !~ m/^\/dev\// ) {
                    next;
                }

                if( $calcmethod == 2 ) {
                    $t_percentused = ( $t_total - $t_avail ) / ( $t_total / 100.0 );
                }

                if( !defined( $t_percentinode ) ) {
                    $t_percentinode = 'UNKNOWN';
                }

                print( "Disk: $t_path ($t_device) $t_used/$t_total ($t_percentused\%) used with $t_avail available [min: $t_minpercent]"
                    . ". inode usage: $t_percentinode%%\n" ) if $verbose;

                my $t_warning = $warning;
                if( $t_minpercent < 10 && !$nowarningrecalc ) {
                    $t_warning = $t_minpercent * 2;
                }

                $diskstats .= sprintf( "%s (%s) %d%% (inodes: %d%%); ", $t_path, $t_device, $t_percentused, $t_percentinode );

                if( $t_percentused >= ( 100 - $t_minpercent ) ) {
                    &setstate( 'CRITICAL', "Disk usage for $t_path ($t_device) is $t_percentused\%" );
                } elsif( $t_percentused >= ( 100 - $t_minpercent - $t_warning ) ) {
                    &setstate( 'WARNING', "Disk usage for $t_path ($t_device) is $t_percentused\%" );
                }

                if( $t_percentinode != 'UNKNOWN' ) {
                    if( $t_percentinode >= ( 100 - $t_minpercent ) ) {
                        &setstate( 'CRITICAL', "Disk inode usage for $t_path ($t_device) is $t_percentinode\%" );
                    } elsif( $t_percentinode >= ( 100 - $t_minpercent - $t_warning ) ) {
                        &setstate( 'WARNING', "Disk inode usage for $t_path ($t_device) is $t_percentinode\%" );
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

=head1 check_disk_snmp.pl

check_disk_snmp.pl - Nagios plugin to check disks on a server for usage

=head1 SYNOPSIS

check_disk_snmp.pl --hostname <host> [options]

 Options:
   --hostname          the hostname or IP of the server to check
   --port              the port to query for SNMP
   --community         the SNMP community to use
   --verbose           display additional information
   --help              extended help message
   --man               full manual page
   --warning           the percent offset from critical which will generate a warning
   --no-warning-recalc don't automatically recalculate the warning offset (see --man)
   --devices-only      only check disks with devices in /dev/ on the server
   --calc-method       calculation method to use (default 1)

=head1 OPTIONS AND ARGUMENTS

=over 8

=item B<--verbose>

Print additional information including all disks found and their usage states.

=item B<--help>

Expanded help message.

=item B<--man>

Full manual page.

=item B<--warning>

The thresholds for critical alerts are taken from SNMP. This parameter sets the warning
threshold by reducing the required percentage by this value.

=item B<--no-warning-recalc>

The threshold for warning is recalculated for small values of the critical threshold. This stops that
behavior. See --man for more details.

=item B<--devices-only>

Useful to ignore kernel and other virtual filesystems such as /proc, /sys, etc

=item B<--calc-method>

The calculation method to use when calculating free space. See --man for more information.


=back

=head1 DESCRIPTION

B<check_disk_snmp.pl> will read all disk entries via SNMP on the given host
and issue a warning or critical alert if given thresholds are exceeded.

=head2 CONFIGURING YOUR SNMP DAEMON

This script requires that you have configured your SNMP daemon to provide disk
usage information. The typical snmpd.conf configuration would be:

    disk PATH MINPERCENT%
    includeAllDisks MINPERCENT%

You do not need any C<disk> lines if you have C<includeAllDisks> but you can use both such that
the individual C<disk> lines can override the C<includeAllDisks> defaults.

This script takes the C<MINPERCENT%> value B<BUT USES IT AS A CRITICAL THRESHOLD FOR USAGE> -
namely, the critical threshold is C<100% - MINPERCENT%> and the warning threshold is
C<100% - MINPERCENT% - WARNING_OFFSET> where C<WARNING_OFFSET> is set via C<--warning> and
defaults to 10%. For example, if you had:

    disk /      10%
    disk /var   15%

then for the root partition, a warning alert would be generated when usage reaches 80% and a critical
alert when usage reaches 90%. For C</var> then it would be 75% and 85% respectivily.

=head2 AUTORECALULATION OF WARNING

If the C<MINPERCENT%> for a disk is less that 10, then C<--warning> is automatically recalculated for
that disk to C<MINPERCENT% * 2>. To stop this behavior, use C<--no-warning-recalc>.

=head2 CALCULATION METHOD

In practice some systems return unusual / unexpected values in the SNMP table. To get around this,
we have defined multiple calculation methods:

=over 8

=item B<1>

The default. Just takes the used percentage directly from the SNMP table.

=item B<2>

Calcutes the percentage used from the total and available data in the SNMP table
ignoring the used data. I.e.

C<$t_total - $t_avail ) / ( $t_total / 100.0 )>.

=back

=head1 AUTHOR

Written by Barry O'Donovan <barry@opensolutions.ie> as part of the work we
do in Open Solutions (http://www.opensolutions.ie/).

=head1 COPYRIGHT

Copyright (c) 2011, Barry O'Donovan <barry@opensolutions.ie>.
All rights reserved.

Released under the terms of the modified BSD license.
See http://opensource.org/licenses/alphabetical for full text.

=head1 SEE ALSO

https://github.com/barryo/nagios-plugins

=cut
