#! /usr/bin/php
<?php

/**
 * check_chassis_arista_api.php - Nagios plugin
 *
 * WARNING: this is a god-awful proof of concept. Ick! But it works.
 *
 *
 * This file is part of "barryo / nagios-plugins" - a library of tools and
 * utilities for Nagios developed by Barry O'Donovan (https://www.barryodonovan.com/)
 * and his company, Open Solutions (http://www.opensolutions.ie/).
 *
 * Copyright (c) 2004 - 2017, Open Source Solutions Limited, Dublin, Ireland
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
 * @copyright  Copyright (c) 2004 - 2017, Open Source Solutions Limited, Dublin, Ireland
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
    'username'           => 'xxx',
    'password'           => 'xxx',
    'log_level'          => LOG__NONE,
    'memwarn'            => 85,
    'memcrit'            => 90,
    'reboot'             => 3600,
    'thres-cpu-1sec'     => '95,98',
    'thres-cpu-5sec'     => '85,95',
    'thres-cpu-1min'     => '70,90'
];


// parse the command line arguments
parseArguments();


//print_r( $cmdargs ); die();


$payload = '{
  "jsonrpc": "2.0",
  "method": "runCmds",
  "params": {
    "format": "json",
    "timestamps": false,
    "autoComplete": false,
    "expandAliases": false,
    "cmds": [
      "show environment cooling",
      "show environment power",
      "show environment temperature",
      "show version"
    ],
    "version": 1
  },
  "id": "EapiExplorer-1"
}';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $cmdargs['api_url']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, "{$cmdargs['username']}:{$cmdargs['password']}");
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Content-Length: ' . strlen($payload))
);
$output = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

if( $info['http_code'] != 200 ) {
    echo "UNKNOWN: Could not access API interface";
    exit( STATUS_UNKNOWN );
}

if( !( $state = json_decode( $output ) ) ) {
    echo "UNKNOWN: Could not decode JSON response";
    exit( STATUS_UNKNOWN );
}

if( isset( $state->error ) ) {
    echo "UNKNOWN: " . $state->error->message;
    exit( STATUS_UNKNOWN );
}

$state = $state->result;

checkCooling( $state[0] );
checkPower( $state[1] );
checkTemperature( $state[2] );
checkMemory( $state[3] );

$payload = '{
  "jsonrpc": "2.0",
  "method": "runCmds",
  "params": {
    "format": "text",
    "timestamps": false,
    "autoComplete": false,
    "expandAliases": false,
    "cmds": [
      "show uptime"
    ],
    "version": 1
  },
  "id": "EapiExplorer-1"
}';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $cmdargs['api_url']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, "{$cmdargs['username']}:{$cmdargs['password']}");
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Content-Length: ' . strlen($payload))
);
$output = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

if( $info['http_code'] != 200 ) {
    echo "UNKNOWN: Could not access API interface for uptime";
    exit( STATUS_UNKNOWN );
}

if( !( $state = json_decode( $output ) ) ) {
    echo "UNKNOWN: Could not decode JSON response for uptime";
    exit( STATUS_UNKNOWN );
}

if( isset( $state->error ) ) {
    echo "UNKNOWN: (uptime) " . $state->error->message;
    exit( STATUS_UNKNOWN );
}

$state = trim( $state->result[0]->output );

$matches = [];
$uptime = 0;	// minutes

if( preg_match( "/^[\d\:]{8}\s+up\s+(\d+)\s+(\w+),\s+(\d+):(\d+),/", $state, $matches ) ) {
// 14:56:15 up 141 days, 14:35,  3 users,  load average: 0.43, 0.37, 0.33
    $uptime = $matches[1] * 1440 + $matches[2] * 60 + $matches[3];
} elseif( preg_match( "/^[\d\:]{8}\s+up\s+(\d+):(\d+),/", $state, $matches ) ) {
// 10:23:36 up 15:09,  1 user,  load average: 0.62, 0.66, 0.62
    $uptime = $matches[1] * 60 + $matches[2];
} elseif( preg_match( "/^[\d\:]{8}\s+up\s+(\d+)\s+min,/", $state, $matches ) ) {
//  10:42:16 up 3 min,  1 user,  load average: 3.55, 1.55, 0.60
    $uptime = $matches[1];
} else {
    echo "UNKNOWN: (uptime) could not parse response";
    exit( STATUS_UNKNOWN );
}

//             141          days
checkUptime( $uptime );

$matches = [];
if( !preg_match( "/load average: ([\d\.]+),\s([\d\.]+),\s([\d\.]+)$/", $state, $matches ) ) {
    echo "UNKNOWN: (uptime) could not parse response";
    exit( STATUS_UNKNOWN );
}
checkCPU( $matches[1], $matches[2], $matches[3] );


if( $status == STATUS_OK )
    $msg = "OK -{$normals}\n";
else
    $msg = "{$criticals}{$warnings}{$unknowns}\n";

echo $msg;
exit( $status );


function checkCPU( $l1, $l2, $l3 ) {
    global $cmdargs, $criticals, $warnings, $unknowns, $normals;

    if( $l1 > 1.5 || $l2 > 1.5 || $l3 > 1.5 ) {
        setStatus( STATUS_WARNING );
        $criticals .= "System load is high: {$l1} {$l2} {$l3}. ";
    } else {
        $normals .= "System load looks okay: {$l1} {$l2} {$l3}. ";
    }
}

function checkUptime( $num ) {
    global $cmdargs, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-reboot'] ) && $cmdargs['skip-reboot'] )
        return;

    if( $num < 1440 ) {
        setStatus( STATUS_WARNING );
        $criticals .= "Switch uptime is {$num} minutes. ";
    } else {
        $normals .= " System uptime looks okay: {$num} minutes. ";
    }
}

function checkCooling( $fans ) {
    global $cmdargs, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-fans'] ) && $cmdargs['skip-fans'] )
        return;

    $fandata = 'Fans (speed actual/configured): ';

    foreach( $fans->fanTraySlots as $i => $ft ) {

        $fandata .= ( $i == 0 ? '' : '; ' );

        $fandata .= "Tray {$ft->label}: ";

        foreach( $ft->fans as $f ) {
            $fandata .= "{$f->label} {$f->status} ({$f->actualSpeed}%/{$f->configuredSpeed}%)";
        }

        if( $f->status != 'ok' ) {
            setStatus( STATUS_CRITICAL );
            $criticals .= "Fan state for {$f->label}: {$f->status}";
        }
    }

    foreach( $fans->powerSupplySlots as $i => $ft ) {

        $fandata .= ( $i == 0 ? '' : '; ' );

        $fandata .= "Tray {$ft->label}: ";

        foreach( $ft->fans as $f ) {
            $fandata .= "{$f->label} {$f->status} ({$f->actualSpeed}%/{$f->configuredSpeed}%)";
        }

        if( $f->status != 'ok' ) {
            setStatus( STATUS_CRITICAL );
            $criticals .= "Fan state for {$f->label}: {$f->status}. ";
        }
    }

    $normals .= " $fandata. ";
}

function checkPower( $psus ) {
    global $cmdargs, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-psu'] ) && $cmdargs['skip-psu'] )
        return;

    $psudata = 'PSUs: ';
    $i = 1;

    while( isset( $psus->powerSupplies->$i ) ) {

        $psu = $psus->powerSupplies->$i;

        $psudata .= ( $i == 1 ? '' : '; ' );

        $psudata .= "PSU #{$i} {$psu->modelName}: {$psu->state} ({$psu->outputPower}/{$psu->capacity}W)";

        if( $psu->state != 'ok' ) {
            setStatus( STATUS_CRITICAL );
            $criticals .= "PSU state for PSU #{$i} {$psu->modelName}: {$psu->state}. ";
        }

        $i++;
    }

    $normals .= " $psudata. ";
}

function checkTemperature( $temps ) {
    global $cmdargs, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-temp'] ) && $cmdargs['skip-temp'] )
        return;

    $data = 'Temperature (current/max/warn/crit): ';

    if( $temps->systemStatus == 'temperatureOk' ) {
        $data .= "overall system temperature status: ok. ";
    } else {
        setStatus( STATUS_CRITICAL );
        $criticals .= "Overall system temperature status: {$temps->systemStatus}. ";
    }

    foreach( $temps->powerSupplySlots as $i => $t ) {

        foreach( $t->tempSensors as $ts ) {
            $data .= "{$ts->name} ({$ts->description}): {$ts->hwStatus} "
                . "({$ts->currentTemperature}/{$ts->maxTemperature}/{$ts->overheatThreshold}/{$ts->criticalThreshold}); ";

            if( $ts->inAlertState ) {
                setStatus( STATUS_CRITICAL );
                $criticals .= "Temp sensor {$ts->name} ({$ts->description}) in alert state. ";
            } else if( $ts->currentTemperature >= $ts->criticalThreshold ) {
                setStatus( STATUS_CRITICAL );
                $criticals .= "Temp sensor {$ts->name} ({$ts->description}) is >= critical threshold. ";
            } else if( $ts->currentTemperature >= $ts->overheatThreshold ) {
                setStatus( STATUS_WARNING );
                $criticals .= "Temp sensor {$ts->name} ({$ts->description}) is >= overheat threshold. ";
            }
        }
    }

    foreach( $temps->tempSensors as $ts ) {
        $data .= "{$ts->name} ({$ts->description}): {$ts->hwStatus} "
            . "({$ts->currentTemperature}/{$ts->maxTemperature}/{$ts->overheatThreshold}/{$ts->criticalThreshold}); ";

        if( $ts->inAlertState ) {
            setStatus( STATUS_CRITICAL );
            $criticals .= "Temp sensor {$ts->name} ({$ts->description}) in alert state. ";
        } else if( $ts->currentTemperature >= $ts->criticalThreshold ) {
            setStatus( STATUS_CRITICAL );
            $criticals .= "Temp sensor {$ts->name} ({$ts->description}) is >= critical threshold. ";
        } else if( $ts->currentTemperature >= $ts->overheatThreshold ) {
            setStatus( STATUS_WARNING );
            $criticals .= "Temp sensor {$ts->name} ({$ts->description}) is >= overheat threshold. ";
        }
    }

    $normals .= " $data. ";
}


function checkMemory( $mem )
{
    global $cmdargs, $criticals, $warnings, $unknowns, $normals;

    if( isset( $cmdargs['skip-mem'] ) && $cmdargs['skip-mem'] )
        return;

    $memUtil = sprintf( "%0.2f", ( $mem->memFree / $mem->memTotal ) * 100.0 );

    if( $memUtil > $cmdargs['memcrit'] ) {
        setStatus( STATUS_CRITICAL );
        $criticals .= "Memory usage at {$memUtil}%. ";
    } else if( $memUtil > $cmdargs['memwarn'] ) {
        setStatus( STATUS_WARNING );
        $warnings .= "Memory usage at {$memUtil}%. ";
    } else {
        $normals .= " Memory OK ({$memUtil}%).";
    }
}

/**
 * Parses (and checks some) command line arguments
 */
function parseArguments()
{
    global $checkOptions, $cmdargs, $checksEnabled, $periods, $periodsEnabled, $argc, $argv;

    if( $argc == 1 ) {
        printUsage( true );
        exit( STATUS_UNKNOWN );
    }

    $i = 1;


    while( $i < $argc ) {

        if( $argv[$i][0] != '-' ) {
            $i++;
            continue;
        }

        switch( $argv[$i][1] ) {

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
                $cmdargs['api_url'] = $argv[$i+1];
                $i++;
                break;

            case 'h':
                $cmdargs['host'] = $argv[$i+1];
                $i++;
                break;

            case 'u':
                $cmdargs['username'] = $argv[$i+1];
                $i++;
                break;

            case 'p':
                $cmdargs['password'] = $argv[$i+1];
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
{$progname} -c <API URL> -u <API USERNAME> -p <API PASSWORD> [-V] [-h] [-?] [--help]

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

{$progname} - Nagios plugin to check the status of an Arista chassis'
Copyright (c) 2004 - 2017 Open Source Solutions Ltd - http://www.opensolutions.ie/

{$progname} -c <API_URL> -u <username> -p <password> [-V] [-?] [--help]

Options:

 -?,--help
    Print detailed help screen.
 -V
    Print version information.
 -c
    API URL
 -u
    API Username
 -p
    API Password
 -v
    Verbose output
 -d
    Debug output

  --skip-mem              Skip memory checks
  --memwarn <integer>     Percentage of memory usage for warning (using: 85)
  --memcrit <integer>     Percentage of memory usage for critical (using: 90)

  --skip-temp             Skip temperature checks
  --skip-fans             Skip fan checks

  --skip-psu              Skip PSU(s) checks

  --skip-reboot           Skip reboot check


END_USAGE;

}
