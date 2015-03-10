#! /usr/bin/php
<?php

/**
 * check_rsnapshot.php - Nagios plugin
 *
 *
 * This file is part of "barryo / nagios-plugins" - a library of tools and
 * utilities for Nagios developed by Barry O'Donovan
 * (http://www.barryodonovan.com/) and his company, Open Solutions
 * (http://www.opensolutions.ie/).
 *
 * Copyright (c) 2004 - 2012, Open Source Solutions Limited, Dublin, Ireland
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
 * @copyright  Copyright (c) 2004 - 2012, Open Source Solutions Limited, Dublin, Ireland
 * @license    http://www.opensolutions.ie/licenses/new-bsd New BSD License
 * @link       http://www.opensolutions.ie/ Open Source Solutions Limited
 * @author     Barry O'Donovan <info@opensolutions.ie>
 * @author     The Skilled Team of PHP Developers at Open Solutions <info@opensolutions.ie>
 */

date_default_timezone_set('Europe/Dublin');

define( "VERSION", '1.0.0' );

// normally in a Nagios plugin, we'd have a max execution time but this plugin
// should only be run once a day as opposed to every five minutes
// ini_set( 'max_execution_time', '55' );

ini_set( 'display_errors', true );
ini_set( 'display_startup_errors', true );

define( "CMD_FIND", '/usr/bin/find' ); 
define( "CMD_DU",   '/usr/bin/du' ); 

define( "STATUS_OK",       0 );
define( "STATUS_WARNING",  1 );
define( "STATUS_CRITICAL", 2 );
define( "STATUS_UNKNOWN",  3 );

define( "CHECK_MINFILES",     'minfiles'     );
define( "CHECK_MINSIZE",      'minsize'      );
define( "CHECK_LOG",          'log'          );
define( "CHECK_DIR_CREATION", 'dir-creation' );
define( "CHECK_ROTATION",     'rotation'     );
define( "CHECK_TIMESTAMP",    'timestamp'    );

$checkOptions = array(
    CHECK_MINFILES      => 'minfiles',
    CHECK_MINSIZE       => 'minsize',
    CHECK_LOG           => 'log',
    CHECK_DIR_CREATION  => 'dir-creation',
    CHECK_ROTATION      => 'rotation',
    CHECK_TIMESTAMP     => 'timestamp'
);

$checksEnabled = array(
    CHECK_MINFILES      => 1,
    CHECK_MINSIZE       => 1,
    CHECK_LOG           => 1,
    CHECK_DIR_CREATION  => 1,
    CHECK_ROTATION      => 1,
    CHECK_TIMESTAMP     => 1
);


define( "LOG__NONE",    0 );
define( "LOG__ERROR",   1 );
define( "LOG__VERBOSE", 2 );
define( "LOG__DEBUG",   3 );

// initialise some variables
$status    = STATUS_OK;
$log_level = LOG__NONE;


// rsnapshot retains backups for named retention periods. These named periods
// have no actual meaning to rsnapshot, they are just called by a cron job
// schedule. But, to know if a backup has not happened based on periods set in
// the configuration file, we need to assign times to these periods. Feel free
// to add more to the below.
$periods = array(
    "hourly"  => 3600,
    "daily"   => 86400,
    "weekly"  => 604800,
    "monthly" => 2678400
);

$periodsEnabled = array(
    'hourly', 'daily', 'weekly', 'monthly'
);


// possible output strings
$criticals = "";
$warnings  = "";
$unknowns  = "";


// set default values for command line arguments
$cmdargs = array(
    'config_version'     => '1.2',              // the rsnapshot config file version we are capable of parsing
    'period'             => 'all',              // by default, assume all periods
    'log_level'          => LOG__ERROR,
    'configFile'         => '/etc/rsnapshot.conf'
);


// parse the command line arguments
parseArguments();


//Parsing configuration files
parseConfig( $cmdargs['configFile'] );

//print_r( $cmdargs ); die();

//Checking if check is enabled and if it is run it.
if( $checksEnabled[ CHECK_MINFILES ] )
    minfilesCheck();

if( $checksEnabled[ CHECK_MINSIZE ] )
    minsizeCheck();

if( $checksEnabled[ CHECK_LOG ] )
    logCheck();

if( $checksEnabled[ CHECK_DIR_CREATION ] )
    dirCreationCheck();

if( $checksEnabled[ CHECK_ROTATION ] )
    rotationCheck();

if( $checksEnabled[ CHECK_TIMESTAMP ] )
    timestampCheck();


if( $status == STATUS_OK )
    $msg = "OK\n";
else
    $msg = "{$criticals}{$warnings}{$unknowns}\n";

echo $msg;
exit( $status );



/**
 * Checks the number of files in a snapshot against a minimum expected number.
 *
 * This is only done for the second most recent retention directory (i.e. to avoid 
 * a backup in progress).
 */
function minfilesCheck()
{
    global $cmdargs, $periods, $criticals, $warnings;

    _log( "========== Minfiles check start ==========", LOG__DEBUG );

    foreach( $cmdargs["backup"] as $backup )
    {
        if( isset( $params ) ) unset( $params );

        if( isset( $cmdargs["nagios"][ $backup[0] ]["MINFILES"] ) )
            $params = $cmdargs["nagios"][ $backup[0] ]["MINFILES"];
        else if( isset( $cmdargs["nagios"]["DEFAULT"]["MINFILES"] ) )
            $params = $cmdargs["nagios"]["DEFAULT"]["MINFILES"];

        $alias = isset( $cmdargs['nagios'][$backup[0]]["ALIAS"] ) ? $cmdargs['nagios'][$backup[0]]["ALIAS"] : $backup[0];

        if( !isset( $params ) )
        {
            _log( sprintf( "Skipping minfiles check for '%s'" , $alias ), LOG__DEBUG );
            continue;
        }

        if( count( $params ) != 2 )
        {
            _log( sprintf( "ERROR: Minfiles Check: Incorrect paramters for '%s'. " , $alias ), LOG__ERROR );
            continue;
        }

        _log( sprintf( "INFO: Starting minfiles check for '%s'" , $alias ), LOG__VERBOSE );

        // we should only really do this for the second last minimum period
        foreach( $cmdargs['retain'] as $name => $howmany ) 
        {
            if( $howmany < 2 )
            {
                _log( sprintf( "ERROR: Minfiles Check: Lowest retention period for '%s' needs at least 2 snapshots. " , $alias ), LOG__ERROR );
                continue 2;
            }
            break;
        }

        $path = sprintf( "%s%s.%d/%s", $cmdargs["snapshot_root"], $name, 1, $backup[1] );

        if( !file_exists( $path ) )
        {
            _log( sprintf( "Skipping minfiles check for %s as retention directory does not exist", $path ), LOG__VERBOSE );
            continue;
        }

        $exec = CMD_FIND . " " . escapeshellarg( $path ) . " | wc -l";
        _log( "Executing: $exec", LOG__DEBUG );

        $result = exec( $exec );

        _log( "Result: $result", LOG__DEBUG );

        if( !is_numeric( $result ) || !intval( $result ) )
        {
            _log( sprintf( "ERROR: Unexpected result in minfiles check for '%s'" , $alias ), LOG__ERROR );
            continue;
        }

        $result = intval( $result );

        if( $result < $params[1] )
        {
            $m = sprintf( "Minfiles check for '%s' is %d, expected >= %d", $alias, $result, $params[1] );
            _log( "CRITICAL: $m", LOG__VERBOSE );
            setStatus( STATUS_CRITICAL );
            $criticals .= $m;
        }
        elseif( $result < $params[0] )
        {
            $m = sprintf( "Minfiles check for '%s' is %d, expected >= %d", $alias, $result, $params[1] );
            _log( "WARNING: $m", LOG__VERBOSE );
            setStatus( STATUS_WARNING );
            $warnings .= $m;
        }
        else
            _log( sprintf( "INFO: Minfiles passed for '%s' - found %d, required >= %d", $path, $result, $params[0] ), LOG__DEBUG );
    }

    _log( "========== Minfiles check end ==========\n", LOG__DEBUG );
}

/**
 * Checks the size of a snapshot against a minimum expected number.
 *
 * This is only done for the second most recent retention directory (i.e. to avoid 
 * a backup in progress).
 */
function minsizeCheck()
{
    global $cmdargs, $periods, $criticals, $warnings;

    _log( "========== Minsize check start ==========", LOG__DEBUG );

    foreach( $cmdargs["backup"] as $backup )
    {
        if( isset( $params ) ) unset( $params );

        if( isset( $cmdargs["nagios"][ $backup[0] ]["MINSIZE"] ) )
            $params = $cmdargs["nagios"][ $backup[0] ]["MINSIZE"];
        else if( isset( $cmdargs["nagios"]["DEFAULT"]["MINSIZE"] ) )
            $params = $cmdargs["nagios"]["DEFAULT"]["MINSIZE"];

        $alias = isset( $cmdargs['nagios'][$backup[0]]["ALIAS"] ) ? $cmdargs['nagios'][$backup[0]]["ALIAS"] : $backup[0];


        if( !isset( $params ) )
        {
            _log( sprintf( "Skipping minsize check for '%s'" , $alias ), LOG__DEBUG );
            continue;
        }

        if( count( $params ) != 2 )
        {
            _log( sprintf( "ERROR: Minsize Check: Incorrect paramters for '%s'. " , $alias ), LOG__ERROR );
            continue;
        }

        _log( sprintf( "INFO: Starting minsize check for '%s'" , $alias ), LOG__VERBOSE );

        foreach( $params as $k => $i )
        {
            if( strtolower( substr( $i, -1 ) ) == "k" )
                $params[$k] = substr( $i, 0, -1 ) * 1024;
            else if( strtolower( substr( $i, -1 ) ) == "m" )
                $params[$k] = substr( $i, 0, -1 ) * 1024 * 1024;
            else if( strtolower( substr( $i, -1 ) ) == "g" )
                $params[$k] = substr( $i, 0, -1 ) * 1024 * 1024 * 1024;
        }

        // we should only really do this for the second last minimum period
        foreach( $cmdargs['retain'] as $name => $howmany ) 
        {
            if( $howmany < 2 )
            {
                _log( sprintf( "ERROR: Minfiles Check: Lowest retention period for '%s' needs at least 2 snapshots. " , $alias ), LOG__ERROR );
                continue 2;
            }
            break;
        }

        $path = sprintf( "%s%s.%d/%s", $cmdargs["snapshot_root"], $name, 1, $backup[1] );

        if( !file_exists( $path ) )
        {
            _log( sprintf( "Skipping minsize check for %s as retention directory does not exist", $path ), LOG__VERBOSE );
            continue;
        }

        $exec = CMD_DU . " -s -b " . escapeshellarg( $path );
        _log( "Executing: $exec", LOG__DEBUG );

        $result = exec( $exec );
        $result = explode( "\t", $result );
        $result = intval( $result[0] );

        _log( "Result: $result", LOG__DEBUG );

        if( !is_numeric( $result ) || !$result )
        {
            _log( sprintf( "ERROR: Unexpected result in minsize check for '%s'" , $alias ), LOG__ERROR );
            continue;
        }

        if( $result < $params[1] )
        {
            $m = sprintf( "Minsize check for '%s' is %d, expected >= %d", $alias, $result, $params[1] );
            _log( "CRITICAL: $m", LOG__VERBOSE );
            setStatus( STATUS_CRITICAL );
            $criticals .= $m;
        }
        elseif( $result < $params[0] )
        {
            $m = sprintf( "Minsize check for '%s' is %d, expected >= %d", $alias, $result, $params[1] );
            _log( "WARNING: $m", LOG__VERBOSE );
            setStatus( STATUS_WARNING );
            $warnings .= $m;
        }
        else
            _log( sprintf( "INFO: Minsize passed for '%s' - found %d, required >= %d", $path, $result, $params[0] ), LOG__DEBUG );
    }

    _log( "========== Minsize check end ==========\n", LOG__DEBUG );
}


/**
 * Checks the most recent entries for each retention period in the rsnapshot config file.
 *
 * Note that:
 *   * it will ignore currently running periods;
 *   * you should have a separate log file per rsnapshot configuration file
 * 
 */
function logCheck()
{
    global $cmdargs, $periods, $criticals, $warnings, $unknowns;

    $result = array();

    $handle = fopen( $cmdargs["logfile"], "r" );

    if( !$handle )
    {
        _log( sprintf( "ERROR: Log check: Log file can't be opened '%s'.", $cmdargs["logfile"] ), LOG__ERROR );
        $unknowns .= "Error - cannot open rsnapshot log file for log-check. ";
        setStatus( STATUS_UNKNOWN );
        return;
    }

    while( ( $buffer = fgets( $handle, 4096 ) ) !== false )
    {
        foreach( $cmdargs["retain"] as $name => $count )
        {
            if( strpos( $buffer, "{$name}: started" ) )
            {
                $result[ $name ]["started"] = trim( $buffer );
                $result[ $name ]["ended"] = false;
            }
            else if( strpos( $buffer, "{$name}: completed" ) )
            {
                $result[ $name ]["ended"] = trim( $buffer );;
            }
        }
    }

    fclose( $handle );

    if( count( $result ) < count( $cmdargs["retain"] ) )
    {
        $m = "Log check: Not all retention periods was found in the log.";
        _log( "WARNING: $m", LOG__VERBOSE );
        $warnings .= "{$m} ";
        setStatus( STATUS_WARNING );
    }

    foreach( $result as $name => $data )
    {
        if( $data["ended"] )
        {
            if( strpos( $data["ended"], "completed, but with some errors" ) )
            {
                $m = "Log check: Task for period {$name} was completed but with errors.";
                _log( "CRITICAL: $m", LOG__VERBOSE );
                $criticals .= "{$m} ";
                setStatus( STATUS_CRITICAL );
            }
            else if( strpos( $data["ended"], "completed, but with some warnings" ) )
            {
                $m = "Log check: Task for period {$name} was completed but with warnings.";
                _log( "WARNING: $m", LOG__VERBOSE );
                $warnings .= "{$m} ";
                setStatus( STATUS_WARNING );
            }
            else if( strpos( $data["ended"], "completed successfully" ) )
            {
                _log( "INFO: Log check - task for period {$name} was completed successfully", LOG__DEBUG );
            }
            else
            {
                $m = "Log check: Unknown completion status for retention period {$name}.";
                _log( "UNKNOWN: $m", LOG__VERBOSE );
                $unknowns .= "{$m} ";
                setStatus( STATUS_UNKNOWN );
            }

        }
    }

    _log( "========== Log check end ==========\n", LOG__DEBUG );
}

/**
 * Checks for files created server side containing a timestamp and thus ensuring files are being
 * copied over.
 */
function timestampCheck()
{
    global $cmdargs, $periods, $criticals, $warnings, $unknowns;

    _log( "========== Timestamp check start ==========", LOG__DEBUG );

    foreach( $cmdargs["backup"] as $backup )
    {
        if( isset( $params ) ) unset( $params );

        if( isset( $cmdargs["nagios"][ $backup[0]] ["TIMESTAMP"] ) )
            $params = $cmdargs["nagios"][ $backup[0] ]["TIMESTAMP"];
        else if( isset( $cmdargs["nagios"]["DEFAULT"]["TIMESTAMP"] ) )
            $params = $cmdargs["nagios"]["DEFAULT"]["TIMESTAMP"];

        $alias = isset( $cmdargs['nagios'][ $backup[0] ]["ALIAS"] ) ?  $cmdargs['nagios'][ $backup[0] ]["ALIAS"] : $backup[0];

        if( !isset( $params ) )
        {
            _log( sprintf( "INFO: Timestamp check: Skipped for '%s'." , $alias ), LOG__DEBUG );
            continue;
        }

        if( count( $params ) != 1 )
        {
            _log( sprintf( "ERROR: Timestamp check: Invalid paramters for '%s'." , $alias ), LOG__ERROR );
            continue;
        }

        // we should only really do this for the last (or second last if necessary) minimum period
        foreach( $cmdargs['retain'] as $name => $howmany ) 
        {
            if( $howmany < 2 )
            {
                _log( sprintf( "ERROR: Timestamp Check: Lowest retention period for '%s' needs at least 2 snapshots. " , $alias ), LOG__ERROR );
                continue 2;
            }
            break;
        }

        $path = sprintf( "%s%s.%d/%s%s", $cmdargs["snapshot_root"], $name, 0, $backup[1], $params[0] );

        if( !file_exists( $path ) )
        {
            // hmmm, maybe a backup in progress, try the next
            $path = sprintf( "%s%s.%d/%s%s", $cmdargs["snapshot_root"], $name, 1, $backup[1], $params[0] );

            if( !file_exists( $path ) )
            {
                _log( sprintf( "Timestamp - skipping check as timestamp file %s does not exist", $path ), LOG__ERROR );
                continue;
            }
        }

        $ts = intval( file_get_contents( $path ) );

        if( $ts === false )
        {
            _log( sprintf( "Timestamp - skipping check as timestamp file %s could not be read", $path ), LOG__ERROR );
            continue;
        }

        _log( sprintf( "Timstamp for %s: %s", $alias, date( 'Y-m-d H:i:s', $ts ) ), LOG__DEBUG );

        $time_diff = mktime() - $ts;

        if( $time_diff > ( 2 * $periods[ $name ] ) )
        {
            $m = sprintf( "Timestamp for %s is greater than it should be.", $alias );
            _log( "CRITICAL: $m", LOG__VERBOSE );
            $criticals .= "$m ";
            setStatus( STATUS_CRITICAL );
        }
    }

    _log( "========== Timestamp check end ==========\n", LOG__DEBUG );
}

/**
 * Checks that retention directories are being rotated.
 * 
 * Each time a directory is rotated, its ctime is reset. So, if all daily
 * directories have been rotated correctly, their ctime should be within
 * the last 24 hours.
 *
 */
function rotationCheck()
{
    global $cmdargs, $periods, $warnings, $criticals;

    _log( "========== Rotation check start ==========", LOG__DEBUG );

    foreach( $cmdargs["retain"] as $name => $howmany )
    {
        $maxage = $periods[ $name ];
        $maxage_ts = mktime() - $maxage;

        for( $i = 0; $i < $howmany; $i++ )
        {
            $path = sprintf( "%s%s.%d", $cmdargs["snapshot_root"], $name, $i );

            if( !file_exists( $path) )
            {
                _log( sprintf( "INFO: Rotation check: Retention directory '%s' does not exist", $path ) , LOG__VERBOSE );
                continue;
            }

            $stat = stat( $path );

            _log( sprintf( "INFO: Rotation check: Retention directory '%s' has ctime: %s", 
                    $path, date( 'Y-m-d H:i:s', $stat['ctime'] ) ), LOG__DEBUG 
            );           

            $relative_age = $maxage_ts - $stat['ctime'];
            if( $relative_age > 0 )
            {
                _log( sprintf( "WARN: Rotation check: Retention directory '%s' is %d seconds too old", 
                        $path, $relative_age ), LOG__VERBOSE 
                );
                setStatus( STATUS_WARNING );
                $warnings .= sprintf( "Rotation Check: %s.%d is %s seconds past expected rotation",
                                $name, $i, $relative_age );
            }
        }
    }

    _log( "========== Rotation check end ==========\n", LOG__DEBUG );
}

/**
 * Checks that retention directories are being created.
 * 
 * This function relies on a NAGIOS configuration directive in the configuration file
 * callled FIRSTRUN which is added on this scripts firstrun if it does not exist already.
 * 
 * This parameter is used to calculate how many rentetion directories we should expect 
 * for each retention period.
 *
 */
function dirCreationCheck()
{
    global $cmdargs, $periods, $warnings, $criticals;

    if( $cmdargs['FIRSTRUN'] === false )
    {
        _log( "FIRSTRUN not set in configuration file - skipping directory creation checks", LOG__ERROR );
        $warnings .= "Cannot run directory creation checks. ";
        setStatus( STATUS_WARNING );
        return;
    }

    _log( "========== Directory creation check start ===========", LOG__DEBUG );

    foreach( $cmdargs["retain"] as $name => $howmany )
    {
        // how many retention directories should we expect to have for this period?
        $availableTime = mktime() - $cmdargs['FIRSTRUN'];
        $expectedDirs = floor( $availableTime / $periods[ $name ] );

        if( $expectedDirs > $howmany )
            $expectedDirs = $howmany;

        _log( "Checking for {$expectedDirs} expected directories for retention period {$name}", LOG__DEBUG );

        for( $i = 0; $i < $howmany; $i++ )
        {
            if( $i == $expectedDirs )
                break;

            $path = sprintf( "%s%s.%d", $cmdargs["snapshot_root"], $name, $i );

            if( !file_exists( $path) )
            {
                _log( sprintf( "WARN: Directory creation check: Retention directory '%s' is expected but does not exist", 
                        $path ), LOG__VERBOSE 
                );
                setStatus( STATUS_CRITICAL );
                $criticals .= sprintf( "Directory Creation Check: %s.%d is expected but missing. ", $name, $i );
            }
        }
    }

    _log( "========== Directory creation check end ==========\n", LOG__DEBUG );
}


/**
 * Parses the rsnapshot configuration file (and any `include_conf` files for specific rsnapshot
 * directives and hidden plugin configuration directives.
 *
 *
 * Possible *hidden* Nagios Plugin Configuration Directives
 *
 * Configuration directives for this plugin can be placed in comments on lines starting `#NAGIOS`.
 *
 * `$KEY` below is the token following a `backup` directive
 *
 * Here are what we look for now:
 *
 * Settings for minimum file count check.
 *
 *     #NAGIOS $KEY MINFILES x1 x2
 *     x1 minimum file count for warning state.
 *     x2 minimum file count for critical state.
 *
 * Settings for minimum backup size test.
 *
 *     #NAGIOS $KEY MINSIZE y1 y2
 *     y1 minimum backup size for warning state.
 *     y2 minimum backup size for critical state.
 *
 * Settings for time stamp check.
 *
 *     #NAGIOS $KEY TIMESTAMP $FILE z1 z2
 *     $FILE - specific file name which can be found in the snapshot_root/$retention_period.0/$bupname
 *          directory and containing a UNIX timestamp value.
 *     z1 minimum time interval from now for warning state (default: rentention period time as defined in $periods above).
 *     z2 minimum time interval from now for error state  (default: 2 x rentention period time as defined in $periods above).
 *
 * Setting an alias allows the plugin to print the alias in any messages rather than the unwieldy backup string
 *
 *     #NAGIOS $key ALIAS alias
 *
 * Overriding time period values as defined in `$periods` above
 *
 *     #NAGIOS TPERIOD period val
 *     period time period name to override.
 *     val new value
 *
 * If $key is `DEFAULT` then it will be stored as a default option and these default
 * options will be used for all backups unless whey have defined their own options.
 *
 * @param string $confFile The configuration file to parse
 * @return void
 */
function parseConfig( $confFile, $primary = true )
{
    global $cmdargs, $periods;

    $fp = fopen( $confFile, 'r' );

    if( !$fp )
    {
        _log( sprintf( "Configuration file [%s] does not exist or cannot be read.", $confFile ), LOG__ERROR );
        exit( STATUS_UNKNOWN );
    }

    _log( sprintf( "PARSING CONF: opened config file: %s", $confFile ), LOG__DEBUG );

    while( ( $buffer = fgets( $fp, 4096 ) ) !== false )
    {
        $tokens = parseConfigurationLine( $buffer );

        if( count( $tokens ) < 2 )
            continue;

        switch( $tokens[0] )
        {
            case "include_conf":
                _log( sprintf( "PARSING CONF: recursively parsing included config file: %s", $tokens[1] ), LOG__DEBUG );
                parseConfig( $tokens[1], false );
                break;

            case "config_version":
                if( $tokens[1] != $cmdargs['config_version'] )
                    _log(  "WARNING: Configuration version is different from that supported by script.", LOG__ERROR );

                _log( sprintf( "PARSING CONF: found configuration file version: %s", $tokens[1] ), LOG__DEBUG );
                $cmdargs["version"] = $tokens[1];
                break;

            case "retain":
            case "interval":
                _log( sprintf( "PARSING CONF: found retention period: %s => %s", $tokens[1], $tokens[2] ), LOG__DEBUG );
                $cmdargs["retain"][ $tokens[1] ] = $tokens[2];
                break;

            case "snapshot_root":
                if( substr( $tokens[1], -1 ) != '/' )
                     $tokens[1] .= '/';

                _log( sprintf( "PARSING CONF: found snapshot root %s", $tokens[1] ), LOG__DEBUG );
                $cmdargs["snapshot_root"] = $tokens[1];
                break;

            case "logfile":
                _log( sprintf( "PARSING CONF: found log file %s", $tokens[1] ), LOG__DEBUG );
                $cmdargs["logfile"] = $tokens[1];
                break;

            case "backup":
                array_shift( $tokens );
                _log( sprintf( "PARSING CONF: found backup: %s", implode( ' ', $tokens ) ), LOG__DEBUG );
                $cmdargs["backup"][] = $tokens;
                break;

            case "backup_script":
                array_shift( $tokens );
                _log( sprintf( "PARSING CONF: found backup_script: %s", implode( ' ', $tokens ) ), LOG__DEBUG );
                $cmdargs["backup"][] = $tokens;
                break;

            case "#NAGIOS":
                if( $tokens[1] == "TPERIOD" )
                {
                    _log( sprintf( "PARSING CONF: found plugin config directive TPERIOD: %s => %s", $tokens[2], $tokens[3] ), LOG__DEBUG );
                    $periods[ $tokens[2] ] = $tokens[3];
                }
                else if( $tokens[2] == "ALIAS" )
                {
                    _log( sprintf( "PARSING CONF: found plugin config directive ALIAS for %s: %s => %s", $tokens[1], $tokens[2], $tokens[3] ), LOG__DEBUG );
                    $cmdargs["nagios"][ $tokens[1] ][ $tokens[2] ] = $tokens[3];
                }
                else if( $tokens[1] == "FIRSTRUN" )
                {
                    _log( sprintf( "PARSING CONF: found plugin config directive FIRSTRUN: %s", date( 'Y-m-d H:i:s', $tokens[2] ) ), LOG__DEBUG );
                    $cmdargs[ $tokens[1] ] = $tokens[2];
                }
                else
                {
                    array_shift( $tokens );
                    $a = array_shift( $tokens );
                    $b = array_shift( $tokens );
                    _log( sprintf( "PARSING CONF: found plugin config directive %s: %s", $a, $b ), LOG__DEBUG );
                    $cmdargs["nagios"][ $a ][ $b ] = $tokens;
                }
                break;

            default:
                // ignored directive
                break;
        }
    }

    fclose( $fp );

    if( $primary && !isset( $cmdargs['FIRSTRUN'] ) )
    {
        $fp = fopen( $confFile, 'a' );

        if( $fp )
        {
            $cmdargs['FIRSTRUN'] = mktime();
            fwrite( $fp, "\n\n# Added by Nagios check_rsnapshot.php and is required for directory creation checks\n");
            fwrite( $fp, "# plugin first run at " . date( 'Y-m-d H:i:s' ) . "\n" );
            fwrite( $fp, "#NAGIOS\tFIRSTRUN\t" . mktime() . "\n\n" );
            fclose( $fp );
        }
        else
        {
            $cmdarg['FIRSTRUN'] = false;
            _log( "Need write access to configuration file to set FIRSTRUN paramater. Directory creation checks will not work without this.", LOG__ERROR );
        }
    }
}

/**
 * Parses a tab separated rsnapshot configuration file into tokens
 *
 * @param string $line Line to parse
 * @return array Array of tokens
 */
function parseConfigurationLine( $line )
{
    $parts = explode( "\t", $line );
    $tokens = array();
    foreach( $parts as $token )
    {
        $token = trim( $token );
        if( $token )
            $tokens[] = $token;
    }
    return $tokens;
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

        //disabling or enabling checks
        if( substr( $argv[$i], 2, 8 ) == 'disable-' || substr( $argv[$i], 2, 7 ) == 'enable-' )
        {
            $op    = substr( $argv[$i], 2, 7 ) == "disable" ? 0 : 1;
            $check = ( $op ? substr( $argv[$i], 9 ) : substr( $argv[$i], 10 ) );

            if( $check == "all" )
            {
                foreach( $checkOptions as $key => $check )
                    $checksEnabled[ $key ] = $op;
            }
            else if( in_array( $check, $checkOptions ) )
            {
                $checksEnabled[ $checkOptions[ $check ] ] = $op;
            }
            else
            {
                _log( sprintf( "ERR: Unknown option %s. Use help for more details.", $argv[$i] ), LOG__ERROR );
                exit( STATUS_UNKNOWN );
            }
            $i++;
            continue;
        }

        if( substr( $argv[$i], 2 ) == "help" )
            $argv[$i] = '-h';

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
                $cmdargs['configFile'] = $argv[$i+1];

                if( isset( $argv[ $i+2 ] ) )
                {
                    $periodsEnabled = array();

                    while( isset( $argv[ $i+2 ] ) )
                    {
                        if( in_array( $argv[ $i+2 ], $periods ) )
                        {
                            $periodsEnabled[] = $argv[$i+2];
                            $i++;
                        }
                        else if( $argv[$i+2][0] != "-" )
                        {
                            _log(  "Bad period provided. Use help for more details.", LOG__ERROR );
                            exit( STATUS_UNKNOWN );
                        }
                        else
                            break;
                    }
                }
                $i++;
                break;

            case 'h':
            case '?':
                printHelp();
                exit( STATUS_OK );
                break;

            default:
                $i++;
        }

    }

    //check the configuration file
    if( !file_exists( $cmdargs['configFile'] ) || !is_readable( $cmdargs['configFile'] ) )
    {
        _log( sprintf( "Configuration file [%s] does not exist or cannot be read.", $cmdargs['configFile'] ), LOG__ERROR );
        exit( STATUS_UNKNOWN );
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
{$progname} -c path [period [period [...]]] [-v] [-d] [--disable-xxx] [--enable-xxx]
            [-V] [-h] [-?] [--help]

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

{$progname} - Nagios plugin to check the status of rsnapshot backups
Copyright (c) 2004 - 2012 Open Source Solutions Ltd - http://www.opensolutions.ie/

{$progname} -c path [period [period [...]]] [-v] [-d] [--disable-xxx] [--enable-xxx]
           [-V] [-h] [-?] [--help]

Options:

 -h,-?,--help
    Print detailed help screen.
 -V
    Print version information.
 -c
    Path to configuration file. You can also optionally specify the
    retention periods you want checked rather than the default of all.
 -v
    Verbose output
 -d
    Debug output

 All checks are enabled by default. Checks can be individuall disabled (or
 enabled following a --disable-all) using --enable-xx or --disable-xx as
 appropriate (--disable versions shown below):

    --disable-all (--enable-all - default)
        Disables (or enabled) all checks.

    --disable-log
        Disables log check.

    --disable-dir-creation
        Disables directory creation-check.

    --disable-timestamp
        Disables timestamp check.

    --disable-rotation
        Disables rotaation

    --disable-minfiles
        Disables minimum file count test.

    --disable-minsize
        Disables minimus file size test.


END_USAGE;

}

