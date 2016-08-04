#! /usr/local/bin/php
<?php

/**
 * notify-by-pushover.php - Nagios notification plugin
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
 */


// This script sends Nagios plugins to Pushover
//
// See: http://www.barryodonovan.com/index.php/2013/05/31/nagios-icinga-alerts-via-pushover
//
// USAGE: notify-by-pushover.php <HOST/SERVICE> <APP_KEY> <USER_KEY> <TYPE> <STATE>


date_default_timezone_set('Europe/Dublin');
define( "VERSION", '1.0.0' );

ini_set( 'max_execution_time', '55' );

ini_set( 'display_errors', true );
ini_set( 'display_startup_errors', true );

define( 'PO_PRI_LOW',    -1 );
define( 'PO_PRI_NORMAL', 0  );
define( 'PO_PRI_HIGH',   1  );
define( 'PO_PRI_EMERG',  2  );  // not used at present in this script


// get the message from STDIN
$message = trim( fgets( STDIN ) );

// get the parameters

$mode  = isset( $argv[1] ) ? $argv[1] : false; // SERVICE or HOST
$app   = isset( $argv[2] ) ? $argv[2] : false;
$user  = isset( $argv[3] ) ? $argv[3] : false;
$type  = isset( $argv[4] ) ? $argv[4] : false; // NOTIFICATIONTYPE
$state = isset( $argv[5] ) ? $argv[5] : false; // STATE

if( !$mode || !$app || !$user || !$type || !$state )
    die( "ERROR - USAGE: notify-by-pushover.php <HOST/SERVICE> <APP_KEY> <USER_KEY> <TYPE> <STATE>\n\n" );

switch( $state )
{
    case 'WARNING':
    case 'UNKNOWN':
        $priority = PO_PRI_LOW;
        break;

    case 'OK':
        $priority = PO_PRI_NORMAL;
        break;

   case 'DOWN':
   case 'CRITICAL':
        $priority = PO_PRI_HIGH;
        break;

    default:
        $priority = PO_PRI_NORMAL;
        break;
}

curl_setopt_array( $ch = curl_init(), array(
    CURLOPT_URL => "https://api.pushover.net/1/messages.json",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => array(
        "token" => $app,
        "user" => $user,
        "message" => $message,
        "title" => "Nagios Alert - $mode - $type - $state",
        "priority" => $priority
    )
));

curl_exec($ch);
curl_close($ch);




