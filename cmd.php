#!/usr/bin/php -q
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004 Ian Berry                                            |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | cacti: a php-based graphing solution                                    |
 +-------------------------------------------------------------------------+
 | Most of this code has been designed, written and is maintained by       |
 | Ian Berry. See about.php for specific developer credit. Any questions   |
 | or comments regarding this code should be directed to:                  |
 | - iberry@raxnet.net                                                     |
 +-------------------------------------------------------------------------+
 | - raXnet - http://www.raxnet.net/                                       |
 +-------------------------------------------------------------------------+
*/

/* do NOT run this script through a web browser */
if (!isset($_SERVER["argv"][0])) {
	die("<br><strong>This script is only meant to run at the command line.</strong>");
}

$start = date("Y-n-d H:i:s");
// print $start . "\n";
ini_set("max_execution_time", "0");
$no_http_headers = true;

include(dirname(__FILE__) . "/include/config.php");
include_once($config["base_path"] . "/lib/snmp.php");
include_once($config["base_path"] . "/lib/poller.php");
include_once($config["base_path"] . "/lib/rrd.php");
include_once($config["base_path"] . "/lib/graph_export.php");
include_once($config["base_path"] . "/lib/ping.php");

$verbosity = read_config_option("log_verbosity");

if ( $_SERVER["argc"] == 1 ) {
	$polling_items = db_fetch_assoc("SELECT * from poller_item ORDER by host_id");
}else{
	if ($_SERVER["argc"] == "3") {
		if ($_SERVER["argv"][1] <= $_SERVER["argv"][2]) {
			$polling_items = db_fetch_assoc("SELECT * from poller_item " .
					"WHERE (host_id >= " .
					$_SERVER["argv"][1] .
					" and host_id <= " .
					$_SERVER["argv"][2] . ") ORDER by host_id");
		}else{
			print "ERROR: Invalid Arguments.  The first argument must be less than or equal to the first.\n";
			print "USAGE: CMD.PHP [[first_host] [second_host]]\n";
			log_data("ERROR: Invalid Arguments.  This rist argument must be less than or equal to the first.");
		}
	}else{
		print "ERROR: Invalid Number of Arguments.  You must specify 0 or 2 arguments.\n";
		log_data("ERROR: Invalid Number of Arguments.  You must specify 0 or 2 arguments.");
	}
}

if ((sizeof($polling_items) > 0) && (read_config_option("poller_enabled") == "on")) {
	$failure_type = "";
	$host_down = false;
	$new_host  = true;
	$last_host = $current_host = "";

	// startup Cacti php polling server and include the include file for script processing
	$cactides = array(
		0 => array("pipe", "r"), // stdin is a pipe that the child will read from
		1 => array("pipe", "w"), // stdout is a pipe that the child will write to
		2 => array("pipe", "w")  // stderr is a pipe to write to
		);

	// create new ping socket for host pinging
	if (phpversion() >= "4.3")	$ping = new Net_Ping;

	if (function_exists("proc_open")) {
		$cactiphp = proc_open(read_config_option("path_php_binary") . " " . $config["base_path"] . "/script_server.php", $cactides, $pipes);
		$output = fgets($pipes[1], 1024);
		if (substr_count($output, "Started") != 0) {
			if ($verbosity == HIGH) {
				log_data("PHPSERVER: Server Started Properly\n");
			}
		}
		$using_proc_function = true;

	}else {
		$using_proc_function = false;
		if ($verbosity == HIGH) {
			log_data("WARNING: PHP version 4.3 or above is recommended for performance considerations.\n");
		}
	}

	foreach ($polling_items as $item) {
		$current_host = $item["hostname"];

		if ($current_host != $last_host) {
			$new_host = true;
			$host_down = false;
		}

		if ($new_host) {
			// perform a ping if PHP is greater than 4.3
			if (phpversion() >= "4.3") {
				$ping->ping($item["hostname"], $item["snmp_timeout"], 4);

				if (!isset($ping->time)) {
					$failure_typpe = "ICMP";
					$host_down = true;

					if ($verbosity == HIGH) {
						log_data("ERROR: ICMP Ping failed for Host:" . $item["hostname"] . ", assumed down.\n");
					}
				} else {
					if ($verbosity == HIGH) {
						log_data("ICMP: ". $item["hostname"] . " is " . $ping->time . " seconds.\n");
					}
				}
			} else {
				if ($verbosity == HIGH) {
					log_data("NOTE: PHP version is: " . phpversion() . " Please upgrade to PHP 4.3 or Above to obtain ping statistics.\n");
				}
			}

			// Perform an SNMP test for earlier versions of PHP
			if (!$host_down) {
				$last_host = $current_host;
				$output = cacti_snmp_get($item["hostname"],
					$item["snmp_community"],
					".1.3.6.1.2.1.1.5.0" ,
					$item["snmp_version"],
					$item["snmp_username"],
					$item["snmp_password"],
					$item["snmp_port"],
					$item["snmp_timeout"]);

				if ((substr_count($output, "ERROR") != 0) || ($output == "")) {
					$failure_type = "SNMP";
					$host_down = true;
					if ($verbosity == HIGH) {
						log_data("ERROR: SNMP Query failed for Host " . $item["hostname"] . ", assumed down.\n");
					}
				}
			}

			if ($host_down) {
				if (read_config_option("log_perror") == "on") {
					if ($failure_type == "ICMP")
						log_data(sprintf("ERROR: ICMP Ping failed for Host: '%s', assumed down.", $current_host));
					else
						log_data(sprintf("ERROR: SNMP Query failed for Host: '%s', assumed down.", $current_host));
				}
			} else {
				/* do the reindex check for this host */
				$reindex = db_fetch_assoc("select
					poller_reindex.data_query_id,
					poller_reindex.action,
					poller_reindex.op,
					poller_reindex.assert_value,
					poller_reindex.arg1
					from poller_reindex
					where poller_reindex.host_id=" . $item["host_id"]);

				if ((sizeof($reindex) > 0) && (!$host_down)) {
					if ($verbosity == HIGH) {
						log_data("Processing " . sizeof($reindex) . " items in the auto reindex cache for '" . $item["hostname"] . "'.\n");
					}

					foreach ($reindex as $index_item) {
						/* do the check */
						switch ($index_item["action"]) {
						case POLLER_ACTION_SNMP: /* snmp */
							$output = cacti_snmp_get($item["hostname"], $item["snmp_community"], $index_item["arg1"], $item["snmp_version"], $item["snmp_username"], $item["snmp_password"], $item["snmp_port"], $item["snmp_timeout"]);
							break;
						case POLLER_ACTION_SCRIPT: /* script (popen) */
							$output = exec_poll($index_item["arg1"]);
							break;
						}

						/* assert the result with the expected value in the db; recache if the assert fails */
						if (($index_item["op"] == "=") && ($index_item["assert_value"] != trim($output))) {
							print "Assert '" . $index_item["assert_value"] . "=" . trim($output) . "' failed. Recaching host '" . $item["hostname"] . "', data query #" . $index_item["data_query_id"] . ".\n";
							db_execute("insert into poller_command (poller_id,time,action,command) values (0,NOW()," . POLLER_COMMAND_REINDEX . ",'" . $item["host_id"] . ":" . $index_item["data_query_id"] . "')");
						}else if (($index_item["op"] == ">") && ($index_item["assert_value"] <= trim($output))) {
							print "Assert '" . $index_item["assert_value"] . ">" . trim($output) . "' failed. Recaching host '" . $item["hostname"] . "', data query #" . $index_item["data_query_id"] . ".\n";
							db_execute("insert into poller_command (poller_id,time,action,command) values (0,NOW()," . POLLER_COMMAND_REINDEX . ",'" . $item["host_id"] . ":" . $index_item["data_query_id"] . "')");
						}else if (($index_item["op"] == "<") && ($index_item["assert_value"] >= trim($output))) {
							print "Assert '" . $index_item["assert_value"] . "<" . trim($output) . "' failed. Recaching host '" . $item["hostname"] . "', data query #" . $index_item["data_query_id"] . ".\n";
							db_execute("insert into poller_command (poller_id,time,action,command) values (0,NOW()," . POLLER_COMMAND_REINDEX . ",'" . $item["host_id"] . ":" . $index_item["data_query_id"] . "')");
						}
					}
				}
         }

			$new_host = false;
			$last_host = $current_host;
		}

		if (!$host_down) {
			switch ($item["action"]) {
			case POLLER_ACTION_SNMP: /* snmp */
				$output = cacti_snmp_get($item["hostname"], $item["snmp_community"], $item["arg1"], $item["snmp_version"], $item["snmp_username"], $item["snmp_password"], $item["snmp_port"], $item["snmp_timeout"]);

				if ($verbosity == HIGH) {
					log_data("SNMP: " . $item["hostname"] . ":" . $item["snmp_port"] . ", dsname: " . $item["rrd_name"] . ", oid: " . $item["arg1"] . ", value: $output\n");
				}

				break;
			case POLLER_ACTION_SCRIPT: /* script (popen) */
				$output = exec_poll($item["arg1"]);

				if ($verbosity == HIGH) {
					log_data("CMD: " . $item["arg1"] . ", output: $output\n");
				}

				break;
			case POLLER_ACTION_SCRIPT_PHP: /* script (php script server) */
				$output = exec_poll_php($item["arg1"], $using_proc_function, $pipes, $cactiphp);

				if ($verbosity == HIGH) {
					log_data("CMD[PHP]: " . $item["arg1"] . ", output: $output");
				}

				break;
			} /* End Switch */

			if (isset($output)) {
				db_execute("insert into poller_output (local_data_id,rrd_name,time,output) values (" . $item["local_data_id"] . ",'" . $item["rrd_name"] . "',NOW(),'" . addslashes($output) . "')");
			}
		} /* Next Cache Item */
	} /* End foreach */

	// create new ping socket for host pinging
	if (phpversion() >= "4.3")	$ping->close_socket();

	if ($using_proc_function == true) {
		// close php server process
		fwrite($pipes[0], "quit\r\n");
		fclose($pipes[0]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		$return_value = proc_close($cactiphp);
	}
}else{
	if ($verbosity == HIGH) {
		log_data("ERROR: Either there are no items in the cache or polling is disabled\n");
	}
}

/* Let the poller server know about cmd.php being finished */
db_execute("insert into poller_time (poller_id, start_time, end_time) values (0, NOW(), NOW())");

?>