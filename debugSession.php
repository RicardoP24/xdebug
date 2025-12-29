
<?php

require('vendor/autoload.php');

ini_set("xdebug.mode", "debug");
ini_set("xdebug.start_with_request", "trigger");

function updateTarget($sessionID, $sessionTab, $xData="", $remove=false) {
$par11 = array();
$par11["SSID"][0]["val1"] = $sessionID;
$par11["SNUM"][0]["val1"] = $sessionTab;
if ($dat11 = exec_sql("I0011", "read", $par11)) {
$sessData = igbinary_unserialize($dat11[0]["Sessione_dati"]);
if ($remove) {
if ($sessData["xdebug"] == $xData) $sessData["xdebug"] = "";
else return;
}
else $sessData["xdebug"] = $xData;
$fld11["fld"]["I0011"][0]["Sessione_id"] = $sessionID;
$fld11["fld"]["I0011"][0]["Sessione_numero"] = $sessionTab;
$fld11["fld"]["I0011"][0]["Sessione_dati"] = igbinary_serialize($sessData);
return exec_sql("I0011", "update", $fld11);
}
}

function updInitBreaks($initBreaks, $updString) {
$updBreak = explode(" ", $updString);
if ($updBreak[0] == "add") $initBreaks[] = array($updBreak[1], $updBreak[2]);
elseif ($updBreak[0] == "remove") {
$oldBreaks = $initBreaks;
$initBreaks = array();
if ($updBreak[1]) foreach ($oldBreaks as $oldBreak) if (strtolower($oldBreak[0]) != strtolower($updBreak[1]) || $oldBreak[1] != $updBreak[2]) $initBreaks[] = $oldBreak;
}
return $initBreaks;
}

set_time_limit(0);

$UTL = 1;
include_once("eveHelper.inc");
setG("leaveSessionAlone", true);

$initialPort = 9001;
$debugPortX = 9003;
$response = array();

// Check if XDEBUG is available
if (!is_callable("xdebug_break")) $response["err"] = "debugNoXdebug";

// Lookup for free port and establish server + gui sockets
if (!$response["err"]) {
$debugHost = gethostbyname($HOSTNAME);
$sessionKey = rand();
//for ($port=$initialPort; $port<($initialPort+50); $port+=2) {
$testConnection = @fsockopen($debugHost, $debugPortX, $errno, $errstr, 1);
if (is_resource($testConnection)) fclose($testConnection);
else {
// $debugPortX = $port;

$server = @stream_socket_server("tcp://".$debugHost.":".$debugPortX, $errno, $errstr);
if ($server === false) {
$response["err"] = "debugSocketOpenError";
$response["errVar"] = $errno." - ".$errstr;
}
else {
$debugPortG = 9004;
//$debugPortG = $port + 1;
$gui = @stream_socket_server("tcp://".$debugHost.":".$debugPortG, $errno, $errstr);
if ($gui === false) {
@stream_socket_shutdown($server, STREAM_SHUT_RDWR);
$response["err"] = "debugSocketOpenError";
$response["errVar"] = $errno." - ".$errstr;
}
}
//break;
//}
}
if (!$debugPortX) {
$response["err"] = "debugSocketPortError";
$response["errVar"] = $initialPort." - ".($initialPort+50);
}
}

// Register in target
if (!$response["err"]) {
$DB->set_trans("B");
if (updateTarget($_REQUEST["sessionId"], $_REQUEST["sessionTab"], $debugHost.":".$debugPortX.":".$sessionKey)) {
$DB->set_trans("C");
$response["debugHost"] = $debugHost;
$response["debugPortX"] = $debugPortX;
$response["debugPortG"] = $debugPortG;
$response["sessionKey"] = $sessionKey;
$response["pid"] = getmypid();
}
else {
$DB->set_trans("R");
$response["err"] = "debugTargetError";
@stream_socket_shutdown($server, STREAM_SHUT_RDWR);
@stream_socket_shutdown($gui, STREAM_SHUT_RDWR);
}
}

// Session not started for some reason
if ($response["err"]) {
header("Content-Type: application/json; charset=UTF-8");
echo json_encode($response);
exit(0);
}

// Respond to frontend
ob_start();
echo json_encode($response);
header("Content-Type: application/json");
header("Content-Encoding: none");
header("Content-Length: ".ob_get_length());
header("Connection: close");
ob_end_flush();
ob_flush();
flush();
if (is_callable('fastcgi_finish_request')) fastcgi_finish_request();

$initCmds = array("feature_set -n max_children -v 1000", "feature_set -n max_depth -v 10", "feature_set -n max_data -v 1024000");
$initBreaks = array();
if ($_REQUEST["breaks"]) foreach (explode(";", $_REQUEST["breaks"]) as $initBreak) $initBreaks[] = explode(",", $initBreak);
$status = "WAIT";
$lastKey = intval(microtime(true));

// Infinite loop, with wait for XDebug / frontend (interrupted by "STOP" or missing "KEY" commands)
while (true) {
$connection = @stream_socket_accept($server, 30, $clientAddress);
if ($clientAddress) {
error_log("\n ENTROU1? ".print_r($vals, true), 3, "/tmp/ui5.txt");

$buffer = "";
while (false !== ($char = fgetc($connection))) {
error_log("\n ENTROU2? ".print_r($char, true), 3, "/tmp/ui5.txt");

// Read until NULL and acumulate in $buffer
if ($char == "\x00") {

// XML: comunication from XDebug
if (substr($buffer, 0, 5) == "<?xml") {

$parser = xml_parser_create();
xml_parse_into_struct($parser, $buffer, $vals);
xml_parser_free($parser);

// INIT: start session, set features + breakpoints and then wait for frontend
if ($vals[0]["tag"] == "INIT") {
$cmd = $cmdGUI = "";
$tid = 1;
$init = $initCmds;
if ($initBreaks) foreach ($initBreaks as $break) $init[] = "breakpoint_set -t line -f ".$break[0]." -n ".$break[1];
$initSeq = 0;
$breaks = array();
$status = "INIT";
}
// Response to breakpoint set
elseif ($vals[0]["attributes"]["COMMAND"] == "breakpoint_set") {
$cmdParts = explode(" ", $cmd);
$breaks[strtolower($cmdParts[4])."_".$cmdParts[6]] = $vals[0]["attributes"]["ID"];
}
// Response to general breakpoint remove, proceed until all are removed
elseif ($vals[0]["attributes"]["COMMAND"] == "breakpoint_remove" && $cmdGUI == "BREAK remove ") {
if ($breaks) $cmd = "breakpoint_remove -d ".array_shift($breaks);
else $cmdGUI = "";
}

error_log("\n debugSess".print_r($cmdGUI, true), 3, "/tmp/ui5.txt");
// Main cycle
if (($status == "INIT" || $status == "RUN") && $cmdGUI != "BREAK remove ") {
// INIT phase, execute all commands before wait for frontend
if ($init[$initSeq]) {
$cmd = $init[$initSeq];
$initSeq++;
}
else {
// Respond to frontend, if latest command came from there
$responseGUI = "";
if ($cmdGUI) {
// Response from XDebug to call stack get, issued because breakpoint was reached
// this is where response to GUI is sent with status break + all call stack
if ($vals[0]["attributes"]["COMMAND"] == "stack_get") {
$allStack = array();
for ($i=1; $vals[$i] && $vals[$i]["attributes"]["FILENAME"]; $i++) $allStack[] = array("file"=>parse_url($vals[$i]["attributes"]["FILENAME"], PHP_URL_PATH), "line"=>$vals[$i]["attributes"]["LINENO"], "where"=>$vals[$i]["attributes"]["WHERE"]);
$responseGUI = "break ".json_encode($allStack);
}
// EVAL: expresssion evaluation, GETVAR: variable output
elseif (substr($cmdGUI, 0, 4) == "EVAL" || substr($cmdGUI, 0, 6) == "GETVAR") {
if ($vals[1]["tag"] == "PROPERTY") $responseGUI = $vals[1]["value"] && $vals[1]["attributes"]["ENCODING"] == "base64" ? $vals[1]["value"] : "%NONE%";
else $responseGUI = "ERRMSG".$vals[2]["value"];
}
// Continuation or stop commands:
// 1. break reached, get stack and respond to gui afterwards
// 2. stop or end of script, place GUI in wait state
elseif ($vals[0]["attributes"]["STATUS"]) {
$xdebugStatus = $vals[0]["attributes"]["STATUS"];
if ($xdebugStatus == "break") {
if ($cmdGUI == "START") $responseGUI = "break";
else $cmd = "stack_get";
}
elseif ($xdebugStatus && in_array($xdebugStatus, array("stopping", "stopped"))) {
$responseGUI = "wait";
$cmd = "run";
$status = "WAIT";
}
else $responseGUI = "?";
}
else $responseGUI = "?";
if ($responseGUI) {
if ($responseGUI == "%NONE%") $responseGUI = "";
fwrite($connectionGUI, $responseGUI."\x00");
fclose($connectionGUI);
$cmdGUI = "";
}
}
// Wait for next frontend command
if (!$cmdGUI && $responseGUI != "wait") {
$cmd = "";
while (true) {
$connectionGUI = @stream_socket_accept($gui, 30, $guiAddress);
if ($guiAddress) {
$cmdGUI = "";
while (false !== ($char = fgetc($connectionGUI))) {
if ($char == "\x00") break;
else $cmdGUI .= $char;
}
if ($cmdGUI == "START") {
if ($status == "INIT") {
$cmd = "run";
$status = "RUN";
}
else {
fwrite($connectionGUI, $responseGUI."\x00");
fclose($connectionGUI);
}
}
elseif (substr($cmdGUI, 0, 5) == "BREAK") {
$updString = substr($cmdGUI, 6);
$initBreaks = updInitBreaks($initBreaks, $updString);
$updBreak = explode(" ", $updString);
if ($updBreak[0] == "add") $cmd = "breakpoint_set -t line -f ".$updBreak[1]." -n ".$updBreak[2];
elseif ($updBreak[0] == "remove" && $breaks) {
if ($updBreak[1]) {
$breakId = $breaks[strtolower($updBreak[1])."_".$updBreak[2]];
unset($breaks[strtolower($updBreak[1])."_".$updBreak[2]]);
}
else $breakId = array_shift($breaks);
$cmd = "breakpoint_remove -d ".$breakId;
}
else fclose($connectionGUI);
}
elseif (substr($cmdGUI, 0, 4) == "EVAL") {
$cmd = "eval";
$cmdData = base64_encode("eval(\"".str_replace("\$", "\\\$" , addslashes(substr($cmdGUI, 5))).(substr($cmdGUI, -1)===";"?"":";")."\")");
}
elseif (substr($cmdGUI, 0, 6) == "GETVAR") {
$cmd = "eval";
$cmdData = base64_encode("eval(\"return print_r(".str_replace("\$", "\\\$" , addslashes(substr($cmdGUI, 7))).", true);\")");
}
elseif (substr($cmdGUI, 0, 3) == "KEY") {
if (substr($cmdGUI, 4) == $sessionKey) {
$lastKey = intval(microtime(true));
fwrite($connectionGUI, "OK\x00");
}
else fwrite($connectionGUI, "\x00");
fclose($connectionGUI);
}
else $cmd = $cmdGUI;
}
if ($cmd || $lastKey < (intval(microtime(true))-60)) break;
}
if (!$cmd) $cmd = "stop";
}
}
}

// Send command to XDebug (if stop is issued, exit program)
fwrite($connection, $cmd." -i ".$tid++.($cmdData?" -- ".$cmdData:"")."\x00");
if ($cmd == "stop") {
if ($cmdGUI == "stop") {
fwrite($connectionGUI, "\x00");
fclose($connectionGUI);
}
break 2;
}
else $lastKey = intval(microtime(true));
if ($cmdData) $cmdData = "";

}

// Communication not from XDebug (stop service, breakpoint updates outside session, get session key)
if ($buffer == "STOP") break 2;
elseif (substr($buffer, 0, 5) == "BREAK") {
$initBreaks = updInitBreaks($initBreaks, substr($buffer, 6));
$lastKey = intval(microtime(true));
fwrite($connection, "\x00");
break;
}
elseif (substr($buffer, 0, 3) == "KEY") {
if (substr($buffer, 4) == $sessionKey) {
$lastKey = intval(microtime(true));
fwrite($connection, "OK\x00");
}
else fwrite($connection, "\x00");
break;
}

$buffer = "";

}
else $buffer .= $char;


}

error_log("\n CLOSE ".print_r($valsc, true), 3, "/tmp/ui5.txt");
fclose($connection);

}

if ($lastKey < (intval(microtime(true))-60)) break;

}

error_log("\n CLOSE GUI ".print_r($gui, true), 3, "/tmp/ui5.txt");
error_log("\n CLOSE SERVER ".print_r($server, true), 3, "/tmp/ui5.txt");
@stream_socket_shutdown($gui, STREAM_SHUT_RDWR);
@stream_socket_shutdown($server, STREAM_SHUT_RDWR);

updateTarget($_REQUEST["sessionId"], $_REQUEST["sessionTab"], $debugHost.":".$debugPortX.":".$sessionKey, true);

?> 