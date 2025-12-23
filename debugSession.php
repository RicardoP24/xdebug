<?php

require('vendor/autoload.php');

function debugLog($msg)
{
    $timestamp = date('Y-m-d H:i:s.') . substr((string) microtime(), 2, 3);
    error_log("[$timestamp] $msg\n", 3, "/www/debugSession.log");
}

debugLog("=== DEBUG SESSION START ===");
debugLog("SessionID: {$_REQUEST['sessionId']}, Tab: {$_REQUEST['sessionTab']}");
debugLog("Breaks recebidos: {$_REQUEST['breaks']}");

function updateTarget($sessionID, $sessionTab, $xData = "", $remove = false)
{
    debugLog("updateTarget called: remove=$remove, xData=$xData, sessionTab=$sessionTab, SSID=$sessionID");

    $par11 = array();
    $par11["SSID"][0]["val1"] = $sessionID;
    $par11["SNUM"][0]["val1"] = $sessionTab;

    if ($dat11 = exec_sql("I0011", "read", $par11)) {
        $sessData = igbinary_unserialize($dat11[0]["Sessione_dati"]);

        // ✅ CHAVE ÚNICA: SSID + Tab
        $sessionKey = $sessionID . ":" . $sessionTab;

        if ($remove) {
            // Remove se for ESTA sessão específica
            if ($sessData["xdebug"] == $xData || $xData == "") {
                $sessData["xdebug"] = "";
                debugLog("Dados xdebug removidos para Sessão=$sessionKey");
            } else {
                debugLog("Dados xdebug NÃO removidos (não correspondem). Esperado: '$xData', Atual: '{$sessData['xdebug']}'");
                return;
            }
        } else {
            // ✅ VERIFICAÇÃO CRÍTICA: 
            // Só matar processo antigo se for a MESMA SESSÃO (mesmo SSID + Tab)
            if ($sessData["xdebug"] && $sessData["xdebug"] != $xData) {
                $oldData = explode(":", $sessData["xdebug"]);
                $newData = explode(":", $xData);

                debugLog("⚠️ Processo antigo encontrado para Sessão=$sessionKey: {$sessData['xdebug']}");

                // Tentar enviar STOP ao processo antigo (mesma sessão)
                $oldSocket = @stream_socket_client("tcp://" . $oldData[0] . ":" . $oldData[1], $errno, $errstr, 2);
                if ($oldSocket) {
                    fwrite($oldSocket, "STOP\x00");
                    fclose($oldSocket);
                    debugLog("✅ STOP enviado ao processo antigo da MESMA sessão");
                    sleep(2);
                }
            }

            $sessData["xdebug"] = $xData;
            debugLog("Dados xdebug atualizados para Sessão=$sessionKey: $xData");
        }

        $fld11["fld"]["I0011"][0]["Sessione_id"] = $sessionID;
        $fld11["fld"]["I0011"][0]["Sessione_numero"] = $sessionTab;
        $fld11["fld"]["I0011"][0]["Sessione_dati"] = igbinary_serialize($sessData);

        return exec_sql("I0011", "update", $fld11);
    } else {
        debugLog("ERRO: Não foi possível ler sessão SSID=$sessionID, Tab=$sessionTab");
        return false;
    }
}

function updInitBreaks($initBreaks, $updString)
{
    $updBreak = explode(" ", $updString);
    if ($updBreak[0] == "add")
        $initBreaks[] = array($updBreak[1], $updBreak[2]);
    elseif ($updBreak[0] == "remove") {
        $oldBreaks = $initBreaks;
        $initBreaks = array();
        if ($updBreak[1])
            foreach ($oldBreaks as $oldBreak)
                if (strtolower($oldBreak[0]) != strtolower($updBreak[1]) || $oldBreak[1] != $updBreak[2])
                    $initBreaks[] = $oldBreak;
    }
    return $initBreaks;
}

set_time_limit(0);

$UTL = 1;
include_once("eveHelper.inc");
setG("leaveSessionAlone", true);

$initialPort = 9001;

$response = array();

// Check if XDEBUG is available
$response["err"] = null;
if (!is_callable("xdebug_break"))
    $response["err"] = "debugNoXdebug";

// Lookup for free port and establish server + gui sockets
if (!$response["err"]) {
    $debugHost = gethostbyname(gethostname());
    $sessionKey = rand();
    debugLog("Procurando porta livre. Host: $debugHost, SessionKey: $sessionKey");

    for ($port = $initialPort; $port < ($initialPort + 50); $port += 2) {
        debugLog("Testando porta: $port");

        $testConnection = @fsockopen($debugHost, $port, $errno, $errstr, 1);
        if (is_resource($testConnection)) {
            fclose($testConnection);
            debugLog("Porta $port JÁ EM USO (fsockopen conectou)");
        } else {
            debugLog("Porta $port parece livre (fsockopen falhou). Tentando bind...");

            $debugPortX = $port;
            // FIX: Removed so_reuseaddr to prevent port hijacking on Windows
            $context = stream_context_create([]);
            $server = @stream_socket_server("tcp://" . $debugHost . ":" . $debugPortX, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

            if ($server === false) {
                $response["err"] = "debugSocketOpenError";
                $response["errVar"] = $errno . " - " . $errstr;
                debugLog("ERRO ao bind socket server na porta $debugPortX: errno=$errno, errstr=$errstr");
            } else {
                debugLog("Socket server criado OK na porta $debugPortX");

                $debugPortG = $port + 1;
                debugLog("Tentando criar socket GUI na porta $debugPortG");

                $gui = @stream_socket_server("tcp://" . $debugHost . ":" . $debugPortG, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

                if ($gui === false) {
                    @stream_socket_shutdown($server, STREAM_SHUT_RDWR);
                    $response["err"] = "debugSocketOpenError";
                    $response["errVar"] = $errno . " - " . $errstr;
                    debugLog("ERRO ao bind socket GUI na porta $debugPortG: errno=$errno, errstr=$errstr");
                } else {
                    debugLog("Socket GUI criado OK na porta $debugPortG");
                    debugLog("*** PORTAS ALOCADAS COM SUCESSO: XDebug=$debugPortX, GUI=$debugPortG ***");
                }
            }
            break;
        }
    }

    if (!$debugPortX) {
        $response["err"] = "debugSocketPortError";
        $response["errVar"] = $initialPort . " - " . ($initialPort + 50);
        debugLog("ERRO: Nenhuma porta disponível entre $initialPort e " . ($initialPort + 50));
    }
}

if (!$response["err"]) {
    $DB->set_trans("B");

    debugLog("Limpando dados xdebug antigos APENAS da Tab {$_REQUEST['sessionTab']}");
    updateTarget($_REQUEST["sessionId"], $_REQUEST["sessionTab"], "", true);

    if (updateTarget($_REQUEST["sessionId"], $_REQUEST["sessionTab"], $debugHost . ":" . $debugPortX . ":" . $sessionKey)) {
        $DB->set_trans("C");
        $response["debugHost"] = $debugHost;
        $response["debugPortX"] = $debugPortX;
        $response["debugPortG"] = $debugPortG;
        $response["sessionKey"] = $sessionKey;
        //$response["xdebugTrigger"] = $sessionKey;//remover depois GS
        $response["pid"] = getmypid();
        debugLog("Target registrado. PID: " . getmypid() . " | Host:$debugHost | PortX:$debugPortX | PortG:$debugPortG | Key:$sessionKey | Tab:{$_REQUEST['sessionTab']}");
    } else {
        $DB->set_trans("R");
        $response["err"] = "debugTargetError";
        @stream_socket_shutdown($server, STREAM_SHUT_RDWR);
        @stream_socket_shutdown($gui, STREAM_SHUT_RDWR);
        debugLog("ERRO ao registrar target na BD");
    }
}

// Session not started for some reason
if ($response["err"]) {
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($response);
    debugLog("Sessão terminada com erro: {$response['err']}");
    exit(0);
}

// Respond to frontend
ob_start();
echo json_encode($response);
header("Content-Type: application/json");
header("Content-Encoding: none");
header("Content-Length: " . ob_get_length());
header("Connection: close");
ob_end_flush();
ob_flush();
flush();
if (is_callable('fastcgi_finish_request'))
    fastcgi_finish_request();

$initCmds = array("feature_set -n max_children -v 1000", "feature_set -n max_depth -v 10", "feature_set -n max_data -v 1024000");
$initBreaks = array();
if ($_REQUEST["breaks"]) {
    foreach (explode(";", $_REQUEST["breaks"]) as $initBreak)
        $initBreaks[] = explode(",", $initBreak);
    debugLog("InitBreaks configurados: " . count($initBreaks) . " breakpoints");
}
$status = "WAIT";
$lastKey = intval(microtime(true));

// Infinite loop, with wait for XDebug / frontend (interrupted by "STOP" or missing "KEY" commands)
debugLog("Entrando no loop principal. Server: $debugHost:$debugPortX, GUI: $debugPortG");

while (true) {
    $connection = @stream_socket_accept($server, 30, $clientAddress);
    if ($clientAddress) {
        debugLog(">>> Conexão recebida de: $clientAddress");

        $buffer = "";
        $charCount = 0;
        while (false !== ($char = fgetc($connection))) {
            $charCount++;

            // Read until NULL and acumulate in $buffer
            if ($char == "\x00") {
                debugLog("NULL recebido. Chars lidos: $charCount, Buffer size: " . strlen($buffer) . " bytes");
                debugLog("Buffer primeiros 200 chars: " . substr($buffer, 0, 200));

                // XML: comunication from XDebug
                if (substr($buffer, 0, 5) == "<?xml") {
                    debugLog("*** XML DETECTADO do XDebug ***");

                    $parser = xml_parser_create();
                    xml_parse_into_struct($parser, $buffer, $vals);
                    xml_parser_free($parser);

                    debugLog("XML parsed. Tag principal: [{$vals[0]['tag']}], Attributes: " . json_encode($vals[0]['attributes']));

                    // INIT: start session, set features + breakpoints and then wait for frontend
                    if ($vals[0]["tag"] == "INIT") {
                        debugLog("*** INIT recebido do XDebug ***");
                        $cmd = $cmdGUI = "";
                        $tid = 1;
                        $init = $initCmds;
                        if ($initBreaks) {
                            foreach ($initBreaks as $break) {
                                $init[] = "breakpoint_set -t line -f " . $break[0] . " -n " . $break[1];
                                debugLog("Breakpoint a configurar: {$break[0]}:{$break[1]}");
                            }
                        }
                        $initSeq = 0;
                        $breaks = array();
                        $status = "INIT";
                        debugLog("Status mudou para INIT. Init commands: " . count($init));
                    }
                    // Response to breakpoint set
                    elseif ($vals[0]["attributes"]["COMMAND"] == "breakpoint_set") {
                        $cmdParts = explode(" ", $cmd);
                        $breaks[strtolower($cmdParts[4]) . "_" . $cmdParts[6]] = $vals[0]["attributes"]["ID"];
                        debugLog("Breakpoint confirmado: ID={$vals[0]['attributes']['ID']}, File={$cmdParts[4]}, Line={$cmdParts[6]}");
                    }
                    // Response to general breakpoint remove, proceed until all are removed
                    elseif ($vals[0]["attributes"]["COMMAND"] == "breakpoint_remove" && $cmdGUI == "BREAK remove ") {
                        if ($breaks) {
                            $cmd = "breakpoint_remove -d " . array_shift($breaks);
                            debugLog("Removendo próximo breakpoint");
                        } else {
                            $cmdGUI = "";
                            debugLog("Todos breakpoints removidos");
                        }
                    }

                    // Main cycle
                    if (($status == "INIT" || $status == "RUN") && $cmdGUI != "BREAK remove ") {
                        debugLog("Main cycle: status=$status, cmdGUI=$cmdGUI");

                        // INIT phase, execute all commands before wait for frontend
                        if ($init[$initSeq]) {
                            $cmd = $init[$initSeq];
                            debugLog("Executando init command [$initSeq]: $cmd");
                            $initSeq++;
                        } else {
                            debugLog("Init phase completa. Aguardando frontend...");

                            // Respond to frontend, if latest command came from there
                            $responseGUI = "";
                            if ($cmdGUI) {
                                debugLog("Processando resposta para cmdGUI: $cmdGUI");

                                // Response from XDebug to call stack get, issued because breakpoint was reached
                                // this is where response to GUI is sent with status break + all call stack
                                if ($vals[0]["attributes"]["COMMAND"] == "stack_get") {
                                    $allStack = array();
                                    for ($i = 1; $vals[$i] && $vals[$i]["attributes"]["FILENAME"]; $i++) {
                                        $allStack[] = array(
                                            "file" => parse_url($vals[$i]["attributes"]["FILENAME"], PHP_URL_PATH),
                                            "line" => $vals[$i]["attributes"]["LINENO"],
                                            "where" => $vals[$i]["attributes"]["WHERE"]
                                        );
                                    }
                                    $responseGUI = "break " . json_encode($allStack);
                                    debugLog("Stack obtido. Níveis: " . count($allStack) . ". Response: " . substr($responseGUI, 0, 200));
                                }
                                // EVAL: expresssion evaluation, GETVAR: variable output
                                elseif (substr($cmdGUI, 0, 4) == "EVAL" || substr($cmdGUI, 0, 6) == "GETVAR") {
                                    if ($vals[1]["tag"] == "PROPERTY") {
                                        $responseGUI = $vals[1]["value"] && $vals[1]["attributes"]["ENCODING"] == "base64" ? $vals[1]["value"] : "%NONE%";
                                        debugLog("EVAL/GETVAR resultado: " . ($responseGUI == "%NONE%" ? "VAZIO" : "BASE64 " . strlen($responseGUI) . " chars"));
                                    } else {
                                        $responseGUI = "ERRMSG" . $vals[2]["value"];
                                        debugLog("EVAL/GETVAR erro: {$vals[2]['value']}");
                                    }
                                }
                                // Continuation or stop commands:
                                // 1. break reached, get stack and respond to gui afterwards
                                // 2. stop or end of script, place GUI in wait state
                                elseif ($vals[0]["attributes"]["STATUS"]) {
                                    $xdebugStatus = $vals[0]["attributes"]["STATUS"];
                                    debugLog("XDebug STATUS recebido: $xdebugStatus");

                                    if ($xdebugStatus == "break") {
                                        if ($cmdGUI == "START") {
                                            $responseGUI = "break";
                                            debugLog("BREAK no START - respondendo 'break' ao GUI");
                                        } else {
                                            $cmd = "stack_get";
                                            debugLog("BREAK - solicitando stack_get");
                                        }
                                    } elseif ($xdebugStatus && in_array($xdebugStatus, array("stopping", "stopped"))) {
                                        $responseGUI = "wait";
                                        $cmd = "run";
                                        $status = "WAIT";
                                        debugLog("XDebug stopped - voltando para WAIT");
                                    } else {
                                        $responseGUI = "?";
                                        debugLog("STATUS desconhecido: $xdebugStatus");
                                    }
                                } else {
                                    $responseGUI = "?";
                                    debugLog("Resposta não reconhecida do XDebug");
                                }

                                if ($responseGUI) {
                                    if ($responseGUI == "%NONE%")
                                        $responseGUI = "";
                                    debugLog("Enviando para GUI: " . substr($responseGUI, 0, 100));
                                    fwrite($connectionGUI, $responseGUI . "\x00");
                                    fclose($connectionGUI);
                                    $cmdGUI = "";
                                }
                            }

                            // Wait for next frontend command
                            if (!$cmdGUI && $responseGUI != "wait") {
                                $cmd = "";
                                debugLog("Aguardando comando do GUI...");

                                while (true) {
                                    $connectionGUI = @stream_socket_accept($gui, 30, $guiAddress);
                                    if ($guiAddress) {
                                        debugLog("GUI conectado de: $guiAddress");

                                        $cmdGUI = "";
                                        while (false !== ($char = fgetc($connectionGUI))) {
                                            if ($char == "\x00")
                                                break;
                                            else
                                                $cmdGUI .= $char;
                                        }

                                        debugLog("Comando recebido do GUI: $cmdGUI");

                                        if ($cmdGUI == "START") {
                                            if ($status == "INIT") {
                                                $cmd = "run";
                                                $status = "RUN";
                                                debugLog("START recebido em INIT - mudando para RUN");
                                            } else {
                                                fwrite($connectionGUI, $responseGUI . "\x00");
                                                fclose($connectionGUI);
                                                debugLog("START recebido fora de INIT - reenviando response");
                                            }
                                        } elseif (substr($cmdGUI, 0, 5) == "BREAK") {
                                            $updString = substr($cmdGUI, 6);
                                            $initBreaks = updInitBreaks($initBreaks, $updString);
                                            $updBreak = explode(" ", $updString);

                                            if ($updBreak[0] == "add") {
                                                $cmd = "breakpoint_set -t line -f " . $updBreak[1] . " -n " . $updBreak[2];
                                                debugLog("BREAK add: {$updBreak[1]}:{$updBreak[2]}");
                                            } elseif ($updBreak[0] == "remove" && $breaks) {
                                                if ($updBreak[1]) {
                                                    $breakId = $breaks[strtolower($updBreak[1]) . "_" . $updBreak[2]];
                                                    unset($breaks[strtolower($updBreak[1]) . "_" . $updBreak[2]]);
                                                    debugLog("BREAK remove específico: ID=$breakId");
                                                } else {
                                                    $breakId = array_shift($breaks);
                                                    debugLog("BREAK remove primeiro: ID=$breakId");
                                                }
                                                $cmd = "breakpoint_remove -d " . $breakId;
                                            } else
                                                fclose($connectionGUI);
                                        } elseif (substr($cmdGUI, 0, 4) == "EVAL") {
                                            $cmd = "eval";
                                            $cmdData = base64_encode("eval(\"" . str_replace("\$", "\\\$", addslashes(substr($cmdGUI, 5))) . (substr($cmdGUI, -1) === ";" ? "" : ";") . "\")");
                                            debugLog("EVAL: " . substr($cmdGUI, 5, 50));
                                        } elseif (substr($cmdGUI, 0, 6) == "GETVAR") {
                                            $cmd = "eval";
                                            $cmdData = base64_encode("eval(\"return print_r(" . str_replace("\$", "\\\$", addslashes(substr($cmdGUI, 7))) . ", true);\")");
                                            debugLog("GETVAR: " . substr($cmdGUI, 7, 50));
                                        } elseif (substr($cmdGUI, 0, 3) == "KEY") {
                                            if (substr($cmdGUI, 4) == $sessionKey) {
                                                $lastKey = intval(microtime(true));
                                                fwrite($connectionGUI, "OK\x00");
                                                debugLog("KEY válido recebido");
                                            } else {
                                                fwrite($connectionGUI, "\x00");
                                                debugLog("KEY inválido recebido");
                                            }
                                            fclose($connectionGUI);
                                        } else {
                                            $cmd = $cmdGUI;
                                            debugLog("Comando direto: $cmd");
                                        }
                                    }
                                    if ($cmd || $lastKey < (intval(microtime(true)) - 60))
                                        break;
                                }

                                if (!$cmd) {
                                    $cmd = "stop";
                                    debugLog("Timeout - enviando STOP");
                                }
                            }
                        }
                    }

                    // Send command to XDebug (if stop is issued, exit program)
                    $cmdToSend = $cmd . " -i " . $tid++ . ($cmdData ? " -- " . $cmdData : "");
                    debugLog(">>> Enviando para XDebug: $cmdToSend");
                    fwrite($connection, $cmdToSend . "\x00");

                    if ($cmd == "stop") {
                        if ($cmdGUI == "stop") {
                            fwrite($connectionGUI, "\x00");
                            fclose($connectionGUI);
                        }
                        debugLog("STOP enviado - encerrando loop");
                        break 2;
                    } else
                        $lastKey = intval(microtime(true));
                    if ($cmdData)
                        $cmdData = "";

                }
                // Communication not from XDebug (stop service, breakpoint updates outside session, get session key)
                else {
                    debugLog("Mensagem não-XML recebida: " . substr($buffer, 0, 50));
                    if ($buffer == "STOP") {
                        $clientIP = explode(":", $clientAddress)[0];
                        if ($clientIP == $debugHost) {
                            debugLog("STOP recebido do HOST LOCAL - encerrando");
                            break 2;
                        } else {
                            debugLog("STOP recebido de IP não-autorizado: $clientIP - ignorando");
                            fwrite($connection, "IGNORED\x00");
                            fclose($connection);
                            continue;
                        }
                    } elseif (substr($buffer, 0, 5) == "BREAK") {
                        $initBreaks = updInitBreaks($initBreaks, substr($buffer, 6));
                        $lastKey = intval(microtime(true));
                        fwrite($connection, "\x00");
                        debugLog("BREAK update externo: " . substr($buffer, 6));
                        break;
                    } elseif (substr($buffer, 0, 3) == "KEY") {
                        if (substr($buffer, 4) == $sessionKey) {
                            $lastKey = intval(microtime(true));
                            fwrite($connection, "OK\x00");
                            debugLog("KEY check OK");
                        } else {
                            fwrite($connection, "\x00");
                            debugLog("KEY check FALHOU");
                        }
                        break;
                    }
                }

                $buffer = "";
            } else
                $buffer .= $char;
        }

        debugLog("<<< Conexão fechada");
        fclose($connection);
    }

    if ($lastKey < (intval(microtime(true)) - 60)) {
        debugLog("Timeout de 60s atingido - encerrando");
        break;
    }
}

debugLog("Limpando sockets e target");
@stream_socket_shutdown($gui, STREAM_SHUT_RDWR);
@stream_socket_shutdown($server, STREAM_SHUT_RDWR);

updateTarget($_REQUEST["sessionId"], $_REQUEST["sessionTab"], $debugHost . ":" . $debugPortX . ":" . $sessionKey, true);

debugLog("=== DEBUG SESSION END ===");
?>