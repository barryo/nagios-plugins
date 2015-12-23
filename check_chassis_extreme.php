#! /usr/bin/php
<?php

/**
 * check_chassis_extreme.php - Nagios plugin
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
        $temp = $snmp->useExtreme_System_Common()->currentTemperature();
        $over = $snmp->useExtreme_System_Common()->overTemperatureAlarm();
    } catch( OSS_SNMP\Exception $e ) {
        setStatus( STATUS_UNKNOWN );
        $unknowns .= "Temperature unknown - possibly not supported on platform? Use --skip-temp. ";
        _log( "WARNING: Temperature unknown - possibly not supported on platform? Use --skip-temp.\n", LOG__ERROR );
        return;
    }

    _log( "Temp: {$temp}'C", LOG__VERBOSE );
    _log( "Over temp alert: " . ( $over ? 'YES' : 'NO' ), LOG__VERBOSE );

    $tempdata = sprintf( "Temp: %d'C", $temp );

    if( $over ) {
        setStatus( STATUS_CRITICAL );
        $criticals .= "Temperature alarm set: {$temp}'C. ";
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
        $fans   = $snmp->useExtreme_System_Common()->fanOperational();
        $speeds = $snmp->useExtreme_System_Common()->fanSpeed();

    } catch( OSS_SNMP\Exception $e ) {
        setStatus( STATUS_UNKNOWN );
        $unknowns .= "Fan states unknown - possibly not supported on platform? Use --skip-fans. ";
        _log( "WARNING: Fan states unknown - possibly not supported on platform? Use --skip-fans.\n", LOG__ERROR );
        return;
    }

    $fandata = 'Fans:';

    foreach( $fans as $i => $operational )
    {
        $ok = $operational && $speeds[$i] >= 2000;
        
        _log( "Fan: {$i} - " . ( $ok ? 'OK' : 'NOT OK' ) . " ({$speeds[$i]} RPM)", LOG__VERBOSE );
        $fandata .= " [{$i} - " . ( $ok ? 'OK' : 'NOT OK' ) . " ({$speeds[$i]} RPM)];";

        if( !$ok ) {
            setStatus( STATUS_CRITICAL );
            if( $operational && $speeds[$i] < 2000 )
                $criticals .= "Fan {$i} is operational but speed is outside normal operating range (<2000). ";
            else if( !$operational )
                $criticals .= "Fan {$i} is not operational. ";
            else
                $criticals .= "Fan {$i} is not operational (reason unknown). ";
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
        $psuStates  = $snmp->useExtreme_System_Common()->powerSupplyStatus();
        $psuSerials = $snmp->useExtreme_System_Common()->powerSupplySerialNumbers();
        $psuSources = $snmp->useExtreme_System_Common()->powerSupplySource();

        $sources = OSS_SNMP\MIBS\Extreme\System\Common::$POWER_SUPPLY_SOURCES;
        $states  = OSS_SNMP\MIBS\Extreme\System\Common::$POWER_SUPPLY_STATES;

        $systemPowerState = $snmp->useExtreme_System_Common()->systemPowerState();
        $systemState      = OSS_SNMP\MIBS\Extreme\System\Common::$POWER_STATES;
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
        _log( "PSU: {$i} - {$states[$state]} (Serial: {$psuSerials[$i]}; Source: {$sources[ $psuSources[$i] ]})", LOG__VERBOSE );
        $psudata .= " {$i} - {$states[$state]};";

        if( $state == OSS_SNMP\MIBS\Extreme\System\Common::POWER_SUPPLY_STATUS_NOT_PRESENT
                && !( isset( $cmdargs['ignore-psu-notpresent'] ) && $cmdargs['ignore-psu-notpresent'] ) )
        {
            setStatus( STATUS_WARNING );
            $warnings .= "PSU $i is not present";
        }
        else if( $state != OSS_SNMP\MIBS\Extreme\System\Common::POWER_SUPPLY_STATUS_PRESENT_OK )
        {
            setStatus( STATUS_CRITICAL );
            $criticals .= "PSU state for {$i}: {$states[$state]}";
        }
    }

    $psudata .= ". ";

    if( $systemPowerState == OSS_SNMP\MIBS\Extreme\System\Common::SYSTEM_POWER_STATE_REDUNDANT_POWER_AVAILABLE )
    {
        $psudata .= "Overall system power state: redundant power available";
    }
    else if( $systemPowerState == OSS_SNMP\MIBS\Extreme\System\Common::SYSTEM_POWER_STATE_SUFFICIENT_BUT_NOT_REDUNDANT_POWER )
    {
        setStatus( STATUS_WARNING );
        $warnings .= "Overall system power state: sufficient but not redundant power available";
    }
    else if( $systemPowerState == OSS_SNMP\MIBS\Extreme\System\Common::SYSTEM_POWER_STATE_INSUFFICIENT_POWER )
    {
        setStatus( STATUS_CRITICAL );
        $warnings .= "Overall system power state: insufficent power available";
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

    $memUtil = $snmp->useExtreme_SwMonitor_Memory()->percentUsage();

    $memData = " Memory (slot:usage%):";

    foreach( $memUtil as $slotId => $usage ) {
        _log( "Memory used in slot {$slotId}: {$usage}%", LOG__VERBOSE );

        if( $usage > $cmdargs['memcrit'] )
        {
            setStatus( STATUS_CRITICAL );
            $criticals .= "Memory usage in slot {$slotId} at {$usage}%. ";
        }
        else if( $usage > $cmdargs['memwarn'] )
        {
            setStatus( STATUS_WARNING );
            $warningss .= "Memory usage in slot {$slotId} at {$usage}%. ";
        }
        else
            $memData .= " {$slotId}:{$usage}%";
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

    $bootTime  = $snmp->useExtreme_System_Common()->bootTime();


    if( ( isset( $cmdargs['lastcheck'] ) && $cmdargs['lastcheck'] && $bootTime <= $cmdargs['lastcheck'] )
            || ( time() - $bootTime <= $cmdargs['reboot'] ) )
    {
        setStatus( STATUS_CRITICAL );
        $criticals .= sprintf( "Device rebooted %0.1f minutes ago. ", ( time() - $bootTime ) / 60.0 );
    }

    $up = ( time() - $bootTime ) / 60.0 / 60.0;

    if( $up < 24 )
        $up = sprintf( "Uptime: %0.1f hours. ", $up );
    else
        $up = sprintf( "Uptime: %0.1f days. ", $up / 24 );

    _log( "Last reboot: " . ( ( time() - $bootTime ) / 60.0 ) . " minutes ago", LOG__VERBOSE );
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

    $util  = $snmp->useExtreme_SwMonitor_Cpu()->totalUtilization();


    _log( "CPU: 5sec - $util%", LOG__VERBOSE );
    $cpudata = "CPU: 5sec - {$util}%";

    list( $w, $c ) = explode( ',', $cmdargs["thres-cpu"] );

    if( $util >= $c )
    {
        setStatus( STATUS_CRITICAL );
        $criticals .= "CPU 5sec usage at {$util}%. ";
    }
    else if( $util >= $w )
    {
        setStatus( STATUS_WARNING );
        $warnings .= "CPU 5sec usage at {$util}%. ";
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
