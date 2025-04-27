#! /usr/bin/php
<?php

// 20250427 - this is a REAL hack! It works for MX204 but it's not pretty.

/**
 * check_chassis_juniper_mx204.php - Nagios plugin
 *
 *
 * This file is part of "barryo / nagios-plugins" - a library of tools and
 * utilities for Nagios developed by Barry O'Donovan
 * (http://www.barryodonovan.com/) and his company, Open Solutions
 * (http://www.opensolutions.ie/).
 *
 * Copyright (c) 2004 - 2025, Open Source Solutions Limited, Dublin, Ireland
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
 * @copyright  Copyright (c) 2004 - 2025, Open Source Solutions Limited, Dublin, Ireland
 * @license    http://www.opensolutions.ie/licenses/new-bsd New BSD License
 * @link       http://www.opensolutions.ie/ Open Source Solutions Limited
 * @author     Barry O'Donovan <info@opensolutions.ie>
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
    'log_level'          => LOG__NONE,
    'memwarn'            => 80,
    'memcrit'            => 90,
    'tempwarn'           => 55,
    'tempcrit'           => 65,
    'reboot'             => 3600,
    'thres-cpu'          => '85,95',
    'target'             => null,
];


// parse the command line arguments
parseArguments();


//print_r( $cmdargs ); die();

require 'OSS_SNMP/OSS_SNMP/SNMP.php';

$snmp = new \OSS_SNMP\SNMP( $cmdargs['host'], $cmdargs['community'] );

//         FANS|POWER|TEMPERATURE|MEMORY|CPU|UPTIME

if( $cmdargs['target'] === null || $cmdargs['target'] == 'CPU' )
    checkCPU();

if( $cmdargs['target'] === null || $cmdargs['target'] == 'UPTIME' )
    checkReboot();

if( $cmdargs['target'] === null || $cmdargs['target'] == 'POWER' )
    checkPower();

if( $cmdargs['target'] === null || $cmdargs['target'] == 'FANS' )
    checkFans();

if( $cmdargs['target'] === null || $cmdargs['target'] == 'TEMPERATURE' )
    checkTemperature();

if( $cmdargs['target'] === null || $cmdargs['target'] == 'MEMORY' )
    checkMemory();


if( $status == STATUS_OK )
    $msg = "OK -{$normals}\n";
else
    $msg = "{$criticals}{$warnings}{$unknowns}\n";

echo $msg;
exit( $status );



/**
 * Checks the chassis temperature
 *
 */
function checkTemperature()
{
    global $snmp, $cmdargs, $periods, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-temp'] ) && $cmdargs['skip-temp'] )
        return;

    _log( "========== Temp check start ==========", LOG__DEBUG );

    // https://oidref.com/1.3.6.1.4.1.2636.3.1.15.1.8

    $fruNames = $snmp->realWalk( 'iso.3.6.1.4.1.2636.3.1.15.1.5' );

    foreach( $fruNames as $oid => $name ) {

        $oids = explode( '.', $oid );
        $noid = $oids[13] . '.' . $oids[14] . '.' . $oids[15];

        $fruNames[ $noid ] = $snmp->parseSnmpValue( $name );
        unset( $fruNames[$oid]);
    }

    $fruTemps = $snmp->realWalk( 'iso.3.6.1.4.1.2636.3.1.15.1.9' );

    foreach( $fruTemps as $oid => $name ) {

        $oids = explode( '.', $oid );
        $noid = $oids[13] . '.' . $oids[14] . '.' . $oids[15];

        $fruTemps[ $noid ] = $snmp->parseSnmpValue( $name );
        unset( $fruTemps[$oid]);
    }

    $tempdata = 'Temp sensors:';

    foreach( $fruTemps as $i => $temp )
    {
        if( !$temp ) {
            continue;
        }

        $ok = ($temp < 65 );
        
        _log( "Temp: {$fruNames[$i]} - " . $temp . 'C', LOG__VERBOSE );
        $tempdata .= " [$fruNames[$i] - " . $temp . 'C' . "]";

        if( !$ok ) {
            setStatus( STATUS_CRITICAL );
            $criticals .= "Temp {$fruNames[$i]} is {$state}C - check thresholds and ensure this is okay! ";
        }
    }

    $normals .= " $tempdata.";

    _log( "========== Temperature check end ==========\n", LOG__DEBUG );
}


function checkFans()
{
    global $snmp, $cmdargs, $periods, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-fans'] ) && $cmdargs['skip-fans'] )
        return;

    _log( "========== Fan check start ==========", LOG__DEBUG );

    // https://oidref.com/1.3.6.1.4.1.2636.3.1.15.1.8

    $fanNames = $snmp->realWalk( 'iso.3.6.1.4.1.2636.3.1.15.1.5.4' );

    foreach( $fanNames as $oid => $name ) {
        $fanNames[ substr( $oid, -5 ) ] = $snmp->parseSnmpValue( $name );
        unset( $fanNames[$oid]);
    }

    $fanStates = $snmp->realWalk( 'iso.3.6.1.4.1.2636.3.1.15.1.8.4' );

    foreach( $fanStates as $oid => $name ) {
        $fanStates[ substr( $oid, -5 ) ] = $snmp->parseSnmpValue( $name );
        unset( $fanStates[$oid]);
    }

    $states = [
        1  => 'unknown(1)',
        2  => 'empty(2)',
        3  => 'present(3)',
        4  => 'ready(4)',
        5  => 'announceOnline(5)',
        6  => 'online(6)',
        7  => 'anounceOffline(7)',
        8  => 'offline(8)',
        9  => 'diagnostic(9)',
        10 => 'standby(10)',
    ];

    $fandata = 'Fans:';

    foreach( $fanStates as $i => $state )
    {
        $ok = $state == 6 ? true : false;
        
        _log( "Fan: {$fanNames[$i]} - " . $states[$state], LOG__VERBOSE );
        $fandata .= " [$fanNames[$i] - " . $states[$state] . "]";

        if( !$ok ) {
            switch( $state ) {
                case 7:
                case 8:
                    setStatus( STATUS_CRITICAL );
                    $criticals .= "Fan {$fanNames[$i]} is {$states[$state]}. ";
                    break;
                default:
                    setStatus( STATUS_WARNING );
                    $warnings .= "Fan {$fanNames[$i]} is {$states[$state]}. ";
                    break;
            }
        }
    }

    $normals .= " $fandata.";

    _log( "========== Fan check end ==========\n", LOG__DEBUG );
}



function checkPower()
{
    global $snmp, $cmdargs, $periods, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-psu'] ) && $cmdargs['skip-psu'] )
        return;

    _log( "========== PSU check start ==========", LOG__DEBUG );

    // https://oidref.com/1.3.6.1.4.1.2636.3.1.15.1.8

    // PEM - power entry module
    $pemNames = $snmp->realWalk( 'iso.3.6.1.4.1.2636.3.1.15.1.5.2' );

    foreach( $pemNames as $oid => $name ) {
        $pemNames[ substr( $oid, -5 ) ] = $snmp->parseSnmpValue( $name );
        unset( $pemNames[$oid]);
    }

    $pemStates = $snmp->realWalk( 'iso.3.6.1.4.1.2636.3.1.15.1.8.2' );

    foreach( $pemStates as $oid => $name ) {
        $pemStates[ substr( $oid, -5 ) ] = $snmp->parseSnmpValue( $name );
        unset( $pemStates[$oid]);
    }

    $states = [
        1  => 'unknown(1)',
        2  => 'empty(2)',
        3  => 'present(3)',
        4  => 'ready(4)',
        5  => 'announceOnline(5)',
        6  => 'online(6)',
        7  => 'anounceOffline(7)',
        8  => 'offline(8)',
        9  => 'diagnostic(9)',
        10 => 'standby(10)',
    ];

    $psudata = 'PSUs:';

    foreach( $pemStates as $i => $state )
    {
        $ok = $state == 6 ? true : false;
        
        _log( "PSU: {$pemNames[$i]} - " . $states[$state], LOG__VERBOSE );
        $psudata .= " [$pemNames[$i] - " . $states[$state] . "]";

        if( !$ok ) {
            switch( $state ) {
                case 7:
                case 8:
                    setStatus( STATUS_CRITICAL );
                    $criticals .= "{SU} {$pemNames[$i]} is {$states[$state]}. ";
                    break;
                default:
                    setStatus( STATUS_WARNING );
                    $warnings .= "PSU {$pemNames[$i]} is {$states[$state]}. ";
                    break;
            }
        }
    }

    $normals .= " $psudata.";


    _log( "========== PSU check end ==========\n", LOG__DEBUG );
}



function checkMemory()
{
    global $snmp, $cmdargs, $periods, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-mem'] ) && $cmdargs['skip-mem'] )
        return;

    _log( "========== Memory check start ==========", LOG__DEBUG );

    $memUtil = $snmp->get('1.3.6.1.4.1.2636.3.1.13.1.27.9.1.0.0');

    $memData = " Memory (usage%):";

    if( $memUtil > $cmdargs['memcrit'] )
    {
        setStatus( STATUS_CRITICAL );
        $criticals .= "Memory usage at {$memUtil}%. ";
    }
    else if( $memUtil > $cmdargs['memwarn'] )
    {
        setStatus( STATUS_WARNING );
        $warningss .= "Memory usage at {$memUtil}%. ";
    }
    else
    {
        $memData .= " {$memUtil}%";
    }

        $normals .= $memData . '. ';

    _log( "========== Memory check end ==========\n", LOG__DEBUG );
}


function checkReboot()
{
    global $snmp, $cmdargs, $periods, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-reboot'] ) && $cmdargs['skip-reboot'] )
        return;

    _log( "========== Reboot check start ==========", LOG__DEBUG );

    $bootTimeSecs  = $snmp->useJuniper()->bootTime() / 100;


    if( ( isset( $cmdargs['lastcheck'] ) && $cmdargs['lastcheck'] && $bootTimeSecs <= $cmdargs['lastcheck'] )
            || ( $bootTimeSecs <= $cmdargs['reboot'] ) )
    {
        setStatus( STATUS_CRITICAL );
        $criticals .= sprintf( "Device rebooted %0.1f minutes ago. ", ( $bootTimeSecs / 60.0 ) );
    }

    $up = $bootTimeSecs / 60.0 / 60.0;

    if( $up < 24 )
        $up = sprintf( "Uptime: %0.1f hours. ", $up );
    else
        $up = sprintf( "Uptime: %0.1f days. ", $up / 24 );

    _log( "Last reboot: " . ( $bootTimeSecs / 60.0 ) . " minutes ago", LOG__VERBOSE );
    _log( $up, LOG__VERBOSE );

    $normals .= " {$up}";

    _log( "========== Reboot check end ==========", LOG__DEBUG );
}



function checkCPU()
{
    global $snmp, $cmdargs, $periods, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-cpu'] ) && $cmdargs['skip-cpu'] )
        return;

    _log( "========== CPU check start ==========", LOG__DEBUG );

    $util1m   = $snmp->useJuniper()->loadAverage1min();
    $util5m   = $snmp->useJuniper()->loadAverage5min();
    $util15m  = $snmp->useJuniper()->loadAverage15min();

    _log( "CPU: 1min - $util1m%; 5min - $util5m%; 15min - $util15m%; ", LOG__VERBOSE );
    $cpudata = "CPU: 1min - $util1m%; 5min - $util5m%; 15min - $util15m%";

    list( $w, $c ) = explode( ',', $cmdargs["thres-cpu"] );

    if( $util5m >= $c )
    {
        setStatus( STATUS_CRITICAL );
        $criticals .= "CPU 5min usage at {$util}%. ";
    }
    else if( $util5m >= $w )
    {
        setStatus( STATUS_WARNING );
        $warnings .= "CPU 5min usage at {$util}%. ";
    }

    $normals .= " $cpudata.";
}



/**
 * Parses (and checks some) command line arguments
 */
function parseArguments()
{
    global $checkOptions, $cmdargs, $checksEnabled, $periods, $periodsEnabled, $argc, $argv;

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
                if( !isset( $argv[$i+1] ) ) { printUsage( true ); exit( STATUS_UNKNOWN ); }
                $cmdargs['community'] = $argv[$i+1];
                $i++;
                break;

            case 't':
                if( !isset( $argv[$i+1] ) ) { printUsage( true ); exit( STATUS_UNKNOWN ); }
                $cmdargs['target'] = $argv[$i+1];
                $i++;
                break;
    
            case 'h':
                if( !isset( $argv[$i+1] ) ) { printUsage( true ); exit( STATUS_UNKNOWN ); }
                $cmdargs['host'] = $argv[$i+1];
                $i++;
                break;

            case 'p':
                if( !isset( $argv[$i+1] ) ) { printUsage( true ); exit( STATUS_UNKNOWN ); }
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
    global $argv, $cmdargs;
    $progname = basename( $argv[0] );

    echo <<<END_USAGE

{$progname} - Nagios plugin to check the status of Brocade chassis'
Copyright (c) 2004 - 2013 Open Source Solutions Ltd - http://www.opensolutions.ie/

{$progname} -c <READCOMMUNITY> -p <PORT> -h <HOSTNAME> [-V] [-?] [--help]

Options:

 -?,--help
    Print detailed help screen.
 -V
    Print version information.
 -c
    SNMP Community (public)
 -h
    Device to poll
 -t
    Target - omit for all, or one of: 
        FANS|POWER|TEMPERATURE|MEMORY|CPU|UPTIME
  -p
    SNMP port (default 161)
 -v
    Verbose output
 -d
    Debug output

  --skip-mem              Skip memory checks
  --memwarn <integer>     Percentage of memory usage for warning (using: {$cmdargs['memwarn']})
  --memcrit <integer>     Percentage of memory usage for critical (using: {$cmdargs['memcrit']})

  --skip-temp             Skip temperature checks
  --tempwarn <integer>    Degrees Celsius for warning  (using: {$cmdargs['tempwarn']})
  --tempcrit <integer>    Degrees Celsius for critical (using: {$cmdargs['tempwarn']})
  --skip-fans             Skip fan checks

  --skip-psu              Skip PSU(s) checks
  --ignore-psu-notpresent Ignore PSUs that are not installed

  --skip-reboot           Skip reboot check
  --lastcheck             Nagios $LASTSERVICECHECK$ macro. Used by reboot check such that if the
                          last reboot was within the last check, then an alert is generated. Overrides
                          --reboot to ensure reboots are caught
  --reboot <integer>      How many seconds ago should we warn that the device has been rebooted (using: {$cmdargs['reboot']})

  --skip-cpu              Skip all CPU utilisation checks
  --thres-cpu             CPU warning,critical thresholds for 5sec checks (using {$cmdargs['thres-cpu']})


END_USAGE;

}
