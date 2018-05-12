#!/usr/bin/env perl
#
# check_bgpstatus.pl - nagios plugin 
#
# Copyright (C) 2018 Nick Hilliard <nick@foobar.org> All Rights Reserved
#
# Redistribution and use in source and binary forms, with or without
# modification, are permitted provided that the following conditions are
# met:
# 
# 1.  Redistributions of source code must retain the above copyright notice,
# this list of conditions and the following disclaimer.
# 
# 2.  Redistributions in binary form must reproduce the above copyright
# notice, this list of conditions and the following disclaimer in the
# documentation and/or other materials provided with the distribution.
# 
# 3.  Neither the name of the copyright holder nor the names of its
# contributors may be used to endorse or promote products derived from this
# software without specific prior written permission.
# 
# THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS
# IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO,
# THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR
# PURPOSE ARE DISCLAIMED.  IN NO EVENT SHALL THE COPYRIGHT HOLDER OR
# CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL,
# EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO,
# PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR
# PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF
# LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
# NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
# SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

use strict;
use warnings;

use Net::SNMP;
use Getopt::Long;
use Data::Dumper;

use constant {
	NAGIOS_OK	=> 0,
	NAGIOS_WARNING	=> 1,
	NAGIOS_CRITICAL	=> 2,
	NAGIOS_UNKNOWN	=> 3,
};

my $err = {
	0	=> 'OK',
	1	=> 'WARNING',
	2	=> 'CRITICAL',
	3	=> 'UNKNOWN',
};

my $host;
my $debug = 0;
my $community = 'public';
my $port = 161;
my $timeout = 30;

GetOptions(
	'debug!'		=> \$debug,
	'host=s'		=> \$host,
	'community=s'		=> \$community,
	'port=s'		=> \$port,
	'timeout=i'		=> \$timeout,
);

if (!$host) {
	verboseexit (NAGIOS_CRITICAL, "no hostname provided");
}

my $oids = {
	'bgpPeerState'		=> '1.3.6.1.2.1.15.3.1.2',
	'bgpPeerAdminStatus'	=> '1.3.6.1.2.1.15.3.1.3',
	'bgpPeerLocalAddr'	=> '1.3.6.1.2.1.15.3.1.5',
	'bgpPeerRemoteAddr'	=> '1.3.6.1.2.1.15.3.1.7',
	'bgpPeerRemoteAs'	=> '1.3.6.1.2.1.15.3.1.9',
	'bgpPeerLastError'	=> '1.3.6.1.2.1.15.3.1.14',
};

my $bgperrorcodes = {
	1 => 'Message Header Error',
	2 => 'OPEN Message Error',
	3 => 'UPDATE Message Error',
	4 => 'Hold Timer Expired',
	5 => 'Finite State Machine Error',
	6 => 'Cease',
	7 => 'ROUTE-REFRESH Message Error',
};

# BGP Error Subcodes
my $bgpsuberrorcodes = {
	# Message Header Error subcodes
	1 => {
		1 => 'Connection Not Synchronized',
		2 => 'Bad Message Length',
		3 => 'Bad Message Type',
	},

	# OPEN Message Error subcodes
	2 => {
		1 => 'Unsupported Version Number',
		2 => 'Bad Peer AS',
		3 => 'Bad BGP Identifier',
		4 => 'Unsupported Optional Parameter',
		5 => '[Deprecated]',
		6 => 'Unacceptable Hold Time',
		7 => 'Unsupported Capability',
	},

	# UPDATE Message Error subcodes
	3 => {
		1 => 'Malformed Attribute List',
		2 => 'Unrecognized Well-known Attribute',
		3 => 'Missing Well-known Attribute',
		4 => 'Attribute Flags Error',
		5 => 'Attribute Length Error',
		6 => 'Invalid ORIGIN Attribute',
		7 => '[Deprecated]',
		8 => 'Invalid NEXT_HOP Attribute',
		9 => 'Optional Attribute Error',
		10 => 'Invalid Network Field',
		11 => 'Malformed AS_PATH',
	},

	# BGP Finite State Machine Error Subcodes
	5 => {
		1 => 'Receive Unexpected Message in OpenSent State',
		2 => 'Receive Unexpected Message in OpenConfirm State',
		3 => 'Receive Unexpected Message in Established State',
	},

	# BGP Cease NOTIFICATION message subcodes
	6 => {
		1 => 'Maximum Number of Prefixes Reached',
		2 => 'Administrative Shutdown',
		3 => 'Peer De-configured',
		4 => 'Administrative Reset',
		5 => 'Connection Rejected',
		6 => 'Other Configuration Change',
		7 => 'Connection Collision Resolution',
		8 => 'Out of Resources',
		9 => 'Hard Reset (TEMPORARY - registered 2017-04-21, expires 2018-04-21) [draft-ietf-idr-bgp-gr-notification]'
	},

	# BGP ROUTE-REFRESH Message Error subcodes
	7 => {
		1 => 'Invalid Message Length',
	},
};

sub bgplasterror {
	my ($bgperror) = @_;

	my $lasterror = hex($bgperror);
	my $errcode = ($lasterror & 0xff00) >> 8;
	my $errsubcode = ($lasterror & 0xff);

	my $errtext;

	if (defined ($bgperrorcodes->{$errcode})) {
		$errtext = $bgperrorcodes->{$errcode};
	}
	if (defined ($bgpsuberrorcodes->{$errcode}->{$errsubcode})) {
		$errtext .= ": ".$bgpsuberrorcodes->{$errcode}->{$errsubcode}
	}
	
	return $errtext;
}

$SIG{'ALRM'} = sub {
	verboseexit (NAGIOS_CRITICAL, "SNMP response timeout for $host");
};
alarm($timeout);

sub verboseexit {
	my ($errval, $notice) = @_;
	
	print $err->{$errval}.": $notice\n";
	exit $errval;
}

my $state;
foreach my $oid (keys %{$oids}) {
	my ($session, $error) = Net::SNMP->session(
		hostname	=> $host,
		community	=> $community,
		port		=> $port
	);

	if (!defined($session)) {
		verboseexit (NAGIOS_UNKNOWN, $error);
	}
	
	my $response = $session->get_table($oids->{$oid});
	if (!defined ($response)) {
		my $sessionerror = $session->error;
		$session->close;
		verboseexit (NAGIOS_CRITICAL, "$host: $sessionerror: $oid");
	}

	foreach my $responseoid (keys %{$response}) {
		next unless ($responseoid =~ /.*\.(\d+\.\d+\.\d+\.\d+$)/);
		my $ipaddr =  $1;
		$state->{$oid}->{$ipaddr} = $response->{$responseoid};
	}

	$session->close;
}

$debug && print Dumper ($state);

my $shutdown = 0;
my $inactive = 0;
my $established = 0;
my @warnings;

foreach my $ip (keys %{$state->{bgpPeerRemoteAddr}}) {
	if ($state->{bgpPeerAdminStatus}->{$ip} == 1) {
		$shutdown++;
	} elsif ($state->{bgpPeerState}->{$ip} == 6) {
		$established++
	} else {
		$inactive++;
		my $errtext = bgplasterror($state->{bgpPeerLastError}->{$ip});
		my $lasterror = hex($state->{bgpPeerLastError}->{$ip});
		my $errcode = ($lasterror & 0xff00) >> 8;
		my $errsubcode = ($lasterror & 0xff);
		my $peerwarning = sprintf ("ip: $ip, asn: ".$state->{bgpPeerRemoteAs}->{$ip}.", errcode: $errcode, subcode: $errsubcode");
		if (defined($errtext)) {
			$peerwarning .= sprintf (", text: $errtext");
		}
		push @warnings, $peerwarning;
	}
}

$debug && print Dumper (@warnings);

my $errval = $inactive ? NAGIOS_WARNING : NAGIOS_OK;
my $peertext = '';
if (@warnings) {
	$peertext = " (".join ("/", @warnings).")";
}

verboseexit ($errval, "host '$host', sessions up: $established, down: $inactive, shutdown: $shutdown".$peertext );

__END__

       @output = `$whois -T aut-num AS$bgpStatus{$key}{$snmpbgpPeerRemoteAs}`;
