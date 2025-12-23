<?php

set_time_limit(0);

$response = array();

if (isset($_REQUEST["cmd"])) {
    $debugHost = $_REQUEST["debugHost"] ?? 'localhost';
    $debugPort = $_REQUEST["debugPort"] ?? 9000;

    $socket = @stream_socket_client("tcp://" . $debugHost . ":" . $debugPort);
    if ($socket) {
        switch ($_REQUEST["cmd"]) {
            case "STOP":
                stream_set_timeout($socket, 1);
                fwrite($socket, "STOP\x00");
                $response["status"] = "";
                break;
            case "START":
            case "CONTINUE":
            case "COMMAND":
                stream_set_timeout($socket, 600); // wait up to 10 minutes
                $xCmd = $_REQUEST["xCmd"] ?? '';
                fwrite($socket, $xCmd . "\x00");
                $buffer = "";
                while (false !== ($char = fgetc($socket))) {
                    if ($char == "\x00")
                        break;
                    else
                        $buffer .= $char;
                }
                if (substr($xCmd, 0, 4) == "EVAL" || substr($xCmd, 0, 6) == "GETVAR")
                    $response["data"] = $buffer;
                else {
                    $bufferParts = explode(" ", $buffer);
                    $response["status"] = $bufferParts[0] ?? '';
                    if (($bufferParts[0] ?? '') == "break" && $_REQUEST["cmd"] != "START")
                        $response["stack"] = $bufferParts[1] ?? '';
                }
                break;
            case "BREAK":
                $action = $_REQUEST["action"] ?? '';
                $filename = $_REQUEST["filename"] ?? '';
                $line = $_REQUEST["line"] ?? '';
                fwrite($socket, "BREAK " . $action . " " . $filename . " " . $line . "\x00");
                break;
            case "KEY":
                stream_set_timeout($socket, 5); // wait only 5 seconds, process may be busy, which is OK
                $sessionKey = $_REQUEST["sessionKey"] ?? '';
                fwrite($socket, "KEY " . $sessionKey . "\x00");
                $buffer = "";
                $socketResponse = false;
                while (false !== ($char = fgetc($socket))) {
                    $socketResponse = true;
                    if ($char == "\x00")
                        break;
                    else
                        $buffer .= $char;
                }
                $response["ok"] = ($buffer == "OK" || !$socketResponse);
                break;
        }
        fclose($socket);
    } else {
        $response["status"] = "";
        $response["err"] = "debugSessionLost";
    }
} else {
    $response["status"] = "";
    // No command provided
}

header("Content-Type: application/json; charset=UTF-8");
echo json_encode($response);

?>