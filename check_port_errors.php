#! /usr/bin/php
<?php

/**
 * check_port_errors.php - Nagios plugin
 *
 *
 * This file is part of "barryo / nagios-plugins" - a library of tools and
 * utilities for Nagios developed by Barry O'Donovan
 * (http://www.barryodonovan.com/) and his company, Open Solutions
 * (http://www.opensolutions.ie/).
 *
 * Copyright (c) 2004 - 2014, Open Source Solutions Limited, Dublin, Ireland
 * All rights reserved.
 *
 * Contact: Barry O'Donovan - info (at) opensolutions (dot) ie
 *          http://www.opensolutions.ie/
 *
 * LICENSE
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 *  * Redistributions of source code must retain the above copyright notice, this
 *    list of conditions and the following disclaimer.
 *
 *  * Redistributions in binary form must reproduce the above copyright notice, this
 *    list of conditions and the following disclaimer in the documentation and/or
 *    other materials provided with the distribution.
 *
 *  * Neither the name of Open Solutions nor the names of its contributors may be
 *    used to endorse or promote products derived from this software without
 *    specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
 * INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF
 * LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE
 * OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED
 * OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @package    barryo / nagios-plugins
 * @copyright  Copyright (c) 2004 - 2014, Open Source Solutions Limited, Dublin, Ireland
 * @license    http://www.opensolutions.ie/licenses/new-bsd New BSD License
 * @link       http://www.opensolutions.ie/ Open Source Solutions Limited
 * @author     Barry O'Donovan <info@opensolutions.ie>
 * @author     The Skilled Team of PHP Developers at Open Solutions <info@opensolutions.ie>
 */

date_default_timezone_set('Europe/Dublin');
define( "VERSION", '1.0.0' );

ini_set( 'max_execution_time', '55' );

ini_set( 'display_errors', true );
ini_set( 'display_startup_errors', true );

define( "STATUS_OK",       0 );
define( "STATUS_WARNING",  1 );
define( "STATUS_CRITICAL", 2 );
define( "STATUS_UNKNOWN",  3 );

define( "LOG__NONE",    0 );
define( "LOG__ERROR",   1 );
define( "LOG__VERBOSE", 2 );
define( "LOG__DEBUG",   3 );

// initialise some variables
$status    = STATUS_OK;
$log_level = LOG__NONE;


// possible output strings
$criticals = "";
$warnings  = "";
$unknowns  = "";
$normals   = "";

// set default values for command line arguments
$cmdargs = [
    'port'               => '161',
    'log_level'          => LOG__NONE
];

// port interfaces to ignore.
//
// The array key is the hostname.  The value is the shortened snmp name.

$ignoreports = [
//    'core-router02.example.com'	=> 'Gi0/19',
];

// parse the command line arguments
parseArguments();


// create a memcache key:
$MCKEY = 'NAGIOS_CHECK_PORT_ERRORS_' . md5( $cmdargs['host'] );

if( !class_exists( 'Memcache' ) )
    die( "ERROR: php5-memcache is required\n" );

$mc = new Memcache;
$mc->connect( 'localhost', 11211 ) or die( "ERROR: Could not connect to memcache on localhost:11211\n" );

_log( "Connected to Memcache with version: " . $mc->getVersion(), LOG__DEBUG );


require 'OSS_SNMP/OSS_SNMP/SNMP.php';

$snmp = new \OSS_SNMP\SNMP( $cmdargs['host'], $cmdargs['community'] );

// get interface types for later filtering
$types = $snmp->useIface()->types();
$names = filterForType( $snmp->useIface()->names(), $types, OSS_SNMP\MIBS\Iface::IF_TYPE_ETHERNETCSMACD );

_log( "Found " . count( $names ) . " physical ethernet ports", LOG__DEBUG );


// get current error counters
$ifInErrors  = filterForType( $snmp->useIface()->inErrors(),  $types, OSS_SNMP\MIBS\Iface::IF_TYPE_ETHERNETCSMACD );
$ifOutErrors = filterForType( $snmp->useIface()->outErrors(), $types, OSS_SNMP\MIBS\Iface::IF_TYPE_ETHERNETCSMACD );

_log( "Found " . count( $ifInErrors  ) . " entries for in errors on physical ethernet ports", LOG__DEBUG );
_log( "Found " . count( $ifOutErrors ) . " entries for out errors on physical ethernet ports", LOG__DEBUG );

// delete unwanted entries
foreach ( array_keys( $ignoreports ) as $hostkey) {

    if ($cmdargs[ 'host' ] == $hostkey) {
        $portid = array_keys( $names, $ignoreports[ $hostkey ] )[0];

        if( isset ($portid) ) {
            unset( $ifInErrors[ $portid ] );
            unset( $ifOutErrors[ $portid ] );
        }

    }

}


// if we don't have any entries already, set them and send an unknown
$cache = $mc->get( $MCKEY );

// save the new values to memcache
$newcache = [
    'ifInErrors'  => $ifInErrors,
    'ifOutErrors' => $ifOutErrors,
    'timestamp'   => time()
];

if( !$mc->set( $MCKEY, $newcache, 0, 900 ) ) {
    echo "UNKNOWN - could not update cache\n";
    exit( STATUS_UNKNOWN );
}

if( $cache === false ) {
    echo "UNKNOWN - no previous cached entries found\n";
    exit( STATUS_UNKNOWN );
}

$msg = '';

foreach( [ 'IN' => 'ifInErrors', 'OUT' => 'ifOutErrors' ] as $dir => $name )
{
    $result = subtractArray( $$name,  $cache[ $name ] );

    if( count( $result ) ) {
        setStatus( STATUS_CRITICAL );

        if( $criticals == '' )
            $criticals = 'CRITICAL: In the last ' . ( time() - $cache['timestamp'] ) . ' seconds, port errors were found.';

        $criticals .= " [DIRECTION: {$dir}] =>";

        foreach( $result as $k => $v )
            $criticals .= " " . $names[ $k ] . ": {$v} errors;";

        $criticals .= ' ***';
    }
    else
        $normals .= " No errors found for $dir packets on all interfaces.";
}


if( $status == STATUS_OK )
    $msg = "OK -{$normals}\n";
else
    $msg .= "{$criticals}{$warnings}{$unknowns}{$normals}\n";

echo $msg;
exit( $status );


function subtractArray( $new, $old )
{
    $result = [];
    foreach( $new as $k => $v )
        if( $v - $old[$k] > 0 )
            $result[$k] = $v - $old[$k];

    return $result;
}



function filterForType( $ports, $types, $type )
{
    foreach( $ports as $k => $v )
        if( $types[$k] !== $type )
            unset( $ports[ $k ] );

    return $ports;
}



/**
 * Parses (and checks some) command line arguments
 */
function parseArguments()
{
    global $checkOptions, $cmdargs, $argc, $argv;

    if( $argc == 1 )
    {
        printUsage( true );
        exit( STATUS_UNKNOWN );
    }

    $i = 1;


    while( $i < $argc )
    {
        if( $argv[$i][0] != '-' )
        {
            $i++;
            continue;
        }

        switch( $argv[$i][1] )
        {

            case 'V':
                printVersion();
                exit( STATUS_OK );
                break;

            case 'v':
                $cmdargs['log_level'] = LOG__VERBOSE;
                $i++;
                break;

            case 'd':
                $cmdargs['log_level'] = LOG__DEBUG;
                $i++;
                break;

            case 'c':
                $cmdargs['community'] = $argv[$i+1];
                $i++;
                break;

            case 'h':
                $cmdargs['host'] = $argv[$i+1];
                $i++;
                break;

            case 'p':
                $cmdargs['port'] = $argv[$i+1];
                $i++;
                break;

            case '?':
                printHelp();
                exit( STATUS_OK );
                break;

            default:
                if( !isset( $argv[$i+1] ) || substr( $argv[$i+1], 0, 1 ) == '-' )
                    $cmdargs[ substr( $argv[$i], 2 ) ] = true;
                else
                    $cmdargs[ substr( $argv[$i], 2 ) ] = $argv[$i+1];

                $i++;
                break;
        }

    }

}


/**
 * Sets the planned exit status without overriding a previous error states.
 *
 * @param int $new_status New status to set.
 * @return void
 */
function setStatus( $new_status )
{
    global $status;

    if( $new_status > $status )
        $status = $new_status;
}

/**
 * Prints a given message to stdout (or stderr as appropriate).
 *
 * @param string $log The log message.
 * @param int $level The log level the user has requested.
 * @return void
 */
function _log( $log, $level )
{
    global $cmdargs;

    if( $level == LOG__ERROR )
        fwrite( STDERR, "$log\n" );
    else if( $level <= $cmdargs['log_level'] )
        print( $log . "\n" );
}


/**
 * Print script usage instructions to the stadout
 */
function printUsage()
{
    global $argv;
    $progname = basename( $argv[0] );

    echo <<<END_USAGE
{$progname} -c <READCOMMUNITY> -p <PORT> -h <HOSTNAME> [-V] [-h] [-?] [--help]

END_USAGE;

}


/**
 * Print version information
 */
function printVersion()
{
    global $argv;

    printf( basename( $argv[0] ) . " (Nagios Plugin) %s\n", VERSION );
    echo "Licensed under the New BSD License\n\n";

    echo <<<LICENSE
THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF
LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE
OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED
OF THE POSSIBILITY OF SUCH DAMAGE.

LICENSE;
}


function printHelp()
{
    global $argv;
    $progname = basename( $argv[0] );

    echo <<<END_USAGE

{$progname} - Nagios plugin to check for incrementing port errors'
Copyright (c) 2014 Open Source Solutions Ltd - http://www.opensolutions.ie/

{$progname} -c <READCOMMUNITY> -p <PORT> -h <HOSTNAME> [-V] [-?] [--help]

WARNING: SCRIPT IS HARD CODED TO CONNECT TO A MEMCACHE INSTANCE ON:
    localhost:11211

Options:

 -?,--help
    Print detailed help screen.
 -V
    Print version information.
 -c
    SNMP Community (public)
 -h
    Device to poll
 -p
    SNMP port (default 161)
 -v
    Verbose output
 -d
    Debug output


END_USAGE;

}
