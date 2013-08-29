#!/usr/bin/perl -w

#use strict;
use Net::POP3;

if ($#ARGV != 2)
{
    print "Error in usage: hostname, username & password required";
    exit 3;
}
                
my $hostname = $ARGV[0];
my $username = $ARGV[1];
my $password = $ARGV[2];

my $pop = Net::POP3->new($hostname, Timeout => 60);
my $msgnum = $pop->login($username, $password);
my $retval = 2;

if ( !defined($msgnum) )
{
    print "Critical: unable to log on\r\n";
    $retval = 2;
} else
{
    $retval = 0;
    print "OK: successfully logged in.\r\n";
}
                                                
$pop->quit;
exit $retval;
                                                
                                                