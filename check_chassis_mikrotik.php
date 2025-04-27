#! /usr/bin/php
<?php

// 20250427 - this is a REAL hack! It works for MX204 but it's not pretty.

/**
 * check_chassis_mikrotik.php - Nagios plugin
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
    'diskwarn'           => 80,
    'diskcrit'           => 90,
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

//         FANS|POWER|TEMPERATURE|MEMORY|CPU|UPTIME|DISK

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

if( $cmdargs['target'] === null || $cmdargs['target'] == 'DISK' )
    checkDisk();


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

    // https://mibbrowser.online/mibdb_search.php?mib=MIKROTIK-MIB

    $temps = [
        '.1.3.6.1.4.1.14988.1.1.3.100.1.3.7101' => 'board-temperature1',
        '.1.3.6.1.4.1.14988.1.1.3.100.1.3.7102' => 'board-temperature2',
    ];

    $tempdata = 'Temperatures:';
    $polled = 0;

    foreach( $temps as $oid => $name ) {
        try {
            $temp = $snmp->get($oid);

            _log( "TEMP: {$name} - {$temp}C", LOG__VERBOSE );
            $tempdata .= " [$name - {$temp}C]";
            
            if( $temp > 60 ) {
                setStatus( STATUS_CRITICAL );
                $criticals .= "{$name} is not OK, temp is {$temp}'C - check allowable max. ";
            } 
            $polled++;
        } catch( OSS_SNMP\Exception $e ) {
            $tempdata .= " [$name not present?]";
        }
    }

    if( !$polled ) {
        setStatus( STATUS_UNKNOWN );
        $unknowns .= "No temperature sensors found. ";
    }

    $normals .= " $tempdata.";

    _log( "========== Temperature check end ==========\n", LOG__DEBUG );
}


function checkFans()
{
    global $snmp, $cmdargs, $periods, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-fans'] ) && $cmdargs['skip-fans'] )
        return;

    _log( "========== Fans check start ==========", LOG__DEBUG );

    // https://mibbrowser.online/mibdb_search.php?mib=MIKROTIK-MIB

    $fans = [
        '.1.3.6.1.4.1.14988.1.1.3.100.1.3.7001' => 'fan1-speed',
        '.1.3.6.1.4.1.14988.1.1.3.100.1.3.7002' => 'fan2-speed',
    ];

    $polled = 0;

    $fandata = 'Fans:';

    foreach( $fans as $oid => $name ) {
        try {
            $speed = $snmp->get($oid);
            _log( "FAN: {$name} - {$speed} RPM", LOG__VERBOSE );
            $fandata .= " [$name - {$speed} RPM]";
            
            if( !$speed ) {
                setStatus( STATUS_CRITICAL );
                $criticals .= "{$name} is not OK, speed is {$speed} RPM. ";
            } else if( $speed < 300 ) {
                setStatus( STATUS_CRITICAL );
                $criticals .= "{$name} is not OK, speed is only {$speed} RPM. ";
            }

            $polled++;
        } catch( OSS_SNMP\Exception $e ) {
            $fandata .= " [$name not present?]";
        }
    }

    if( !$polled ) {
        setStatus( STATUS_UNKNOWN );
        $unknowns .= "No fans found. ";
    }


    $normals .= " $fandata.";


    _log( "========== FAN check end ==========\n", LOG__DEBUG );

}



function checkPower()
{
    global $snmp, $cmdargs, $periods, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-psu'] ) && $cmdargs['skip-psu'] )
        return;

    _log( "========== PSU check start ==========", LOG__DEBUG );

    // https://mibbrowser.online/mibdb_search.php?mib=MIKROTIK-MIB

    $psus = [
        '.1.3.6.1.4.1.14988.1.1.3.15.0' => 'psu1-state',
        '.1.3.6.1.4.1.14988.1.1.3.16.0' => 'psu1-state',
    ];

    $polled = 0;
    $psudata = 'PSUs:';

    foreach( $psus as $oid => $name ) {
        try {
            $state = $snmp->get($oid);
            _log( "PSU: {$name} - " . ( $state ? 'OK' : 'NOT OK' ), LOG__VERBOSE );
            $psudata .= " [$name - " . ( $state ? 'OK' : 'NOT OK' ) . "]";
        
            if( !$state ) {
                setStatus( STATUS_CRITICAL );
                $criticals .= "{$name} is not OK. ";
            }
            $polled++;
        } catch( OSS_SNMP\Exception $e ) {
            $psudata .= " [$name not present?]";
        }
    }

    if( !$polled ) {
        setStatus( STATUS_UNKNOWN );
        $unknowns .= "No PSUs found. ";
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

    $memAvail = $snmp->get('1.3.6.1.2.1.25.2.3.1.5.65536');
    $memUsed  = $snmp->get('1.3.6.1.2.1.25.2.3.1.6.65536');

    $memUtil = (int)((100*$memUsed)/$memAvail);

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


function checkDisk()
{
    global $snmp, $cmdargs, $periods, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-disk'] ) && $cmdargs['skip-disk'] )
        return;

    _log( "========== Disk check start ==========", LOG__DEBUG );

    $diskAvail = $snmp->get('1.3.6.1.2.1.25.2.3.1.5.131072');
    $diskUsed  = $snmp->get('1.3.6.1.2.1.25.2.3.1.6.131072');

    $diskUtil = (int)((100*$diskUsed)/$diskAvail);

    $diskData = " Disk (usage%):";

    if( $diskUtil > $cmdargs['diskcrit'] )
    {
        setStatus( STATUS_CRITICAL );
        $criticals .= "Dick usage at {$diskUtil}%. ";
    }
    else if( $diskUtil > $cmdargs['diskwarn'] )
    {
        setStatus( STATUS_WARNING );
        $warningss .= "Disk usage at {$memUtil}%. ";
    }
    else
    {
        $diskData .= " {$diskUtil}%";
    }

        $normals .= $diskData . '. ';

    _log( "========== Disk check end ==========\n", LOG__DEBUG );
}


function checkReboot()
{
    global $snmp, $cmdargs, $periods, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-reboot'] ) && $cmdargs['skip-reboot'] )
        return;

    _log( "========== Reboot check start ==========", LOG__DEBUG );

    $bootTimeSecs  = $snmp->get( '1.3.6.1.2.1.25.1.1.0' )/100;


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

    $cpus = $snmp->walk1d( '1.3.6.1.2.1.25.3.3.1.2');

    list( $w, $c ) = explode( ',', $cmdargs["thres-cpu"] );

    $cpudata = 'CPUS 1min load avg:';

    foreach( $cpus as $cpu => $util ) {

        _log( "CPU: #{$cpu} - {$util}; ", LOG__VERBOSE );
        $cpudata .= " [CPU #{$cpu}: {$util}%]";

        if( $util >= $c )
        {
            setStatus( STATUS_CRITICAL );
            $criticals .= "CPU #{$cpu} 1min usage at {$util}%. ";
        }
        else if( $util >= $w )
        {
            setStatus( STATUS_WARNING );
            $warnings .= "CPU #{$cpu} 1min usage at {$util}%. ";
        }
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

  --skip-disk             Skip memory checks
  --diskwarn <integer>    Percentage of disk usage for warning (using: {$cmdargs['diskwarn']})
  --diskcrit <integer>    Percentage of disk usage for critical (using: {$cmdargs['diskcrit']})


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
