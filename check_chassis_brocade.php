#! /usr/bin/php
<?php

/**
 * check_chassis_brocade.php - Nagios plugin
 *
 *
 * This file is part of "barryo / nagios-plugins" - a library of tools and
 * utilities for Nagios developed by Barry O'Donovan
 * (http://www.barryodonovan.com/) and his company, Open Solutions
 * (http://www.opensolutions.ie/).
 *
 * Copyright (c) 2004 - 2013, Open Source Solutions Limited, Dublin, Ireland
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
 * @copyright  Copyright (c) 2004 - 2013, Open Source Solutions Limited, Dublin, Ireland
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
    'log_level'          => LOG__NONE,
    'memwarn'            => 70,
    'memcrit'            => 90,
    'reboot'             => 3600,
    'thres-cpu-1sec'     => '95,98',
    'thres-cpu-5sec'     => '85,95',
    'thres-cpu-1min'     => '70,90'
];


// parse the command line arguments
parseArguments();


//print_r( $cmdargs ); die();

require 'OSS_SNMP/OSS_SNMP/SNMP.php';

$snmp = new \OSS_SNMP\SNMP( $cmdargs['host'], $cmdargs['community'] );

checkCPU();
checkReboot();
checkPower();
checkFans();
checkTemperature();
checkMemory();
checkOthers();


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

    _log( "========== Temperature check start ==========", LOG__DEBUG );

    try
    {
        // remember - Foundry use units of 0.5 Celcius:
        $temp = $snmp->useFoundry_Chassis()->actualTemperature() / 2.0;
        $warn = $snmp->useFoundry_Chassis()->warningTemperature() / 2.0;
        $shut = $snmp->useFoundry_Chassis()->shutdownTemperature() / 2.0;

        if( !$warn ) $warn = 64.0;
    }
    catch( OSS_SNMP\Exception $e )
    {
        setStatus( STATUS_UNKNOWN );
        $unknowns .= "Temperature unknown - possibly not supported on platform? Use --skip-temp. ";
        _log( "WARNING: Temperature unknown - possibly not supported on platform? Use --skip-temp.\n", LOG__ERROR );
        return;
    }

    _log( "Temp: $temp (Warn: $warn Shutdown: $shut)", LOG__VERBOSE );
    $tempdata = sprintf( "Temp (A/W/C):  %0.1f/%0.1f/%0.1f", $temp, $warn, $shut );

    if( $temp >= $warn )
    {
        setStatus( STATUS_CRITICAL );
        $criticals .= "Temperature approaching SHUTDOWN threshold: $temp/$shut";
    }

    if( isset( $cmdargs['tempcrit'] ) && $cmdargs['tempcrit'] && $temp >= $cmdargs['tempcrit'] )
    {
        setStatus( STATUS_CRITICAL );
        $criticals .= "Temperature exceeds critical threshold: $temp/" . $cmdargs['tempcrit'];
    }
    else if( isset( $cmdargs['tempwarn'] ) && $cmdargs['tempwarn'] && $temp >= $cmdargs['tempwarn'] )
    {
        setStatus( STATUS_WARNING );
        $warnings .= "Temperature exceeds warning threshold: $temp/" . $cmdargs['tempwarn'];
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

    try
    {
        $fanDescs  = $snmp->useFoundry_Chassis()->fanDescriptions();
        $fanStates = $snmp->useFoundry_Chassis()->fanStates();
    }
    catch( OSS_SNMP\Exception $e )
    {
        setStatus( STATUS_UNKNOWN );
        $unknowns .= "Fan states unknown - possibly not supported on platform? Use --skip-fans. ";
        _log( "WARNING: Fan states unknown - possibly not supported on platform? Use --skip-fans.\n", LOG__ERROR );
        return;
    }

    $fandata = 'Fans:';

    foreach( $fanStates as $i => $state )
    {
        _log( "Fan: {$fanDescs[$i]} - $state", LOG__VERBOSE );
        $fandata .= $state == OSS_SNMP\MIBS\Foundry\Chassis::FAN_STATE_NORMAL ? ' OK' : " " . strtoupper( $state );

        if( $state == OSS_SNMP\MIBS\Foundry\Chassis::FAN_STATE_FAILURE )
        {
            setStatus( STATUS_CRITICAL );
            $criticals .= "Fan state for {$fanDescs[$i]}: $state";
        }
        else if( $state != OSS_SNMP\MIBS\Foundry\Chassis::FAN_STATE_NORMAL )
        {
            setStatus( STATUS_WARNING );
            $criticals .= "Fan state for {$fanDescs[$i]}: $state";
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

    try
    {
        $psuDescs  = $snmp->useFoundry_Chassis()->psuDescriptions();
        $psuStates = $snmp->useFoundry_Chassis()->psuStates();
    }
    catch( OSS_SNMP\Exception $e )
    {
        setStatus( STATUS_UNKNOWN );
        $unknowns .= "PSU states unknown - possibly not supported on platform? Use --skip-psu. ";
        _log( "WARNING: PSU states unknown - possibly not supported on platform? Use --skip-psu.\n", LOG__ERROR );
        return;
    }

    $psudata = 'PSUs:';

    foreach( $psuStates as $i => $state )
    {
        _log( "PSU: {$psuDescs[$i]} - $state", LOG__VERBOSE );
        $psudata .= $state == OSS_SNMP\MIBS\Foundry\Chassis::PSU_STATE_NORMAL ? ' OK' : " " . strtoupper( $state );

        if( $state == OSS_SNMP\MIBS\Foundry\Chassis::PSU_STATE_FAILURE )
        {
            setStatus( STATUS_CRITICAL );
            $criticals .= "PSU state for {$psuDescs[$i]}: $state";
        }
        else if( $state != OSS_SNMP\MIBS\Foundry\Chassis::PSU_STATE_NORMAL )
        {
            setStatus( STATUS_WARNING );
            $criticals .= "PSU state for {$psuDescs[$i]}: $state";
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

    $memUtil = $snmp->useFoundry_Chassis()->memoryUtilisation();

    _log( "Memory used: {$memUtil}%", LOG__VERBOSE );

    if( $memUtil > $cmdargs['memcrit'] )
    {
        setStatus( STATUS_CRITICAL );
        $criticals .= "Memory usage at {$memUtil}%. ";
    }
    else if( $memUtil > $cmdargs['memwarn'] )
    {
        setStatus( STATUS_WARNING );
        $warnings .= "Memory usage at {$memUtil}%. ";
    }
    else
        $normals .= " Memory OK ({$memUtil}%).";

    _log( "========== Memory check end ==========\n", LOG__DEBUG );
}


function checkReboot()
{
    global $snmp, $cmdargs, $periods, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-reboot'] ) && $cmdargs['skip-reboot'] )
        return;

    _log( "========== Reboot check start ==========", LOG__DEBUG );

    // uptime in seconds
    $sysuptime = $snmp->useSystem()->uptime() / 100.0;

    if( ( isset( $cmdargs['lastcheck'] ) && $cmdargs['lastcheck'] && $sysuptime <= $cmdargs['lastcheck'] )
            || ( $sysuptime < $cmdargs['reboot'] ) )
    {
        // Brocade use a 32bit integer for sysuptime (argh!) so it rolls over every 497.1 days
        // If we find that the system has rebooted, we'll get a second opinion

        $engineTime = $snmp->useSNMP_Engine()->time();

        if( ( isset( $cmdargs['lastcheck'] ) && $cmdargs['lastcheck'] && $engineTime <= $cmdargs['lastcheck'] )
                || ( $engineTime < $cmdargs['reboot'] ) )
        {
            setStatus( STATUS_CRITICAL );
            $criticals .= sprintf( "Device rebooted %0.1f minutes ago. ", $sysuptime / 60.0 );
        }
    }

    _log( "========== Reboot check end ==========", LOG__DEBUG );
}



function checkCPU()
{
    global $snmp, $cmdargs, $periods, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-cpu'] ) && $cmdargs['skip-cpu'] )
        return;

    _log( "========== CPU check start ==========", LOG__DEBUG );

    $cpudata = 'CPU: ';

    foreach( [ '1sec', '5sec', '1min' ] as $period )
    {
        if( isset( $cmdargs["skip-cpu-$period"] ) && $cmdargs["skip-cpu-$period"] )
            continue;

        $fn = "cpu{$period}Utilisation";
        $util = $snmp->useFoundry_Chassis()->$fn();

        _log( "CPU: $period - $util%", LOG__VERBOSE );
        $cpudata .= " $period $util%";

        list( $w, $c ) = explode( ',', $cmdargs["thres-cpu-$period"] );

        if( $util >= $c )
        {
            setStatus( STATUS_CRITICAL );
            $criticals .= "CPU $period usage at {$util}%. ";
        }
        else if( $util >= $w )
        {
            setStatus( STATUS_WARNING );
            $warnings .= "CPU $period usage at {$util}%. ";
        }
    }

    $normals .= " $cpudata.";
}



function checkOthers()
{
    global $snmp, $cmdargs, $periods, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-others'] ) && $cmdargs['skip-others'] )
        return;

    _log( "========== Others check start ==========", LOG__DEBUG );

    if( $snmp->useFoundry_Chassis()->isQueueOverflow() )
    {
        _log( "Error - queue overflow indicated", LOG__VERBOSE );
        setStatus( STATUS_CRITICAL );
        $criticals .= "Queue overflow indicated. ";
    }
    else
        _log( "OK - queue overflow not indicated", LOG__VERBOSE );

    if( $snmp->useFoundry_Chassis()->isBufferShortage() )
    {
        _log( "Error - buffer shortage indicated", LOG__VERBOSE );
        setStatus( STATUS_CRITICAL );
        $criticals .= "Buffer shortage indicated. ";
    }
    else
        _log( "OK - buffer shortage not indicated", LOG__VERBOSE );

    if( $snmp->useFoundry_Chassis()->isDMAFailure() )
    {
        _log( "Error - DMA failure indicated", LOG__VERBOSE );
        setStatus( STATUS_CRITICAL );
        $criticals .= "DMA failure indicated. ";
    }
    else
        _log( "OK - DMA failure not indicated", LOG__VERBOSE );

    if( $snmp->useFoundry_Chassis()->isResourceLow() )
    {
        _log( "Error - low resources indicated", LOG__VERBOSE );
        setStatus( STATUS_CRITICAL );
        $criticals .= "Low resources indicated. ";
    }
    else
        _log( "OK - low resources not indicated", LOG__VERBOSE );

    if( $snmp->useFoundry_Chassis()->isExcessiveError() )
    {
        _log( "Error - excessive errors indicated", LOG__VERBOSE );
        setStatus( STATUS_CRITICAL );
        $criticals .= "Excessive errors indicated. ";
    }
    else
        _log( "OK - excessive errors not indicated", LOG__VERBOSE );

    _log( "========== Others check end ==========", LOG__DEBUG );
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
 -p
    SNMP port (default 161)
 -v
    Verbose output
 -d
    Debug output

  --skip-mem              Skip memory checks
  --memwarn <integer>     Percentage of memory usage for warning (using: 70)
  --memcrit <integer>     Percentage of memory usage for critical (using: 90)

  --skip-temp             Skip temperature checks
  --tempwarn <integer>    Degrees Celsius for warning (in addition to device's setting)
  --tempcrit <integer>    Degrees Celsius for critical (in addition to device's setting)
  --skip-fans             Skip fan checks

  --skip-psu              Skip PSU(s) checks
  --ignore-psu-notpresent Ignore PSUs that are not installed

  --skip-reboot           Skip reboot check
  --lastcheck             Nagios $LASTSERVICECHECK$ macro. Used by reboot check such that if the
                          last reboot was within the last check, then an alert if generated. Overrides
                          --reboot to ensure reboots are caught

  --skip-others           Skip 'other' checks

  --skip-cpu              Skip all CPU utilisation checks
  --skip-cpu-1sec         Skip 1sec CPU utilisation check
  --skip-cpu-5sec         Skip 5sec CPU utilisation check
  --skip-cpu-1min         Skip 1min CPU utilisation check
  --thres-cpu-1sec        CPU warning,critical thresholds for 1sec checks (using 95,98)
  --thres-cpu-5sec        CPU warning,critical thresholds for 5sec checks (using 85,95)
  --thres-cpu-1min        CPU warning,critical thresholds for 1min checks (using 70,90)

  --reboot <integer>      How many minutes ago should we warn that the device has been rebooted (using: 60)


END_USAGE;

}

