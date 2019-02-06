<?php
#
# This is a simple socket server for Obsidian software
#
# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
#
# Version: 1.0
# Author: DEMR
#
# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Variables
#
# You should only modify the port variable
$protocol = "tcp";
$server = "0.0.0.0";
$port = "25003";
$timeout = -1;
$current_path = dirname(__FILE__);

# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Logs
#
$error_log = "{$current_path}/obsidian_listener_error.log";
$access_log = "{$current_path}/obsidian_listener_access.log";

function log_msg($log, $msg) {
  $text = date("Ymd.His") . ": " . $msg . "\n";
  file_put_contents($log, $text, FILE_APPEND);
}
    
# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Xymon script
#
$xymonscr = "{$current_path}/xymon.sh";

# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Socket Server
#
$connectionstr = $protocol . "://" . $server . ":" . $port;
$socket = stream_socket_server($connectionstr, $errno, $errstr);
if (!$socket) {
  log_msg($error_log, "$errno $errstr");
}
else {
  # Begin listening for connections
  while (true) {
    while ($conn = stream_socket_accept($socket, $timeout)) {
      # Get client's data:
      $clientname = stream_socket_get_name($conn, true);
      log_msg($access_log, "Receiving request from: {$clientname}");
      # Get client's message:
      $clientmsg = fread($conn, 1024);
      # Parse client message:
      parse_str($clientmsg, $params);
      $command_params = " -H";
      $requested_host = "";
      $requested_service ="";
      $params_ok = true;
      switch ($params['option']) {
        case "hosts":
          $command_params = " -H";
          break;
        case "services":
          $requested_host = $params['host'];
          if (empty($requested_host))  {
            $params_ok = false;
            log_msg($error_log, "Error parsing parameters in services");
          }
          else {
            $command_params = " -S {$requested_host}";
          }
          break;
        case "data":
          $requested_host = $params['host'];
          $requested_service = $params['service'];
          if (empty($requested_host) or empty($requested_service)) {
            $params_ok = false;
            log_msg($error_log, "Error parsing parameters in data");
          }
          else {
            $command_params = " -G {$requested_host} {$requested_service}";
          }
          break;
        default:
          $params_ok = false;
          log_msg($error_log, "Error parsing parameters: {$params['option']}");
          break;
      }
      if ($params_ok) {
        log_msg($access_log, "Client message: {$clientmsg}");
        log_msg($access_log, "Parsed parameters: {$command_params}");
        $command = "{$xymonscr} {$command_params}";
        $retval = 0;
        $result = array();
        exec($command, $result, $retval);
        if ($retval != 0) {
          log_msg($error_log, "Error executing command: {$result[0]}");
        }
        else {
          foreach ($result as $r) {
            fwrite($conn, $r . "\n");
          }
        }
      }
      fclose($conn);
    }
  }
  fclose($socket);
}
?>
