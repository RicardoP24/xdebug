<?php

$REQUEST_METHOD = $_SERVER["REQUEST_METHOD"];
if ($REQUEST_METHOD == "POST")
	$brVars = $_POST;
elseif ($REQUEST_METHOD == "GET")
	$brVars = $_GET;

if (!isset($ENVN))
	$ENVN = $brVars["ENVN"];
if (!isset($SESS))
	$SESS = $brVars["SESS"];
if (!isset($NEXT))
	$NEXT = $brVars["NEXT"];
if (!isset($SSID))
	$SSID = $brVars["SSID"];

extract(array_merge($_POST, $_GET, $_COOKIE));
if (empty($UTL))
	ob_start("ob_gzhandler");
if (empty($PPWD))
	$PPWD = FALSE;
if (empty($NPWD))
	$NPWD = FALSE;

include_once("config.inc");
if (empty($locDirInc))
	$locDirInc = $locDirBase . $locEnvDir[$ENVN];

//$NOMODIF = TRUE;

// Security
function user_login($username, $md5pwd, $clrpwd, $UTL, $WEB)
{
	global $SSID;
	$usrPar = array();
	$usrPar["USRN"][0]["val1"] = $username;
	if ($WEB)
		$usrPar["PWDT"][0]["val1"] = "W";
	elseif (!$UTL)
		$usrPar["PWDT"][0]["val1"] = "L";
	else
		$usrPar["PWDT"][0]["val1"] = "B";
	$pwdRules = exec_sql("I0029", "read", $usrPar);
	setG("date", date("YmdHis"));
	if (!($userData = exec_sql("I0002", "read_I0002V02", $usrPar))) {
		login_log($pwdRules[0], $username, $userData[0], false);
		send_msg("SY", "SEC", "2");
		return false;
	}
	if ($userData[0]["Utente_lock"]) {
		login_log($pwdRules[0], $username, $userData[0], false);
		send_msg("SY", "SEC", "5");
		return false;
	}
	if (!$userData[0]["Utente_gruppo"]) {
		login_log($pwdRules[0], $username, $userData[0], false);
		send_msg("SY", "SEC", "8", array("p1" => $username));
		return false;
	}
	global $pwdData;
	if (!$pwdData = exec_sql("I0003", "read", $usrPar))
		$pwd = md5("");
	elseif ($pwdData[0]["Password"])
		$pwd = $pwdData[0]["Password"];
	else
		$pwd = md5("");
	if (!$md5pwd) {
		if (md5($clrpwd) != $pwd) {
			login_log($pwdRules[0], $username, $userData[0], false);
			send_msg("SY", "SEC", "2");
			return false;
		}
	} elseif (($WEB && md5($pwd . $WEB) != $md5pwd) || (!$WEB && md5($pwd . $SSID) != $md5pwd)) {
		login_log($pwdRules[0], $username, $userData[0], false);
		send_msg("SY", "SEC", "2");
		return false;
	}
	login_log($pwdRules[0], $username, $userData[0], true);
	if (!$pwdData || !$pwdData[0]["Password"])
		$userData[0]["nopass"] = true;
	elseif (!$userData[0]["Password_no_rule"] && $pwdRules[0]["Password_scadenza"]) {
		$now = mktime(0, 0, 0, date("m"), date("d"), date("Y"));
		$lastPwd = mktime(0, 0, 0, substr($pwdData[0]["Std_datamod"], 4, 2), substr($pwdData[0]["Std_datamod"], 6, 2), substr($pwdData[0]["Std_datamod"], 0, 4));
		if ((($now - $lastPwd) / 86400) > $pwdRules[0]["Password_scadenza"])
			$userData[0]["nopass"] = true;
	}
	if ($userData[0]["Installazione"])
		setG("locInstalation", $userData[0]["Installazione"]);
	return $userData[0];
}
function login_log($pwdRules, $username, $userData, $suc)
{
	if ($pwdRules) {
		if (($suc && $pwdRules["Login_registo_suc"]) || (!$suc && $pwdRules["Login_registo_unsuc"])) if ($fw = @fopen(getG("locDirBase") . getG("locDirLogs") . "login.log", "a")) {
			$text = "[" . date("Y-m-d H:i:s") . "] [client " . getG("remIP") . "] [user " . $username . "] ";
			if ($suc)
				$text .= "OK";
			else
				$text .= "ERR";
		}
		if ($userData && !$suc) {
			$updUser["fld"]["I0044"][0]["Utente"] = $username;
			$updUser["fld"]["I0044"][0]["Utente_login_err"] = $userData["Utente_login_err"] + 1;
			if ($pwdRules["Login_err_max"] && $updUser["fld"]["I0044"][0]["Utente_login_err"] >= $pwdRules["Login_err_max"]) {
				$updUser["fld"]["I0044"][0]["Utente_lock"] = "1";
				if ($fw)
					$text .= " User locked";
			}
			$updUser["fld"]["I0044"][0]["Std_utente"] = $username;
			exec_sql("I0044", "modify", $updUser);
		}
		if ($fw) {
			@fwrite($fw, $text . "\n");
			@fclose($fw);
		}
	}
	if ($userData && $suc && $userData["Utente_login_err"]) {
		$updUser["fld"]["I0044"][0]["Utente"] = $username;
		$updUser["fld"]["I0044"][0]["Utente_login_err"] = "0";
		exec_sql("I0044", "modify", $updUser);
	}
}
function set_auth($user, $group)
{
	$allAuth = array();
	$autA = array();
	if ($group) if (!$allAuth = exec_sql("I0021", "read", array("USRG" => array(array("val1" => $group)))))
		$allAuth = array();
	if ($allUser = exec_sql("I0016", "read", array("USRN" => array(array("val1" => $user)))))
		$allAuth = array_merge($allAuth, $allUser);
	if (!$allAuth)
		setG("autA", array("area" => "NONE"));
	else {
		$lin = 0;
		foreach ($allAuth as $linAuth) {
			if (!$linAuth["Parametro_gruppo"]) {
				if ($linAuth["Parametro_segno"] == "1")
					$autA[$lin]["sig"] = $linAuth["Autorizzazione_tipo"];
				else
					$autA[$lin]["sig"] = false;
				if ($linAuth["Area_applicativa"])
					$autA[$lin]["area"] = $linAuth["Area_applicativa"];
				if ($linAuth["Processo_applicativo"])
					$autA[$lin]["proc"] = $linAuth["Processo_applicativo"];
				if ($linAuth["Autorizzazione_grupo"])
					$autA[$lin]["grup"] = $linAuth["Autorizzazione_grupo"];
				if ($linAuth["Azione_codice"])
					$autA[$lin]["act"] = $linAuth["Azione_codice"];
				$lin++;
			} else {
				if ($linAuth["Parametro_segno"] == "1")
					$autP[$linAuth["Parametro_gruppo"]]["I"][] = $linAuth["Valore_1"];
				else
					$autP[$linAuth["Parametro_gruppo"]]["E"][] = $linAuth["Valore_1"];
			}
		}
		setG("autA", array_reverse($autA, true));
		if ($autP)
			setG("autP", $autP);
	}
}
function check_auth_ac($area, $proc, $grup, $act)
{
	$autA = getG("autA");
	$act = strval($act);
	if (!$autA || ($area == "SY" && $proc == "SYS" && $grup == "SYS") || $autA["area"] == "NONE")
		return "2";
	else
		foreach ($autA as $linA) {
			if ((!isset($linA["act"]) || $linA["act"] == $act) && (!isset($linA["grup"]) || $linA["grup"] == $grup) && (!isset($linA["proc"]) || $linA["proc"] == $proc) && (!isset($linA["area"]) || $linA["area"] == $area))
				return $linA["sig"];
		}
	return true;
}

function check_auth_acPdt($area, $proc, $grup, $act)
{
	$autA = getG("autA");
	$act = strval($act);
	if (!$autA || $autA["area"] == "NONE")
		return true;
	else
		foreach ($autA as $linA) {
			if ((!isset($linA["act"]) || $linA["act"] == $act) && (!isset($linA["grup"]) || $linA["grup"] == $grup) && (!isset($linA["proc"]) || $linA["proc"] == $proc) && (!isset($linA["area"]) || $linA["area"] == $area)) {
				//			if (isset($linA["sig"])) return true;
				if (is_array($linA)) if ($linA["sig"])
					return true;
				else
					return false;
			}
		}
	return true;
}

// Session support
function destroy_sess($id, $num, $user = false)
{
	rlsLocks($id, $num);
	$sesPar = array();
	$sesPar["fld"]["I0011"][0]["Sessione_id"] = $id;
	$sesPar["fld"]["I0011"][0]["Sessione_numero"] = $num;
	exec_sql("I0011", "erase", $sesPar);
	$sesPar["fld"]["I0015"][0]["Sessione_id"] = $id;
	$sesPar["fld"]["I0015"][0]["Sessione_numero"] = $num;
	exec_sql("I0015", "erase", $sesPar);
	$sesPar["fld"]["I0028"][0]["Sessione_id"] = $id;
	$sesPar["fld"]["I0028"][0]["Sessione_numero"] = $num;
	exec_sql("I0028", "erase", $sesPar);
	global $DB;
	$DB->set_trans("C");
	$dir = getG("locDirBase") . getG("locDirSessions") . $id . $num;
	if (file_exists($dir) && $d = dir($dir)) {
		while ($entry = $d->read())
			if ($entry != "." && $entry != "..")
				@unlink($dir . "/" . $entry);
		$d->close();
		$d = dir(getG("locDirBase"));
		$d->close();
		@rmdir($dir);
	}
}
function destroy_all_sess($SessionID)
{
	$sesPar = array();
	$sesPar["SSID"][0]["val1"] = $SessionID;
	if ($data = exec_sql("I0011", "read", $sesPar))
		foreach ($data as $rec) {
			destroy_sess($rec["Sessione_id"], $rec["Sessione_numero"]);
			if ($rec["Modificazione"])
				$chkMod[$rec["Modificazione"]] = true;
		}
	if (!empty($chkMod))
		chkStatusModif($chkMod);
	return true;
}
function wpms_session_open($save_path, $session_name)
{
	return true;
}
function wpms_session_close()
{
	return true;
}

function wpms_session_read($SessionID)
{
	global $SSID;
	global $SESS;
	global $saveGlobals;
	global $EVE;

	$SSID = $SessionID;
	if ($SESS == "")
		return "";
	if (!$_SESSION)
		$_SESSION = $saveGlobals;

	$sesPar = array();
	$sesPar["SSID"][0]["val1"] = $SSID;
	$sesPar["SNUM"][0]["val1"] = $SESS;

	if ($data = exec_sql("I0011", "read", $sesPar)) {
		if ($data[0]["Messagio_sys"]) {
			global $sysMsg;
			$sysMsg = true;
		}

		global $MODI;
		if ($data[0]["Modificazione"])
			$MODI = $data[0]["Modificazione"];

		$_SESSION = igbinary_unserialize($data[0]["Sessione_dati"]);

		// ✅ MANTENDO A ESTRUTURA MAS CORRIGINDO PARA MÚLTIPLOS USERS
		if ($_SESSION["xdebug"]) {
			$debugURL = explode(":", $_SESSION["xdebug"]);
			error_log("[" . date('Y-m-d H:i:s') . "][SSID:$SSID][SESS:$SESS] wpms_session_read: xdebug={$_SESSION['xdebug']}", 3, "/www/debugSession.log");

			// ✅ SOLUÇÃO: Configurar APENAS para ESTE request via output buffering
			// XDebug 3 permite ini_set() DURANTE o script!
			ini_set('xdebug.client_host', $debugURL[0]);
			ini_set('xdebug.client_port', $debugURL[1]);
			ini_set('xdebug.idekey', $debugURL[2]);
			ini_set('xdebug.mode', 'debug');
			ini_set('xdebug.start_with_request', 'yes'); // Forçar ativação

			// ✅ Também setar cookie para trigger mode
			setcookie('XDEBUG_SESSION', $debugURL[2], 0, '/');
			$_COOKIE['XDEBUG_SESSION'] = $debugURL[2];

			// ✅ Tentar conectar (manter tua lógica)
			error_log("[" . date('Y-m-d H:i:s') . "][SSID:$SSID][SESS:$SESS] Conectando ao XDebug...", 3, "/www/debugSession.log");

			if (@xdebug_connect_to_client($debugURL[0], (int) $debugURL[1])) {
				error_log("[" . date('Y-m-d H:i:s') . "][SSID:$SSID][SESS:$SESS] XDebug conectado! Chamando xdebug_break()", 3, "/www/debugSession.log");
				xdebug_break();
				error_log("[" . date('Y-m-d H:i:s') . "][SSID:$SSID][SESS:$SESS] xdebug_break() executado", 3, "/www/debugSession.log");
			} else {
				error_log("[" . date('Y-m-d H:i:s') . "][SSID:$SSID][SESS:$SESS] xdebug_connect_to_client FALHOU", 3, "/www/debugSession.log");
			}
		}
	} elseif (!$EVE || $SESS != "0") {
		$sesPar["SNUM"][0]["val1"] = "0";
		if ($data = exec_sql("I0011", "read", $sesPar)) {
			$_SESSION = igbinary_unserialize($data[0]["Sessione_dati"]);
		} else {
			def_output("SY", "GEN", "8");
			setG("leaveSessionAlone", true);
			return "";
		}
	}

	if (PHP_MAJOR_VERSION >= 8)
		return serialize($_SESSION);
}

function wpms_session_write($SessionID, $val)
{
	return true;
}
function wpms_session_destroy($SessionID)
{
	return true;
}
function wpms_session_gc($maxlifetime = 300)
{
	return true;
}

// Class loader
function load_class($class, $instance = "", $par = "")
{
	global $loaded_classes;
	global $loaded_instances;
	if (empty($loaded_classes[$class])) {
		$dirClasses = getG("locDirBase") . getG("locDirClasses");
		if (include($dirClasses . $class . ".cla"))
			$loaded_classes[$class] = true;
		else {
			send_msg("SY", "GEN", "40", array("p1" => $class));
			return false;
		}
	}
	if ($instance != "" && empty($loaded_instances[$class][$instance])) {
		global ${$instance};
		if (is_array($par))
			${$instance} = new $class($par);
		else
			${$instance} = new $class;
		$loaded_instances[$class][$instance] = true;
	}
	return true;
}
function load_class_GN($class, $instance)
{
	global $locDirInc;
	global $loaded_gn_classes;
	if (empty($loaded_gn_classes[$class])) {
		if (!include_once($locDirInc . $class . ".cla")) {
			if ($class == "GnMsg")
				abort("Error loading messages");
			else
				send_msg("SY", "GEN", "40", array("p1" => $class));
			return false;
		} else {
			$loaded_gn_classes[$class] = true;
			global ${$instance};
			${$instance} = new $class;
			return true;
		}
	} elseif ($class == "GnReport" || $class == "GnReportLocal") { // ate ter a variavel classname em todas as classes ... o dia que temos incluimos no if do empty
		if (${$instance}->classname == $class)
			return true;
		else {
			if (!include_once($locDirInc . $class . ".cla")) {
				if ($class == "GnMsg")
					abort("Error loading messages");
				else
					send_msg("SY", "GEN", "40", array("p1" => $class));
				return false;
			} else {
				$loaded_gn_classes[$class] = true;
				global ${$instance};
				${$instance} = new $class;
				return true;
			}
		}
	} else
		return true;
}

// Global values
function setG($var, $val)
{
	$_SESSION[$var] = $val;
}
function getG($var)
{
	if (isset($_SESSION[$var]))
		return $_SESSION[$var];
}
function umD($um)
{
	return $_SESSION["allUm"][$um];
}

// Database and local definitions
function exec_sql($class, $method, $par = "")
{
	global ${$class};
	global $UTL;
	global $RFS;
	load_class($class, $class);
	global $sqlStack;
	$s = isr_count($sqlStack);
	$sqlStack[$s] = array("class" => $class, "method" => $method);
	if (!$UTL && !$RFS)
		ob_start("getBufferSql");
	if (getG("debug")) {
		$perfSQL = getG("perfSQL");
		$n = isr_count($perfSQL);
		$perfSQL[$n] = $sqlStack[$s];
		setG("perfSQL", $perfSQL);
		$startTime = microtime(true);
	}
	if (getG("debugLog") && ((!getG("debugObject") || (getG("debugObject") == $class && getG("debugMethod") == $method))))
		setG("strDbgLog", true);
	elseif (getG("strDbgLog"))
		setG("strDbgLog", false);
	if ($par == "")
		$ret = ${$class}->{$method}();
	else
		$ret = ${$class}->{$method}($par);
	if (getG("strDbgLog") && getG("debugObject") && getG("debugObject") == $class && getG("debugMethod") == $method)
		setG("strDbgLog", false);
	if (!$UTL && !$RFS)
		ob_end_flush();
	if (getG("debug")) {
		$perfSQL = getG("perfSQL");
		if ($sqlStack[$s]["sql"])
			$perfSQL[$n]["sql"] = $sqlStack[$s]["sql"];
		if ($sqlStack[$s]["inf"])
			$perfSQL[$n]["inf"] = $sqlStack[$s]["inf"];
		if ($perfSQL[$n + 1]) {
			$n = isr_count($perfSQL);
			$perfSQL[$n] = array("end" => true);
		}
		$perfSQL[$n]["time"] = microtime(true) - $startTime;
		setG("perfSQL", $perfSQL);
	} elseif (isr_count($sqlStack[$s]) > 2) {
		$perfSQL = getG("perfSQL");
		$n = isr_count($perfSQL);
		$perfSQL[$n] = $sqlStack[$s];
		$perfSQL[$n]["time"] = "0.0000";
		if ($sqlStack[$s]["inf"])
			$perfSQL[$n]["inf"] = $sqlStack[$s]["inf"];
		setG("perfSQL", $perfSQL);
	}
	unset($sqlStack[$s]);

	// Write table logs if they exist and if there is no error
	global $err;
	if ($fillW0076 = getG("W0076")) {
		setG("W0076", "");
		if (!$err)
			exec_sql("W0076", "write", $fillW0076);
	}
	return $ret ?? [];
}
// Call object->method: usefull when parameters should be changed. Simpler than exec_sql because not used for DB access
function call_method($class, $method, &$par = "")
{
	global ${$class};
	global $UTL;
	global $RFS;
	load_class($class, $class);
	global $sqlStack;
	$s = isr_count($sqlStack);
	$sqlStack[$s] = array("class" => $class, "method" => $method);
	if (!$UTL && !$RFS)
		ob_start("getBufferSql");
	//This is necessary to catch the action methods in the Trace
	if (getG("debug")) {
		$perfSQL = getG("perfSQL");
		$n = isr_count($perfSQL);
		$perfSQL[$n] = $sqlStack[$s];
		setG("perfSQL", $perfSQL);
		$startTime = microtime(true);
	}
	if ($par == "")
		$ret = ${$class}->{$method}();
	else
		$ret = ${$class}->{$method}($par);
	if (!$UTL && !$RFS)
		ob_end_flush();
	if (getG("debug")) {
		$perfSQL = getG("perfSQL");
		if ($sqlStack[$s]["sql"])
			$perfSQL[$n]["sql"] = $sqlStack[$s]["sql"];
		if ($sqlStack[$s]["inf"])
			$perfSQL[$n]["inf"] = $sqlStack[$s]["inf"];
		if ($perfSQL[$n + 1]) {
			$n = isr_count($perfSQL);
			$perfSQL[$n] = array("end" => true);
		}
		$perfSQL[$n]["time"] = microtime(true) - $startTime;
		setG("perfSQL", $perfSQL);
	} elseif (isr_count($sqlStack[$s]) > 2) {
		$perfSQL = getG("perfSQL");
		$n = isr_count($perfSQL);
		$perfSQL[$n] = $sqlStack[$s];
		$perfSQL[$n]["time"] = "0.0000";
		if ($sqlStack[$s]["inf"])
			$perfSQL[$n]["inf"] = $sqlStack[$s]["inf"];
		setG("perfSQL", $perfSQL);
	}
	unset($sqlStack[$s]);
	return $ret;
}
function get_line($ID)
{
	global $DB;
	return $DB->next_row($ID);
}
function getBufferSql($buffer)
{
	if ($buffer != "") {
		global $sqlStack;
		$s = isr_count($sqlStack) - 1;
		$sqlStack[$s]["inf"][] = $buffer;
		if (strstr($buffer, " error</b>:"))
			return $buffer;
	}
}
function send_msg($area, $process, $code, $par = "", $wf = FALSE, $pwf = array())
{
	global $MSG;
	global $err;
	global $catchErr;
	global $repMessages;
	global $log;
	global $wfEvents;
	global $EVE;
	global $RFS;
	if (!$log && getG("messagesOnLog") && $area . $process . $code != "INADM7")
		$log = "I";
	$MSG->getMsg($area, $process, $code, $par);
	if ($catchErr)
		$catchErr = $MSG->msgText;
	else {
		if ($MSG->msgType != "F" && $log && (!$EVE || $EVE && $RFS) && ($log == "B" || ($log == "I" && ($MSG->msgType == "I" || $MSG->msgType == "T")))) {
			global $allLog;
			$allLog[] = array("area" => $area, "proc" => $process, "code" => $code, "par" => $par);
		} elseif ($MSG->msgType != "F") {
			if ($repMessages)
				$next = isr_count($repMessages);
			else
				$next = 0;
			$repMessages[$next]["type"] = $MSG->msgType;
			$repMessages[$next]["text"] = $MSG->msgText;
			$repMessages[$next]["code"] = $MSG->msgArea . "-" . $MSG->msgProcess . "-" . $MSG->msgCode;
			$repMessages[$next]["pars"] = $par;
		}
		if ($MSG->msgType == "C" || $MSG->msgType == "E" || $MSG->msgType == "Q")
			$err = true;
		if ($MSG->workFlow && !$wf) if ($callStack = debug_backtrace()) if ($nmFnct = pathinfo($callStack[0]["file"])) {
			$arFnct = explode("_", $nmFnct["filename"]);
			$wf["obj"] = substr($arFnct[0], 0, 20);
			if (!empty($arFnct[1]))
				$wf["met"] = substr($arFnct[1], 0, 40);
			$wf["srcL"] = $callStack[0]["line"];
		}
		if ($MSG->workFlow && $wf && $_SESSION["allWrkMsg"][$area . $process . $code]) {
			$next = isr_count($wfEvents);
			$wfEvents[$next] = array("area" => $area, "process" => $process, "code" => $code, "par" => $par, "wf" => $wf);
			$parM = array(); // mandatory parameters
			$parO = array(); // optionals parameters
			if (isr_count($MSG->parM))
				foreach ($MSG->parM as $namPar)
					$parM[$namPar] = $namPar;
			if (isr_count($MSG->parO))
				foreach ($MSG->parO as $namPar)
					$parO[$namPar] = $namPar;
			if (isr_count($pwf))
				foreach ($pwf as $parName => $parValue) {
					if ($parM[$parName] && $parValue) {
						unset($parM[$parName]);
						$wfEvents[$next]["pwf"][$parName] = $parValue;
					} elseif ($parO[$parName] && $parValue)
						$wfEvents[$next]["pwf"][$parName] = $parValue;
				}
			if (isr_count($parM))
				unset($wfEvents[$next]);
			if ($wfEvents[$next] && empty($wfEvents[$next]["pwf"])) {
				$wfEvents[$next]["pwf"]["USRN"] = getG("USRN");
				$wfEvents[$next]["pwf"]["USRG"] = getG("USRG");
			}
		}
	}
}
function get_msg($area, $process, $code, $par = "")
{
	global $MSG;
	$MSG->getMsg($area, $process, $code, $par);
	return $MSG->msgText;
}
function send_java_msg($area, $process, $code, $par = "")
{
	global $MSG;
	$MSG->getMsg($area, $process, $code, $par);
	$javaPar = ",'" . $area . "-" . $process . "-" . $code . "'";
	if (!empty($par["j1"])) {
		$javaPar .= "," . $par["j1"];
		if ($par["j2"] != "") {
			$javaPar .= "," . $par["j2"];
			if ($par["j3"] != "") {
				$javaPar .= "," . $par["j3"];
				if ($par["j4"] != "") {
					$javaPar .= "," . $par["j4"];
					if ($par["j5"] != "")
						$javaPar .= "," . $par["j5"];
				}
			}
		}
	}
	$text = str_replace(array("<BR>", "<br>"), "\\n", addslashes($MSG->msgText));
	if ($MSG->msgType == "C")
		return "if (!new_confirm('" . $text . "'" . $javaPar . ")) return false";
	if ($MSG->msgType == "E")
		return "new_alert('" . $text . "','E'" . $javaPar . "); return false";
	else
		return "new_alert('" . $text . "','" . $MSG->msgType . "'" . $javaPar . ")";
}
function abort($msg)
{
	global $err;
	global $repMessages;
	$repMessages[0]["text"] = $msg;
	$err = true;
}
function def_output($area = "", $process = "", $code = "", $par = "")
{
	global $SESS;
	global $ENVN;
	global $MSG;
	global $RFS;
	global $UTL;
	global $WEB;
	global $repMessages;
	if ($area && $process && $code) if (load_class_GN("GnMsg", "MSG"))
		$MSG->getMsg($area, $process, $code, $par);
	$msgE = $msgtype = $msgCode = $msgText = FALSE;
	if ($MSG->msgType == "E")
		$msgE = TRUE;
	$msgType = $MSG->msgType;
	$msgCode = $MSG->msgArea . "-" . $MSG->msgProcess . "-" . $MSG->msgCode;
	$msgText = $MSG->msgText;
	if ($WEB) {
		if ($msgE) {
			global $err;
			$err = true;
		}
		$repMessages[] = array("type" => $MSG->msgType, "code" => $msgCode, "text" => $msgText);
		return;
	}
	if (!$msgE && isr_count($repMessages) > 1)
		foreach ($repMessages as $recMessages)
			if ($recMessages["type"] == "E") {
				$msgE = $msgType = "E";
				$msgCode = $recMessages["code"];
				$msgText = $recMessages["text"];
			}
	echo "<HTML><HEAD><TITLE>WPMS - ";
	if ($msgType == "E")
		echo "ERROR $msgCode";
	elseif ($msgType == "Q")
		echo "QUERY $msgCode";
	else
		echo "INFO $msgCode";
	echo "</TITLE>\n";
	if (!$UTL && !$RFS && $SESS && $SESS != "99" && $ENVN)
		echo "<SCRIPT SRC=\"BwFunctions.js\"></SCRIPT><SCRIPT>var session='" . $SESS . "';var envn='" . $ENVN . "';</SCRIPT></HEAD>";
	elseif ($RFS)
		echo "<SCRIPT>function submitForm(action,value){\n\tdocument.post.POSTACTION.value = action\n\tdocument.post.POSTVALUE.value = value\n\tdocument.post.submit()\n}\n</SCRIPT></HEAD>\n";
	$withMessageQ = FALSE;
	if (getG("locUXenv"))
		$locationReplace = $_SERVER["HTTP_HOST"] . getG("locEnvDir") . "loginPdt" . getG("locUXenv") . ".php?&LOGOFF=RF&ENVN=" . $ENVN;
	else
		$locationReplace = $_SERVER["HTTP_HOST"] . "/loginPdt.php?&LOGOFF=RF&ENVN=" . $ENVN;
	if ($msgType == "Q") {
		echo "<BODY BGCOLOR=#FFFFFF>\n";
		$withMessageQ = TRUE;
		echo "<FORM NAME=post ACTION=wpms.php METHOD=GET>\n\t<INPUT TYPE=HIDDEN NAME=MSGQ VALUE='" . str_replace("-", "", $msgCode) . "'>\n\t<INPUT TYPE=HIDDEN NAME=POSTACTION VALUE=16>\n\t<INPUT TYPE=HIDDEN NAME=POSTVALUE VALUE'XX'>\n\t<INPUT TYPE=HIDDEN NAME=ENVN VALUE=" . $ENVN . ">\n\t<INPUT TYPE=HIDDEN NAME=NEXT VALUE=GnMain.php>\n\t<INPUT TYPE=HIDDEN NAME=SESS VALUE=" . $SESS . ">\n\t<INPUT TYPE=HIDDEN NAME=RFS VALUE=1>\n\t<INPUT TYPE=HIDDEN NAME=LASTP VALUE=" . getG("lastPost") . ">\n\t<INPUT TYPE=HIDDEN NAME=TOKEN VALUE=" . getG("token") . "\n\t<INPUT TYPE=BUTTON NAME=BUT VALUE=\"OK\" onClick=\"location.replace('http://" . $locationReplace . "')\">\n</FORM>";
	} else {
		echo "<BODY BGCOLOR=#BBBBBB>";
		if ($msgType == "E")
			echo "<TABLE BGCOLOR=#000000><TR><TD><FONT COLOR=#FFFFFF>";
		echo htmlXspecialchars(stripslashes($msgText)) . "<BR>";
		if ($msgType == "E")
			echo "</FONT></TD></TR></TABLE>";
		if ($msgType == "E" && $msgCode != "SY-GEN-8" && $SESS && $SESS != "99")
			echo get_msg("SY", "GEN", "8") . "<BR>";
		if (!$RFS) {
			echo "<CENTER><INPUT TYPE=BUTTON VALUE=OK onClick=\"";
			if (!$UTL && $SESS && $SESS != "99" && $ENVN)
				echo "logoff()";
			else
				echo "self.close()";
			echo "\"></CENTER>";
		} else {
			echo "<FORM><INPUT TYPE=BUTTON NAME=BUT VALUE=\"OK\" onClick=\"location.replace('http://" . $locationReplace . "')\"></FORM>";
			$withFocus = "BUT";
		}
	}
	echo "</BODY>\n";
	if (!empty($withFocus)) {
		echo "<SCRIPT>\n";
		echo "	document.forms[0]." . $withFocus . ".focus();\n";
		echo "</SCRIPT>\n";
	}
	if ($withMessageQ) {
		$txtAcpVal = "";
		if ($repMessages[0]["pars"]["p4"]) if ($arAcpVal = explode("||", $repMessages[0]["pars"]["p4"]))
			foreach ($arAcpVal as $acpVal)
				$txtAcpVal .= $acpVal . ",";
		echo "<SCRIPT>\nif(askdVl=prompt('" . htmlXspecialchars(stripslashes($msgText)) . " (" . substr($txtAcpVal, 0, -1) . ")" . "','" . $repMessages[0]["pars"]["p5"] . "')) submitForm('16',askdVl)\n</SCRIPT>";
	}
	echo "</HTML>";
	ob_end_flush();
	if ($MSG->msgType == "E")
		$SESS = "";
	exit;
}
function activateLog($batch = false)
{
	global $log;
	global $allLog;
	if (!$batch) {
		global $SSID;
		global $SESS;
		if ($SSID && $SESS)
			$log = "I";
		else
			return;
	} else
		$log = "B";
	if (!$allLog)
		$allLog = array();
}
function endLog($clear = false)
{
	global $log;
	global $allLog;
	if ($log) {
		$log = "E";
		if ($clear)
			$allLog = array();
	}
}
function prgBarStart()
{
	global $PRGBAR;
	if (!$PRGBAR)
		load_class_GN("GnProgressBar", "PRGBAR");
	return $PRGBAR->startPrgBar();
}
function prgBarSetPerc($perc)
{
	global $PRGBAR;
	return $PRGBAR->setPercentage($perc);
}
function prgBarEnd()
{
	global $PRGBAR;
	return $PRGBAR->endPrgBar();
}
function exec_print($prtName = false, $post, $thisPrtr = false, $prtCode = false, $noPrint = false)
{
	global $err;
	global $SYS;
	global $REP;
	global $locDirInc;
	global $HOSTNAME;
	global $RFS;
	global $VCS;
	$rfACC = array();
	$rfACC = getG("RF_USRN");
	if (!$prtName && $prtCode) {
		$parPRCD = array();
		$parPRCD["PRTC"][0]["val1"] = $prtCode;
		$parPRCD["INST"][0]["val1"] = getG("locInstalation");
		$parPRCD["USRN"][0]["val1"] = getG("USRN");
		$parPRCD["USRG"][0]["val1"] = getG("USRG");
		if ($post["par"]["ENTI"] || $post["par"]["BKST"])
			$parPRCD["ENTI"][0]["val1"] = ($post["par"]["ENTI"][0]["val1"]) ? $post["par"]["ENTI"][0]["val1"] : $post["par"]["BKST"][0]["val1"];
		if ($post["par"]["CMPC"])
			$parPRCD["CMPC"][0]["val1"] = $post["par"]["CMPC"][0]["val1"];
		if ($post["par"]["INTL"])
			$parPRCD["INTL"][0]["val1"] = $post["par"]["INTL"][0]["val1"];
		if ($post["par"]["RCST"])
			$parPRCD["RCST"][0]["val1"] = $post["par"]["RCST"][0]["val1"];
		if ($RFS || $VCS || $rfACC) { // if this is from RF, get params from RF user, like warehouse and user team
			if ($rfACC["ENTI"])
				$parPRCD["ENTI"][0]["val1"] = $rfACC["ENTI"];
			if ($rfACC["TEAM"])
				$parPRCD["TEAM"][0]["val1"] = $rfACC["TEAM"];
		}
		if ($datPRCD = exec_sql("ACPRCD", "getAccValues", $parPRCD))
			$prtName = $datPRCD[0]["val"];
		else {
			$parT0134 = array();
			$parT0134["PRTC"][0]["val1"] = $prtCode;
			if ($datT0134 = exec_sql("T0134", "read", $parT0134))
				$prtName = $datT0134[0]["Stampa"];
		}
	}
	$parPRTV = array();
	$parPRTV["INST"][0]["val1"] = getG("locInstalation");
	$parPRTV["PRTG"][0]["val1"] = $prtName;
	$parPRTV["USRN"][0]["val1"] = getG("USRN");
	$parPRTV["USRG"][0]["val1"] = getG("USRG");
	if ($post["par"]["ENTI"] || $post["par"]["BKST"])
		$parPRTV["ENTI"][0]["val1"] = ($post["par"]["ENTI"][0]["val1"]) ? $post["par"]["ENTI"][0]["val1"] : $post["par"]["BKST"][0]["val1"];
	if ($post["par"]["CMPC"])
		$parPRTV["CMPC"][0]["val1"] = $post["par"]["CMPC"][0]["val1"];
	if ($post["par"]["INTL"])
		$parPRTV["INTL"][0]["val1"] = $post["par"]["INTL"][0]["val1"];
	if ($post["par"]["RCST"])
		$parPRTV["RCST"][0]["val1"] = $post["par"]["RCST"][0]["val1"];
	if ($RFS || $VCS || $rfACC) { // if this is from RF, get params from RF user, like warehouse and user team
		if ($rfACC["ENTI"])
			$parPRTV["ENTI"][0]["val1"] = $rfACC["ENTI"];
		if ($rfACC["TEAM"])
			$parPRTV["TEAM"][0]["val1"] = $rfACC["TEAM"];
	}
	$datPRTV = exec_sql("ACPRTV", "getAccValues", $parPRTV);
	$prtPar["PRTG"][0]["val1"] = $prtName;
	if ((!$datPRTV[4]["val"] && !$noPrint) || $datPRTV[0]["val"]) {
		$dat134 = exec_sql("T0134", "read_T0134V02", $prtPar);
		if ($datPRTV[9]["val"] == "1") {
			$parPRTC = array();
			$parPRTC["PRTG"][0]["val1"] = $prtName;
			$parPRTC["USRN"][0]["val1"] = getG("USRN");
			$parPRTC["USRG"][0]["val1"] = getG("USRG");
			// if this is from RF, get params from RF user, like warehouse and user team
			if ($RFS || $VCS || $rfACC) {
				if ($rfACC["ENTI"])
					$parPRTC["ENTI"][0]["val1"] = $rfACC["ENTI"];
				if ($rfACC["TEAM"])
					$parPRTC["TEAM"][0]["val1"] = $rfACC["TEAM"];
			}
			$datPRTC = exec_sql("ACPRTC", "getAccValues", $parPRTC);
			if ($datPRTC[0]["val"])
				exec_local_print($datPRTC[0]["val"], $post, $thisPrtr, $noPrint);
		} elseif ($dat134 && !$err) {
			if ($post["lpar"])
				foreach ($post["lpar"] as $l => $linPar) {
					$allPar[$l] = $post["par"];
					foreach ($linPar as $par => $parm)
						$allPar[$l][$par] = $parm;
				} else
				$allPar[0] = $post["par"];
			if (($dat134[0]["70"] && !$datPRTV) || $datPRTV[6]["val"]) {
				if ($num = exec_sql("ACNIMP", "getAccValues"))
					$prtSpoolName = $num[0]["val"];
				else
					send_msg("SY", "GEN", "7", array("p1" => "NIMP"));
				$prtFile = getG("locDirBase") . getG("locDirSpool") . $prtSpoolName . ".pdf";
			} else {
				$fileName = "print.pdf";
				$dir = createSessionDir();
				$prtFile = getG("locDirBase") . $dir . $fileName;
				$_SESSION["repOpenFile"]["file"] = $fileName;
				$_SESSION["repOpenFile"]["dir"] = $dir;
				$_SESSION["repOpenFile"]["opt"] = "O";
			}
			if ($dat134[0]["90"]) {
				load_class_GN("GnReport", "REP");
				foreach ($allPar as $parL)
					if ($REP->getReport($dat134[0]["90"], array("par" => $parL))) if ($REP->nextData || $REP->nextDataH) {
						if (!$err) {
							$prtTitle = $REP->nextTitle;
							$prtColumns = $REP->nextColumns;
							$prtHeader = $REP->nextHeader;
							$prtData = $REP->nextData;
							$prtDataH = $REP->nextDataH;
							$prtBckAttr = $REP->nextBckAttr;
							$prtDtrPrt = $REP->nextDtrPrt;
							$prtMail2 = $REP->sendMail2;
							if (!$err)
								include($locDirInc . $dat134[0]["200"]);
							if (!$err)
								documentOutput($thisPrtr, $prtName, $prtFile, $prtSpoolName, false, $dat134, $datPRTV, $prtDtrPrt, $prtMail2, $prtBckAttr, $parL, $post, $prtTitle, $noPrint);
						}
					}
			} else {
				foreach ($allPar as $parL) {
					// Variable $prtMail2 is set to global to make sure that Documents made with EPED can be sent by email.
					global $prtMail2;
					include($locDirInc . $dat134[0]["200"]);
					if (!$err)
						documentOutput($thisPrtr, $prtName, $prtFile, $prtSpoolName, false, $dat134, $datPRTV, $prtDtrPrt, $prtMail2, $prtBckAttr, $parL, $post, $prtTitle, $noPrint);
					$prtMail2 = false;
				}
			}
		} else
			send_msg("SY", "GEN", "15", array("p1" => $prtName));
	}
}
function exec_local_print($prtName, $post, $thisPrtr = false, $noPrint = false)
{
	global $err;
	global $SYS;
	global $REPL;
	global $REP;
	global $locDirInc;
	global $HOSTNAME;
	global $RFS;
	global $VCS;
	$parPRTL = array();
	$parPRTL["PRTL"][0]["val1"] = $prtName;
	$parPRTL["USRN"][0]["val1"] = getG("USRN");
	$parPRTL["USRG"][0]["val1"] = getG("USRG");
	// if this is from RF, get params from RF user, like warehouse and user team
	$rfACC = array();
	$rfACC = getG("RF_USRN");
	if ($RFS || $VCS || $rfACC) {
		if ($rfACC["ENTI"])
			$parPRTL["ENTI"][0]["val1"] = $rfACC["ENTI"];
		if ($rfACC["TEAM"])
			$parPRTL["TEAM"][0]["val1"] = $rfACC["TEAM"];
	}
	$datPRTL = exec_sql("ACPRTL", "getAccValues", $parPRTL);
	$prtPar["PRTL"][0]["val1"] = $prtName;
	if ((!$datPRTL[4]["val"] && !$noPrint) || $datPRTL[0]["val"]) {
		$datI0084 = exec_sql("I0084", "read_I0084V02", $prtPar);
		if ($datI0084 && !$err) {
			load_class_GN("GnReportLocal", "REPL");
			if ($post["lpar"])
				foreach ($post["lpar"] as $l => $linPar) {
					$allPar[$l] = $post["par"];
					foreach ($linPar as $par => $parm)
						$allPar[$l][$par] = $parm;
				} else
				$allPar[0] = $post["par"];
			foreach ($allPar as $parL)
				if ($REPL->getReport($datI0084[0]["90"], array("par" => $parL))) if ($REPL->nextData || $REPL->nextDataH) {
					if (!$err) {
						if (($datI0084[0]["70"] && !$datPRTL) || $datPRTL[6]["val"]) {
							if ($num = exec_sql("ACNIMP", "getAccValues"))
								$prtSpoolName = $num[0]["val"];
							else
								send_msg("SY", "GEN", "7", array("p1" => "NIMP"));
							$prtFile = getG("locDirBase") . getG("locDirSpool") . $prtSpoolName . ".pdf";
						} else {
							$fileName = "print.pdf";
							$dir = createSessionDir();
							$prtFile = getG("locDirBase") . $dir . $fileName;
							$_SESSION["repOpenFile"]["file"] = $fileName;
							$_SESSION["repOpenFile"]["dir"] = $dir;
							$_SESSION["repOpenFile"]["opt"] = "O";
						}
						$prtTitle = $REPL->nextTitle;
						$prtColumns = $REPL->nextColumns;
						$prtHeader = $REPL->nextHeader;
						$prtData = $REPL->nextData;
						$prtDataH = $REPL->nextDataH;
						$prtBckAttr = $REPL->nextBckAttr;
						$prtDtrPrt = $REPL->nextDtrPrt;
						$prtMail2 = $REPL->sendMail2;
						if (!$err)
							include($locDirInc . $datI0084[0]["200"]);
					}
					if (!$err)
						documentOutput($thisPrtr, $prtName, $prtFile, $prtSpoolName, true, $datI0084, $datPRTL, $prtDtrPrt, $prtMail2, $prtBckAttr, $parL, $post, $prtTitle, $noPrint);
				}
		} else {
			if (!$post["nomsg"])
				send_msg("SY", "GEN", "15", array("p1" => $prtName));
		}
	}
}
function documentOutput($printer, $prtName, $prtFile, $prtSpoolName, $localPrint, $printDefs, $printAttribute, $prtDtrPrt, $email, $prtBckAttr, $parL, $post, $prtTitle, $noPrint)
{
	global $SYS;
	global $HOSTNAME;
	global $RFS;
	global $VCS;
	global $EVE;

	// Should we delete the spool once printed?
	if ($localPrint)
		$saveSpool = $printAttribute[9]["val"] ? $printAttribute[9]["val"] : $printDefs[0]["50"];
	else
		$saveSpool = $printAttribute[10]["val"] ? $printAttribute[10]["val"] : $printDefs[0]["50"];

	// if we should not print but we are it's because we need to arquive the document
	if (($printAttribute[4]["val"] || $noPrint) && $printAttribute[0]["val"]) {
		$outData = array();
		$outData["fld"]["I0013"][0]["Spool_coda_stato"] = "06";
		$outData["fld"]["I0013"][0]["Spool"] = $prtSpoolName;
		$outData["fld"]["I0013"][0]["Stampante"] = "ZNULL"; // fixed value: meaningless
		$outData["fld"]["I0013"][0]["Spool_coda"] = "01"; // fixed value: meaningless
		$arrayDir = array_reverse(explode('/', $prtFile));
		$outData["fld"]["I0013"][0]["File_nome"] = $arrayDir[0];
		$outData["fld"]["I0013"][0]["Stampa"] = $prtName;
		$outData["fld"]["I0013"][0]["Stampa_copie"] = $printAttribute[7]["val"] ? $printAttribute[7]["val"] : $printDefs[0]["30"];
		$outData["fld"]["I0013"][0]["Stampa_salva_spool"] = $saveSpool;
		$outData["fld"]["I0013"][0]["Utente"] = getG("USRN");
		$outData["fld"]["I0013"][0]["Ora_creazione"] = date("YmdHis");
		$outData["fld"]["I0013"][0]["Server"] = $HOSTNAME;
		$outData["fld"]["I0013"][0]["PagesSheet"] = $printAttribute[5]["val"];
		$outData["fld"]["I0013"][0]["FrontBack"] = $printAttribute[1]["val"];
		$outData["fld"]["I0013"][0]["Ente"] = ($post["par"]["BKST"][0]["val1"]) ? $post["par"]["BKST"][0]["val1"] : (($post["par"]["ENTI"][0]["val1"]) ? $post["par"]["ENTI"][0]["val1"] : false);
		$outData["fld"]["I0013"][0]["File_backup"] = $printAttribute[0]["val"];
		if ($prtBckAttr)
			foreach ($prtBckAttr as $keyField => $keyVal)
				$outData["fld"]["I0013"][0][$keyField] = substr($keyVal, 0, 20);
		elseif (isr_count($parL)) {
			$cntKeys = 1;
			if ($parL)
				foreach ($parL as $parName => $parVal)
					$outData["fld"]["I0013"][0]["Chiave_0" . $cntKeys++] = substr($parVal[0]["val1"], 0, 20);
		}
		exec_sql("I0013", "write", $outData);
	} elseif (($printDefs[0]["70"] && !$printAttribute) || $printAttribute[6]["val"]) { // If with spool, determine printer and outq
		//The declarePrinterRF is written in the physical action declareTechnicalPrinterAfter to be declared a radio frequency printer
		if (!$printer && getG("declarePrinterRF")) {
			$printer = getG("declarePrinterRF");
			setG("declarePrinterRF", "");
		}
		// try do check if in session have printer define
		if (!$printer && getG("Printers")) {
			$printersSession = json_decode(getG("Printers"), true);
			$parT0134 = array(); //determine if graph or zpl
			$parT0134["PRTG"][0]["val1"] = $prtName;
			if ($datT0134 = exec_sql("T0134", "read", $parT0134)) {
				if (($datT0134[0]["Stampa_funzione"] == "ZplPrint" || $datT0134[0]["Stampa_funzione"] == "SpecialPrint") && $printersSession["barcode"])
					$printer = $printersSession["barcode"];
				elseif (($datT0134[0]["Stampa_funzione"] == "EpsPrint" || $datT0134[0]["Stampa_funzione"] == "PrintList") && $printersSession["graph"])
					$printer = $printersSession["graph"];
			} else {
				//check local prints
				$parI0084 = array();
				$parI0084["PRTL"][0]["val1"] = $prtName;
				if ($datI0084 = exec_sql("I0084", "read", $parI0084)) {
					if (($datI0084[0]["Stampa_funzione"] == "ZplPrint" || $datI0084[0]["Stampa_funzione"] == "SpecialPrint") && $printersSession["barcode"])
						$printer = $printersSession["barcode"];
					elseif (($datI0084[0]["Stampa_funzione"] == "EpsPrint" || $datI0084[0]["Stampa_funzione"] == "PrintList") && $printersSession["graph"])
						$printer = $printersSession["graph"];
				}
			}
		}

		if (!$printer) {
			$parPRTD = array();
			$parPRTD["PRTG"][0]["val1"] = $prtName;
			$parPRTD["PRTL"][0]["val1"] = $prtName;
			$parPRTD["USRN"][0]["val1"] = getG("USRN");
			$parPRTD["USRG"][0]["val1"] = getG("USRG");
			$parPRTD["ENTI"][0]["val1"] = $prtDtrPrt["ENTI"][0]["val1"];
			$parPRTD["SPCT"][0]["val1"] = $prtDtrPrt["SPCT"][0]["val1"];
			$parPRTD["SPAC"][0]["val1"] = $prtDtrPrt["SPAC"][0]["val1"];
			// if this is from RF, get params from RF user, like warehouse and user team
			$rfACC = array();
			$rfACC = getG("RF_USRN");
			if ($RFS || $VCS || $rfACC) {
				if ($rfACC["ENTI"])
					$parPRTD["ENTI"][0]["val1"] = $rfACC["ENTI"];
				if ($rfACC["TEAM"])
					$parPRTD["TEAM"][0]["val1"] = $rfACC["TEAM"];
			}
			if ($datPRTD = exec_sql("ACPRTD", "getAccValues", $parPRTD)) {
				$outq = $datPRTD[0]["val"];
				$printer = $datPRTD[1]["val"];
			} else
				send_msg("IN", "PRT", "6");
		}
		// if printer not available verify alternatives ... if none let's stay on the same printer
		$parI0012["PRTR"][0]["val1"] = $printer;
		if (!$datI0012 = exec_sql("I0012", "read", $parI0012)) {
			send_msg("IN", "PRT", "4", array("p1" => $printer));
			return;
		}
		$info = $SYS->printerManager($printer);
		if ($info[0] != "01" || $datI0012[0]["Stampante_sospesa"]) {
			$parI0035 = array();
			$parI0035["PRTR"][0]["val1"] = $printer;
			if ($datI0035 = exec_sql("I0035", "read_I0035V01", $parI0035))
				foreach ($datI0035 as $recI0035) {
					$parI0012["PRTR"][0]["val1"] = $recI0035["Stampante_altra"];
					if ($datI0012Alt = exec_sql("I0012", "read", $parI0012)) {
						$infoAlt = $SYS->printerManager($recI0035["Stampante_altra"]);
						if ($infoAlt[0] == "01" && !$datI0012Alt[0]["Stampante_sospesa"]) {
							$info = $infoAlt;
							$datI0012 = $datI0012Alt;
							$printer = $recI0035["Stampante_altra"];
							break;
						}
					}
				}
		}
		if ($datI0012[0]["FrontBack"] && $printAttribute[1]["val"])
			$frtBck = true;
		else
			$frtBck = false;
		if ($datI0012[0]["PagesSheet"] && $printAttribute[5]["val"])
			$pgPrSht = true;
		else
			$pgPrSht = false;
		$parI0019 = array();
		if ($outq)
			$parI0019["OUTQ"][0]["val1"] = $outq;
		$parI0019["PRTS"][0]["val1"] = "01"; // active queue
		if (!$datI0019 = exec_sql("I0019", "read", $parI0019))
			send_msg("IN", "PRT", "5", array("p1" => $outq));
		else
			$outq = $datI0019[0]["Spool_coda"];
		// send pdf document by mail if we have to
		if ($printAttribute[3]["val"] && $email)
			foreach ($email["recipients"] as $recipient)
				$SYS->sendMail($email["subject"], $recipient, $prtFile);
		if (($printDefs[0]["70"] && !$printAttribute) || $printAttribute[6]["val"]) {
			$outData = array();
			if ((($printDefs[0]["40"] && !$printAttribute) || $printAttribute[8]["val"]) && $datI0019[0]["Stampante_stato"] == "01" && $info[0] == "01") {
				if ($SYS->printPdfFile($prtFile, $prtSpoolName, $printer, $datI0012[0]["Stampante_tipo"], $printAttribute[7]["val"] ? $printAttribute[7]["val"] : $printDefs[0]["30"], $frtBck, $pgPrSht)) {
					if (!$post["nomsg"]) {
						if ($EVE || $VCS)
							send_msg("SY", "GEN", "101");
						else
							send_msg("SY", "GEN", "12", array("p1" => $prtTitle));
					}
					$outData["fld"]["I0013"][0]["Spool_coda_stato"] = "02";
				} else {
					if (!$post["nomsg"])
						send_msg("SY", "GEN", "14", array("p1" => $prtTitle));
					$outData["fld"]["I0013"][0]["Spool_coda_stato"] = "03";
				}
			} else {
				if (!$post["nomsg"]) {
					if ($EVE || $VCS)
						send_msg("SY", "GEN", "101");
					else
						send_msg("SY", "GEN", "13", array("p1" => $prtTitle));
				}
				$outData["fld"]["I0013"][0]["Spool_coda_stato"] = "03";
			}
			$arrayDir = array_reverse(explode('/', $prtFile));
			$outData["fld"]["I0013"][0]["Spool"] = $prtSpoolName;
			$outData["fld"]["I0013"][0]["Stampante"] = $printer;
			$outData["fld"]["I0013"][0]["Spool_coda"] = $outq;
			$outData["fld"]["I0013"][0]["File_nome"] = $arrayDir[0];
			$outData["fld"]["I0013"][0]["Stampa"] = $prtName;
			$outData["fld"]["I0013"][0]["Stampa_copie"] = $printAttribute[7]["val"] ? $printAttribute[7]["val"] : $printDefs[0]["30"];
			$outData["fld"]["I0013"][0]["Stampa_salva_spool"] = $saveSpool;
			$outData["fld"]["I0013"][0]["Utente"] = getG("USRN");
			$outData["fld"]["I0013"][0]["Ora_creazione"] = date("YmdHis");
			$outData["fld"]["I0013"][0]["Server"] = $HOSTNAME;
			$outData["fld"]["I0013"][0]["PagesSheet"] = $printAttribute[5]["val"];
			$outData["fld"]["I0013"][0]["FrontBack"] = $printAttribute[1]["val"];
			$outData["fld"]["I0013"][0]["Ente"] = ($post["par"]["BKST"][0]["val1"]) ? $post["par"]["BKST"][0]["val1"] : (($post["par"]["ENTI"][0]["val1"]) ? $post["par"]["ENTI"][0]["val1"] : false);
			$outData["fld"]["I0013"][0]["File_backup"] = $printAttribute[0]["val"];
			if ($prtBckAttr)
				foreach ($prtBckAttr as $keyField => $keyVal)
					$outData["fld"]["I0013"][0][$keyField] = substr($keyVal, 0, 20);
			elseif (isr_count($parL)) {
				$cntKeys = 1;
				if ($parL)
					foreach ($parL as $parName => $parVal)
						$outData["fld"]["I0013"][0]["Chiave_0" . $cntKeys++] = substr($parVal[0]["val1"], 0, 20);
			}
			exec_sql("I0013", "write", $outData);
		}
	}
}
function getLangBrowser()
{
	$lingue_temp = explode(',', $_SERVER["HTTP_ACCEPT_LANGUAGE"] ?? '');
	if ($lingue_temp[0] == "pt-BR")
		return "BR";
	elseif ($lingue_temp[0] <> "")
		return strtoupper(substr($lingue_temp[0], 0, 2));
	else
		return "EN";
}
function createSessionDir()
{
	$dir = getG("locDirBase") . getG("locDirSessions") . getG("locSession");
	if (!file_exists($dir)) if (!@mkdir($dir, 0666) || !@chmod($dir, 0777)) {
		send_msg("SY", "GEN", "37");
		return false;
	}
	return getG("locDirSessions") . getG("locSession") . "/";
}
function addSessionFile($fileName = false, $lines = false, $mode = "w")
{
	if (!$dir = createSessionDir())
		return false;
	if (!$fileName) {
		$tempName = pathinfo(tempnam(substr($dir, -1), ""));
		return $tempName["basename"];
	} elseif ($lines) {
		if ($mode == "D") {
			if (!$fw = fopen(getG("locDirBase") . $dir . $fileName . ".php", "w")) {
				send_msg("SY", "GEN", "37");
				return false;
			}
			fwrite($fw, "<?php\n");
			fwrite($fw, "header(\"Content-disposition: filename=" . $fileName . "\");\n");
			fwrite($fw, "header(\"Content-type: application/octetstream\");\n");
			fwrite($fw, "header(\"Pragma: no-cache\");\n");
			fwrite($fw, "header(\"Expires: 0\");\n");
			fwrite($fw, "\$fw = file(\"" . $fileName . "\");\n");
			fwrite($fw, "foreach(\$fw as \$rec) echo \$rec;\n");
			fwrite($fw, "?" . ">\n");
			fclose($fw);
			$mode = "w";
		}
		if (!$fw = fopen(getG("locDirBase") . $dir . $fileName, $mode)) {
			send_msg("SY", "GEN", "37");
			return false;
		}
		foreach ($lines as $rec)
			fwrite($fw, $rec);
		fclose($fw);
	}
	return true;
}
function getExpression($expr, $qts = "'")
{
	if (substr($expr, 0, 7) == "#STRING")
		return trim(substr($expr, 7));
	$expr = str_replace("#DAY", date("d"), $expr);
	$expr = str_replace("#TODAY", date("Ymd"), $expr);
	$expr = str_replace("#YEAR", date("Y"), $expr);
	$expr = str_replace("#MONTH", date("m"), $expr);
	$expr = str_replace("#SVDSCT", $qts . getG("DSCT") . $qts, $expr);
	$expr = str_replace("#SVLANG", $qts . getG("LANG") . $qts, $expr);
	$expr = str_replace("#SVTIPC", $qts . getG("TIPC") . $qts, $expr);
	$expr = str_replace("#SVTIPE", $qts . getG("TIPE") . $qts, $expr);
	$expr = str_replace("#SVUSRNP", $qts . getG("USRN") . $qts, $expr);
	$expr = str_replace("#SVUSRN", getG("USRN"), $expr);
	$expr = str_replace("#SVINST", $qts . getG("locInstalation") . $qts, $expr);
	if (substr($expr, 0, 8) == "#PHPFUNC")
		$expr = eval ("return " . str_replace("#PHPFUNC", "", $expr) . ";");
	return $expr;
}
function chkLock($obj, $key, $mode, $chkip = false)
{
	global $SSID;
	global $SESS;
	global $levLock;
	$par["LCKO"][0]["val1"] = $obj;
	$par["LCKK"][0]["val1"] = $key;
	$data = exec_sql("I0018", "read", $par);
	if ($data) {
		if ($data[0]["Sessione_id"] == $SSID && $data[0]["Sessione_numero"] == $SESS)
			return array("0" => true);
		elseif ($mode == "02")
			return array("0" => false, "1" => $data[0]["Std_utente"]);
		elseif (($data[0]["Sessione_ultima"] + getG("maxLckTime")) > time()) {
			if (!$chkip)
				return array("0" => false, "1" => $data[0]["Std_utente"]);
			else {
				$parI0011 = array();
				$parI0011["SSID"][0]["val1"] = $data[0]["Sessione_id"];
				$parI0011["SNUM"][0]["val1"] = "0";
				if ($datI0011 = exec_sql("I0011", "read", $parI0011)) if ($datSess = igbinary_unserialize($datI0011[0]["Sessione_dati"])) {
					if ($datSess["remIP"] != getG("remIP"))
						return array("0" => false, "1" => $data[0]["Std_utente"]);
					else {
						$eraI0011["fld"]["I0011"][0]["Sessione_id"] = $data[0]["Sessione_id"];
						exec_sql("I0011", "erase", $eraI0011);
					}
				}
			}
		} elseif ($mode != "01" || ($mode == "01" && !$SSID && !$SESS)) {
			$lckDat["fld"]["I0018"][0]["Oggetto_blocco"] = $obj;
			$lckDat["fld"]["I0018"][0]["Blocco_chiave"] = $key;
			exec_sql("I0018", "erase", $lckDat);
			return array("0" => true);
		}
	} elseif ($mode == "02")
		return array("0" => false);
	if ($mode == "01" && $SSID && $SESS) {
		$lckDat["fld"]["I0018"][0]["Oggetto_blocco"] = $obj;
		$lckDat["fld"]["I0018"][0]["Blocco_chiave"] = $key;
		$lckDat["fld"]["I0018"][0]["Sessione_id"] = $SSID;
		$lckDat["fld"]["I0018"][0]["Sessione_numero"] = ($SESS == "NEW") ? 1 : $SESS;
		$lckDat["fld"]["I0018"][0]["Sessione_livello"] = $levLock;
		$lckDat["fld"]["I0018"][0]["Sessione_ultima"] = time();
		$lckDat["fld"]["I0018"][0]["Utente"] = getG("USRN");
		exec_sql("I0018", "modify", $lckDat);
		setG("withLocks", true);
	}
	return array("0" => true);
}
function rlsLocks($SSID, $SESS, $lev = 0)
{

	$lckPar["SSID"][0]["val1"] = $SSID;
	if ($SESS)
		$lckPar["SNUM"][0]["val1"] = $SESS;
	$lock4ever = getG("lock4ever");
	//	if(!getG("lock4ever")) if ($data = exec_sql("I0018", "read", $lckPar)) {
	if ($data = exec_sql("I0018", "read", $lckPar)) {
		foreach ($data as $l => $linLck)
			if ($linLck["Sessione_livello"] >= $lev) {
				if ($lock4ever && $linLck["Oggetto_blocco"] == $lock4ever["obj"] && $linLck["Blocco_chiave"] == $lock4ever["key"])
					continue;
				$lckDat["fld"]["I0018"][$l]["Oggetto_blocco"] = $linLck["Oggetto_blocco"];
				$lckDat["fld"]["I0018"][$l]["Blocco_chiave"] = $linLck["Blocco_chiave"];
			}
		exec_sql("I0018", "erase", $lckDat);
	}
}
function lockObject($obj, $parKeys, $type, $post, $chkip = false)
{
	global $err;
	if (!$post["lpar"])
		$post["lpar"][0] = $post["par"];
	foreach ($post["lpar"] as $lin => $linPar) {
		$key = false;
		foreach ($parKeys as $parm => $x) {
			if ($linPar[$parm]) {
				if ($key)
					$key .= "||";
				$key .= $linPar[$parm][0]["val1"];
			} elseif ($post["par"][$parm]) {
				if ($key)
					$key .= "||";
				$key .= $post["par"][$parm][0]["val1"];
			} else {
				$msgPar["p2"] = $parm;
				foreach ($parKeys as $desc) {
					if ($msgPar["p1"])
						$msgPar["p1"] .= ", ";
					$msgPar["p1"] .= $desc;
				}
				send_msg("SY", "DBA", "55", $msgPar);
			}
			if ($err)
				break;
		}
		if ($err)
			break;
		else {
			if (!$key)
				$key = "*ALL";
			$lockChecked = chkLock($obj, $key, $type, $chkip);
			if (!$lockChecked["0"]) {
				if ($type == "02")
					send_msg("SY", "DBA", "49");
				else {
					if ($key != "*ALL")
						$msgPar["p1"] = str_replace("||", "_", $key);
					else
						$msgPar["p1"] = "";
					$msgPar["p2"] = "(" . $lockChecked["1"] . ")";
					send_msg("SY", "DBA", "41", $msgPar);
				}
			}
			if ($err)
				break;
		}
	}
}
function chkStatusModif($chkMod)
{
	if (getG("localDev")) {
		foreach ($chkMod as $mod => $x) {
			$par["MODI"][0]["val1"] = $mod;
			if ($stat = exec_sql("T0085", "read_T0085V02", $par)) {
				$newStat = false;
				global $SSID;
				$arrI0011 = array();
				$arrI0011["SSID"][0]["val1"] = $SSID;
				$arrI0011["MODI"][0]["sig1"] = "NN";
				$data = exec_sql("I0011", "read", $arrI0011);
				if ($stat[0]["20"] == "10" && $data)
					$newStat = "20";
				elseif ($stat[0]["20"] == "20" && !$data)
					$newStat = "10";
				if ($newStat) {
					$modDat["fld"]["T0085"][0]["Modificazione"] = $mod;
					$modDat["fld"]["T0085"][0]["Status_applicativo"] = $newStat;
					exec_sql("T0085", "update", $modDat);
				}
			}
		}
	}
}
function htmlXspecialchars($string, $ent = ENT_COMPAT, $charset = 'ISO-8859-15')
{
	if (getG("locCharset") && getG("locCharset") != "ISO-8859-2")
		$charset = getG("locCharset");
	return htmlspecialchars($string, $ent, $charset);
}

function checkToken($RFS, $SSID, $SESS)
{
	if ($RFS) {
		global $ENVN;
		global $locLibDb;
		global $locHostDb;
		global $locDatabase;
		global $locUserDb;
		global $locPasswordDb;
		$postToken = $_GET["TOKEN"];
		$DBToken = new $locLibDb[$ENVN]($locHostDb[$ENVN], $locDatabase[$ENVN], $locUserDb[$ENVN], $locPasswordDb[$ENVN], FALSE, TRUE);
		if (!$DBToken->Link_ID) {
			$err = true;
			abort("Can\'t connect to database");
		}
		$rtab = array("I0111" => "I0111");
		$rfld = array("Sessione_id" => "I0111.Sessione_id", "Sessione_numero" => "I0111.Sessione_numero", "Token" => "I0111.Token", "Validita_fine" => "I0111.Validita_fine", "Std_f1" => "I0111.Std_f1");
		$rjoi = array("I0111" => "P");
		$rwhr = array();
		$rwhr[0]["sign"] = "=";
		$rwhr[0]["val"] = $SSID;
		$rwhr[0]["fld"] = "I0111.Sessione_id";
		$rwhr[1]["sign"] = "=";
		$rwhr[1]["val"] = $SESS;
		$rwhr[1]["fld"] = "I0111.Sessione_numero";
		$rwhr[2]["sign"] = "=";
		$rwhr[2]["val"] = "1";
		$rwhr[2]["fld"] = "I0111.Std_f1";
		$rorb = array();
		$rorb[0]["field"] = "Sessione_id";
		$rorb[1]["field"] = "Sessione_numero";
		$rorb[2]["field"] = "Token";
		$rpar = array();
		$rpar["XLOCK"][] = "I0111";
		$datI0111 = $DBToken->select_query($rtab, $rfld, $rjoi, $rwhr, array(), $rorb, array(), $rpar);
		if ($datI0111) {
			if (($_GET["TOKEN"] == "XXX") && $datI0111[0]["Token"] == getG("token")) { // compare token in I0111 vs I0011 to discover if previous process already finished
				if (getG("Jump2Menu")) {
					$after["MENU"] = TRUE;
					setG("allMns", "");
					if ($VCS)
						include($locDirInc . "GnMenuVoice.php");
					else
						include($locDirInc . "GnMenuPdt.php");
				} elseif ($_SESSION["repBrowser"])
					include($locDirInc . $_SESSION["repBrowser"]);
				exit;
			} elseif ($datI0111[0]["Token"] == $postToken) {
				$newToken = ++$postToken;
				$wrt = array();
				$wrt[0]["Sessione_id"] = $SSID;
				$wrt[0]["Token"] = $newToken;
				$wrt[0]["Sessione_numero"] = $SESS;
				$wfldT = array();
				$wfldT["Sessione_id"] = "K";
				$wfldT["Sessione_numero"] = "K";
				$wfldT["Token"] = "F";
				$wfldT["Validita_inizio"] = "F";
				$wfldT["Validita_fine"] = "F";
				$wfldT["Std_f1"] = "F";
				$wfldT["Std_f2"] = "F";
				$wfldT["Std_f3"] = "F";
				$wfldT["Std_f4"] = "F";
				$wfldT["Std_datamod"] = "F";
				$wfldT["Std_programma"] = "F";
				$wfldT["Std_utente"] = "F";
				$recUpd = $DBToken->action_query("U", "I0111", $wfldT, $wrt);
				if ($recUpd != 1) {
					$DBToken->set_trans("R");
					send_msg("AP", "RFS", "172");
					return false;
				} else {
					setG("token", $newToken);
					$DBToken->set_trans("C");
				}
			} else { //token diferente
				setG("leaveSessionAlone", true);
				echo "<HTML><script>\nfunction timeRepost(timeoutPeriod) {\n\twindow.setTimeout(\"document.post.submit();\",timeoutPeriod);\n}\n</script><BODY onLoad=timeRepost(2000)><br>" . get_msg("AP", "RFS", "405", array("p1" => "One second please!!!")) . "<FORM NAME=post ACTION=" . getG("locEnvDir") . "wpms.php METHOD=GET>";
				foreach ($_GET as $var => $val) {
					if ($var != "TOKEN")
						echo "<INPUT TYPE=HIDDEN NAME=$var VALUE=$val>";
					else
						echo "<INPUT TYPE=HIDDEN NAME=\"TOKEN\" VALUE=\"XXX\">";
				}
				echo get_msg("AP", "RFS", "539") . "<br>";
				echo "<div class=\"m0\">";
				echo "<input type=\"submit\" class=\"m1\" value =\"OK\">";
				echo "</div>";
				echo "</FORM></BODY></HTML>";
				die;
			}
		} else { // don't exist I0111
			$newToken = "0";
			$wrt = array();
			$wrt[0]["Sessione_id"] = $SSID;
			$wrt[0]["Token"] = $newToken;
			$wrt[0]["Sessione_numero"] = ($SESS) ? $SESS : "1";
			$wfldT = array();
			$wfldT["Sessione_id"] = "K";
			$wfldT["Sessione_numero"] = "K";
			$wfldT["Token"] = "F";
			$wfldT["Validita_inizio"] = "F";
			$wfldT["Validita_fine"] = "F";
			$wfldT["Std_f1"] = "F";
			$wfldT["Std_f2"] = "F";
			$wfldT["Std_f3"] = "F";
			$wfldT["Std_f4"] = "F";
			$wfldT["Std_datamod"] = "F";
			$wfldT["Std_programma"] = "F";
			$wfldT["Std_utente"] = "F";
			$recUpd = $DBToken->action_query("M", "I0111", $wfldT, $wrt);
			if ($recUpd != 1) {
				$DBToken->set_trans("R");
				send_msg("AP", "RFS", "172");
				return false;
			} else {
				setG("token", $newToken);
				$DBToken->set_trans("C");
			}
		}
	}
}

function checkmaxRfInactive($RFS)
{
	if (!$RFS)
		return false;
	elseif (getG("maxRfInactive")) {
		if (getG("Date") - $_SESSION["date"] > getG("maxRfInactive")) {
			echo "<HTML><BODY><FORM NAME=post ACTION=/loginPdt.php METHOD=GET>";
			echo "<INPUT TYPE=HIDDEN NAME=\"LOGOFF\" VALUE=\"RF\">";
			echo "<INPUT TYPE=HIDDEN NAME=\"ENVN\" VALUE=" . $ENVN . ">";
			echo "<INPUT TYPE=HIDDEN NAME=\"RFS\" VALUE=\"1\">";
			echo def_output("AP", "RFS", "544") . "<br>";
			echo "<div class=\"m0\">";
			echo "<input type=\"submit\" class=\"m1\" value =\"OK\">";
			echo "</div>";
			echo "</FORM></BODY></HTML>";
			die;
		}
	}
}
function writeWPMSstat($menuAction = false)
{
	global $RFS, $UTL, $WPMSstat_IT, $WPMSstat_ST;
	if (!$UTL)
		$pageSize = ob_get_length();
	if ($RFS) {
		$actiRF = getG("actiRF");
		setG("actiRF", false);
	} else
		$actiRF = false;
	if ($myActi = getG("ACTI")) {
		$fillW0036 = array();
		$fillW0036["fld"]["W0036"][0] = array("Data_standard" => date("Ymd"), "Utente" => getG("USRN"), "Azione_codice" => ((!$menuAction) ? $myActi : $menuAction), "Azione" => $actiRF, "Ora_inizio" => $WPMSstat_IT, "Tempo_usato" => (microtime(true) - $WPMSstat_ST), "Dimensioni_pagina" => $pageSize, "Server" => $_SERVER['SERVER_NAME']);
		exec_sql("W0036", "write", $fillW0036);
	}
}

function validateDate($date, $format = 'YmdHis')
{
	$d = DateTime::createFromFormat($format, $date);
	return $d && $d->format($format) == $date;
}

function convertToTimeZone($val, $type = "", $defCol = array())
{
	if (!getG("DBTZ") || getG("DBTZ") == getG("TimeZone"))
		return $val;
	if ($type == "H" || $defCol["type"] == "H") {
		if (validateDate("20230101" . $val)) {
			$datetime = date("Y-m-d H:i:s", mktime(substr($val, 0, 2), substr($val, 2, 2), 0, 1, 1, 2023));
			$given = new DateTime($datetime, new DateTimeZone(getG("DBTZ")));
			$given->setTimezone(new DateTimeZone(getG("TimeZone")));
			return $given->format("His");
		} else
			return $val;
	} elseif ($defCol["type"] == "LD" && $defCol["edit"] == "H" && $defCol["len"] == 14 && (strlen($val) >= 6 && strlen($val) < 8)) {
		if (validateDate("20230101" . $val)) {
			$datetime = date("Y-m-d H:i:s", mktime(substr($val, 0, 2), substr($val, 4, 2), 0, 1, 1, 2023));
			$given = new DateTime($datetime, new DateTimeZone(getG("DBTZ")));
			$given->setTimezone(new DateTimeZone(getG("TimeZone")));
			return $given->format("His");
		} else
			return $val;
	}
	if (validateDate($val)) {
		$datetime = date("Y-m-d H:i:s", mktime(substr($val, 8, 2), substr($val, 10, 2), substr($val, 12, 2), substr($val, 4, 2), substr($val, 6, 2), substr($val, 0, 4)));
		$given = new DateTime($datetime, new DateTimeZone(getG("DBTZ")));
		$given->setTimezone(new DateTimeZone(getG("TimeZone")));
		return $given->format("YmdHis");
	} else
		return $val;
}

// specific for php 8
function isr_count($par)
{
	if (PHP_MAJOR_VERSION < 8)
		return count($par);
	else {
		if (is_array($par) || $par instanceof Countable)
			return count($par);
		else {
			//uncomment for count logs
			//$one_more_issue = debug_backtrace();
			//error_log("\n" . date("Y/m/d H:i:s") . " " . $one_more_issue[0]["file"] . "_" . $one_more_issue[0]["line"] . "\n", 3, "/www/htdocs/issuesWithCount.log");
			//error_log(print_r($one_more_issue[0]["args"], true), 3, "/www/htdocs/issuesWithCount.log");
			return 0;
		}
	}
}

// ------------------ Start -------------------
global $WPMSstat_ST, $WPMSstat_IT;
$startTime = $WPMSstat_ST = microtime(true);
$iniTime = $WPMSstat_IT = date("YmdHis");

$catchErr = false;
$err = false;
$repMessages = false;
$loaded_classes = array();
$loaded_instances = array();
if ($locLibDb[$ENVN] && $locLibSys[$ENVN]) {
	if (@include($locDirInc . $locLibDb[$ENVN] . ".cla"))
		$DB = new $locLibDb[$ENVN]($locHostDb[$ENVN], $locDatabase[$ENVN], $locUserDb[$ENVN], $locPasswordDb[$ENVN]);
	if (!$DB->Link_ID) {
		$err = true;
		abort("Can\'t connect to database");
	}
	if (include($locDirInc . $locLibSys[$ENVN] . ".cla"))
		$SYS = new $locLibSys[$ENVN]();
	else {
		$err = true;
		abort("Error loading system library");
	}
} else
	$err = TRUE;
if ($locCouchbase[$ENVN]) {
	if (@include($locDirInc . $locCouchbase[$ENVN]["class"] . ".cla")) {
		$MC = new $locCouchbase[$ENVN]["class"]($locCouchbase[$ENVN]["cluster"], $locCouchbase[$ENVN]["cache"], $locCouchbase[$ENVN]["user"], $locCouchbase[$ENVN]["password"]);
	}
}

// trace activated ... em teste. Talvez passar para o odata.php ou ainda ter aqui mais abaixo e no odata.php
if (extension_loaded("xdebug") && isset($_COOKIE["XDEBUG_TRACE"])) {
	ini_set("xdebug.collect_params", "4");
	ini_set("xdebug.show_mem_delta", 1);
	ini_set("xdebug.collect_return", 1);
	//	$dirTrace = createSessionDir();
//	xdebug_start_trace(getG("locDirBase").$dirTrace.$SSID."_".$SESS);
	xdebug_start_trace("/tmp/traceFile_" . getmypid());
}

if (!$err) {
	setG("locEnvEVE", $locEnvEVE[$ENVN]);
	setG("locEnv", $locEnvName[$ENVN]);
	setG("locInstalation", $locInstalation[$ENVN]);
	setG("locDirUser", $locDirUser[$ENVN]);
	setG("locDirRoot", $locDirBase . "/");
	setG("locDirBase", $locDirBase . $locEnvDir[$ENVN]);
	setG("locEnvDir", $locEnvDir[$ENVN]);
	setG("locDirClasses", $locDirClasses[$ENVN]);
	setG("locDirIncludes", $locDirIncludes[$ENVN]);
	setG("locDirBitmaps", $locDirBitmaps[$ENVN]);
	setG("locDirPictures", $locDirPictures[$ENVN]);
	setG("locDirTemp", $locDirTemp[$ENVN]);
	setG("locDirSessions", $locDirTemp[$ENVN] . "sessions/");
	setG("locDirSpool", $locDirTemp[$ENVN] . "spool/");
	setG("locDirLogs", $locDirTemp[$ENVN] . "logs/");
	setG("locDirBackups", $locDirBackups[$ENVN]);
	setG("locDirArchives", $locDirArchives[$ENVN]);
	setG("locDirExports", $locDirExports[$ENVN]);
	setG("locDirUpgrades", $locDirUpgrades[$ENVN]);
	setG("locDirProfiles", $locDirProfiles[$ENVN]);
	setG("locDirHelp", $locDirHelp[$ENVN]);
	setG("locDirCss", $locDirCss[$ENVN]);
	setG("sysMail", $locSysMail[$ENVN]);
	setG("waterMarkText", $waterMarkText[$ENVN]);
	setG("locLibDb", $locLibDb[$ENVN]);
	setG("locCharset", $locCharset[$ENVN]);
	setG("locExtLib", $locDirExtLib[$ENVN]);
	setG("locSuperUserSmtpUsr", $locSuperUserSmtpUsr[$ENVN]);
	setG("locSuperUserSmtpPwd", $locSuperUserSmtpPwd[$ENVN]);
	setG("locSuperUserSmtpSrv", $locSuperUserSmtpSrv[$ENVN]);
	setG("locSuperUserSmtpPrt", $locSuperUserSmtpPrt[$ENVN]);
	if ($locSuperUserSmtpOptions[$ENVN])
		setG("locSuperUserSmtpOptions", $locSuperUserSmtpOptions[$ENVN]);
	setG("locUXenv", $locUXenv[$ENVN]);
	setG("urlLU", $urlLU[$ENVN]);
	setG("localDev", $localDev[$ENVN]);
	setG("katalon", $katalon[$ENVN]);
	setG("DBTZ", $locTimeZoneDb[$ENVN]);
	setG("locGrpISR", $locGrpISR[$configEnv]);
	if (!getG("LANG"))
		setG("LANG", getLangBrowser());
	if (!getG("DSCT"))
		setG("DSCT", "2");
	setG('locCacheSize', $locCacheSize[$ENVN]);
	setG('locEveSysServers', $locEveSysServers[$ENVN]);

	if (function_exists("memcache_add") && $locCacheSize[$ENVN] && !$MC) { // is going to be removed because of couchbase
		if (isset($locCacheSrvs[$ENVN]))
			$datI0022 = $locCacheSrvs[$ENVN];
		else
			$datI0022 = exec_sql("I0022", "read_I0022V02");
		if ($datI0022) {
			$MC = new Memcache;
			foreach ($datI0022 as $recI0022)
				$MC->addServer($recI0022["140"] ? $recI0022["140"] : $recI0022["10"], ini_get("memcache.default_port"));
		}
	}

	// EVE requests, we only need functions above and connection to DB
	if ($EVE)
		return;

	if (!empty($SITE))
		setG("SITE", TRUE);
	else
		setG("SITE", FALSE);
	if ($SESS == "NEW" || ($UTL && !$SESS)) {
		$parI0001 = array();
		$parI0001["INST"][0]["val1"] = getG("locInstalation");
		if ($datI0001 = exec_sql("I0001", "read_all", $parI0001)) if ($datI0001[0]["Codice_TimeZone"]) {
			setG("TIMEZONE", $datI0001[0]["Codice_TimeZoneD"]);
			date_default_timezone_set($datI0001[0]["Codice_TimeZoneD"]);
		} else {
			setG("TIMEZONE", "Europe/Lisbon");
			date_default_timezone_set("Europe/Lisbon");
		}
		if (load_class_GN("GnMsg", "MSG")) {
			if (!empty($_SERVER["HTTP_X_FORWARDED_FOR"]))
				setG("remIP", $_SERVER["HTTP_X_FORWARDED_FOR"]);
			else
				setG("remIP", $_SERVER["REMOTE_ADDR"]);
			if (!$userData = user_login($USRN, $MPWD, $PPWD, $UTL, $WEB)) {
				if ($NPWD) {
					send_msg("IN", "SEC", "3");
					$newPass = true;
				}
			} else {
				$USRN = $userData["Utente"];
				if ($userData["Lingua_codice"] != "")
					setG("LANG", $userData["Lingua_codice"]);
				if (!$RFS)
					setG("MNUI", $userData["Menu_codice"]);
				elseif ($userData["Menu_rf"]) {
					setG("MNUI", $userData["Menu_rf"]);
					setG("UMNU", true); // user menu
					// get all udt types for my device
					/*if ($DEVICE) {
						$parD0276=array();
						$parD0276["DISP"][0]["val1"] = $DEVICE;
						if ($datD0276 = exec_sql("D0276","read",$parD0276)) foreach($datD0276 as $recD0276) $arrUserUDLT[$recD0276["Udl_tipo"]] = $recD0276["Udl_classe"];
						setG("UDLD",$arrUserUDLT);
						setG("DEVICE",$DEVICE);
					}*/
				}
				if (!getG("MNUI") && !$UTL && !$RFS)
					send_msg("SY", "GEN", "50");
				elseif ($NPWD) {
					if ($RFS) {
						$LPWD = strlen($NPWD);
						if (!$NPWD || !$VPWD)
							send_msg("IN", "SEC", "1");
						elseif ($NPWD != $VPWD)
							send_msg("IN", "SEC", "2");
						else
							$NPWD = md5($NPWD);
					}
					if (!$userData["Password_no_rule"]) {
						load_class("FINSEC01", "FINSEC01");
						$FINSEC01->checkPassword("L", $USRN, $NPWD, $LPWD, $pwdData[0]["Password"], $pwdData[0]["Password_old"]);
					}
					if ($err)
						$newPass = true;
					else {
						$newPwd["fld"]["I0003"][0]["Utente"] = $USRN;
						$newPwd["fld"]["I0003"][0]["Password_tipo"] = "L";
						$newPwd["fld"]["I0003"][0]["Password"] = $NPWD;
						if ($pwdData[0]["Password"]) {
							if ($pwdData[0]["Password_old"])
								$newPwd["fld"]["I0003"][0]["Password_old"] = $pwdData[0]["Password"] . "|" . $pwdData[0]["Password_old"];
							else
								$newPwd["fld"]["I0003"][0]["Password_old"] = $pwdData[0]["Password"];
						}
						$newPwd["fld"]["I0003"][0]["Std_programma"] = "LOGIN";
						$newPwd["fld"]["I0003"][0]["Std_utente"] = $USRN;
						exec_sql("I0003", "modify", $newPwd);
					}
				} elseif (!empty($userData["nopass"])) {
					send_msg("SY", "SEC", "3");
					$newPass = true;
				}
			}
			if ($newPass && !$userData["Password_no_rule"]) {
				load_class("FINSEC01", "FINSEC01");
				$rules4password = $FINSEC01->valPassword($USRN, "L");
			} else
				$rules4password = false;
		}
		if (!$err) {

			setG("USRN", $USRN);
			setG("PRGR", $userData["Utente_adm"]);
			if ($userData["Utente_gruppo"])
				setG("USRG", $userData["Utente_gruppo"]);
			// if the user is an RF operator, set the site and team
			if ($RFS || $VCS) {
				$parD0260 = array();
				$parD0260["OPTR"][0]["val1"] = $USRN;
				if ($datD0260 = exec_sql("D0260", "read", $parD0260)) if ($datD0260[0]["Cambia_squadra_login"]) {
					$updW0004 = array();
					$updW0004["fld"]["W0004"][0] = array("Turnista" => $USRN, "Ente" => "", "Reparto" => "", "Squadra" => "");
					exec_sql("W0004", "update", $updW0004);
				} else {
					$parW0004 = array();
					$parW0004["OPTR"][0]["val1"] = $USRN;
					$parW0004["STD1"][0]["val1"] = 1;
					$datW0004 = exec_sql("W0004", "read_all", $parW0004);
				}
				$parD0258 = $arrTeams = array();
				$parD0258["OPTR"][0]["val1"] = $USRN;
				$parD0258["STD1"][0]["val1"] = 1;
				if ($datD0258 = exec_sql("D0258", "read_all", $parD0258))
					foreach ($datD0258 as $recD0258)
						$arrTeams[$recD0258["Ente"]][$recD0258["Squadra"]] = $recD0258["SquadraD"];

				if ((!$datW0004[0]["Ente"] || !$datW0004[0]["Squadra"]) && (isr_count($arrTeams) == 1 && isr_count($arrTeams[$recD0258["Ente"]]) == 1)) {
					// refresh the new operator data
					$par4refresh = array("swapTEAM" => true);
					$par4refresh["par"]["OPTR"][0]["val1"] = $USRN;
					$par4refresh["par"]["ENTI"][0]["val1"] = $recD0258["Ente"];
					$par4refresh["par"]["TEAM"][0]["val1"] = $recD0258["Squadra"];
					if ($optrData = exec_sql("FAPLOG801", "restoreOperatorData", $par4refresh)) {
						$datW0004 = $optrData;
						$datW0004[0]["EnteD"] = $recD0258["EnteD"];
						$datW0004[0]["SquadraD"] = $recD0258["SquadraD"];
					} else
						$arrTeams = array(); // something went wrong, so the team definition cannot be right
				} elseif (!$datW0004[0]["Ente"] && isr_count($arrTeams) == 1) {
					$datW0004[0]["Ente"] = $recD0258["Ente"];
					$datW0004[0]["EnteD"] = $recD0258["EnteD"];
				}
				// set operator menu, if it is not set yet
				if (!getG("MNUI")) {
					if ($datW0004[0]["Attivita_tutte"]) if ($optrMNU = unserialize($datW0004[0]["Attivita_tutte"])) {
						// get activities menus
						$mnuIN = false;
						foreach ($optrMNU as $recMNU)
							$mnuIN .= (($mnuIN) ? "','" : "('") . $recMNU;

						setG("DSCT", "3");
						$parD0261 = $arrOtherMNU = array();
						$parD0261["OPAC"][0]["val1"] = $mnuIN . "')";
						$parD0261["OPAC"][0]["sig1"] = "IN";
						if ($datD0261 = exec_sql("D0261", "read_D0261V01", $parD0261))
							foreach ($datD0261 as $recD0261) {
								if (!$recD0261["040D"]) {
									$parI0102 = array();
									$parI0102["MENU"][0]["val1"] = $recD0261["040"];
									if ($datI0102 = exec_sql("I0102", "read_I0102V01", $parI0102))
										$recD0261["040D"] = $datI0102[0]["02"];
								}
								if ($recD0261["010"] == $optrMNU[0])
									setG("MNUI", $recD0261["040"]);
								else
									$arrOtherMNU[$recD0261["010"]] = array("desc" => $recD0261["040D"], "menu_rf" => $recD0261["040"]);
							}
						setG("DSCT", "2");
					}
					// validate main menu, assigned to mai activity
					if (!getG("MNUI") && !$arrTeams)
						send_msg("SY", "GEN", "50");
					setG("XTR_MNU", $arrOtherMNU);
				}
				// set user data
				setG("RF_USRN", array("ENTI" => $datW0004[0]["Ente"], "ENTID" => $datW0004[0]["EnteD"], "TEAM" => $datW0004[0]["Squadra"], "TEAMD" => $datW0004[0]["SquadraD"], "ALLT" => $arrTeams));
				if ($DEVICE) {
					$parD0276 = array();
					$parD0276["DISP"][0]["val1"] = $DEVICE;
					if ($datD0276 = exec_sql("D0276", "read", $parD0276))
						foreach ($datD0276 as $recD0276)
							$arrUserUDLT[$recD0276["Udl_tipo"]] = $recD0276["Udl_classe"];
					setG("UDLD", $arrUserUDLT);
					setG("DEVICE", $DEVICE);
				}
			}
			if ($SCRW < 700)
				setG("SCRW", "1");
			elseif ($SCRW < 1000)
				setG("SCRW", "2");
			elseif ($SCRW < 1200)
				setG("SCRW", "3");
			else
				setG("SCRW", "4");
			// if (!$userData["Utente_adm"]) set_auth($USRN, $userData["Utente_gruppo"]);
			set_auth($USRN, $userData["Utente_gruppo"]);
			setG("STYLE", $userData["Style_sheet"]);
			$parSys["INST"][0]["val1"] = getG("locInstalation");
			if ($datSys = exec_sql("T0039", "read_T0039V01", $parSys))
				foreach ($datSys as $linSys)
					setG(($linSys["60"]) ? $linSys["60"] : $linSys["10"], ($linSys["50"]) ? $linSys["50"] : $linSys["20"]);
			setG("lastPost", 1);
			setG("fixDate", "0");
			setG("debug", false);
			setG("host", $_SERVER["SERVER_NAME"]);
			$_SESSION["allUm"] = array();
			if ($um = exec_sql("D0083", "read"))
				foreach ($um as $recUm)
					$_SESSION["allUm"][$recUm["Unita_misura"]] = $recUm["Unita_misura_decimal"];
			$parVRNT["INST"][0]["val1"] = getG("locInstalation");
			if ($datVRNT = exec_sql("ACVRNT50", "read", $parVRNT))
				foreach ($datVRNT as $recVRNT)
					$variants[$recVRNT["Report_codice"]] = $recVRNT["Variante_codice"];
			$parVRNT["USRG"][0]["val1"] = $userData["Utente_gruppo"];
			if ($datVRNT = exec_sql("ACVRNT30", "read", $parVRNT))
				foreach ($datVRNT as $recVRNT)
					$variants[$recVRNT["Report_codice"]] = $recVRNT["Variante_codice"];
			$parVRNT["USRN"][0]["val1"] = $USRN;
			if ($datVRNT = exec_sql("ACVRNT10", "read", $parVRNT))
				foreach ($datVRNT as $recVRNT)
					$variants[$recVRNT["Report_codice"]] = $recVRNT["Variante_codice"];
			if ($variants)
				foreach ($variants as $repC => $varC)
					$_SESSION["variants"][$repC]["varc"] = $varC;
			if ($datI0070 = exec_sql("I0070", "read"))
				foreach ($datI0070 as $recI0070)
					$_SESSION["localReports"][$recI0070["Report_codice"]] = TRUE;
			if ($datI0103 = exec_sql("I0103", "read"))
				foreach ($datI0103 as $recI0103)
					$_SESSION["localActions"][$recI0103["Azione_codice"]] = TRUE;
			$_SESSION["allWrkMsg"] = array();
			if ($d56 = exec_sql("I0056", "read_I0056V02"))
				foreach ($d56 as $r56)
					$_SESSION["allWrkMsg"][$r56["010"] . $r56["020"] . $r56["030"]] = TRUE;



			if ($SESS == "NEW") {
				//check if admin and liveUpgrade is on
				if (getG("activeLiveUpgrade") && (!$userData["Utente_adm"])) {
					send_msg("SY", "GEN", "77", array("p1" => getG("activeLiveUpgrade")));
				}

				$loginProblem = FALSE;
				destroy_all_sess($SSID);
				$sesPar = array();
				$sesPar["fld"]["I0011"][0]["Sessione_id"] = $SSID;
				$sesPar["fld"]["I0011"][0]["Sessione_numero"] = "0";
				$sesPar["fld"]["I0011"][0]["Sessione_ultima"] = time();
				$sesPar["fld"]["I0011"][0]["Sessione_dati"] = igbinary_serialize($_SESSION);
				if (exec_sql("I0011", "modify", $sesPar) == 0) {
					error_log("Unable to initialize session data for session $SSID");
					send_msg("SY", "GEN", "37");
					$loginProblem = TRUE;
				}
				if (!$loginProblem) {
					$usrDat["fld"]["I0044"][0]["Utente"] = $USRN;
					$usrDat["fld"]["I0044"][0]["Utente_login_ultimo"] = date("Ymd");
					exec_sql("I0044", "modify", $usrDat);
					$DB->set_trans("C");
					$openSess = true;
				}
			}
		}
		$SESS = "";
	} elseif (!empty($LOGOFF) || ($SESS && (empty($OPT) || $OPT != "D")) || $CHGMODIF) {
		global $saveGlobals;
		$saveGlobals = $_SESSION;
		// for voice wpms we force session id since client lost it
		if ($VCSSID)
			session_id($VCSSID);


		session_set_save_handler('wpms_session_open', 'wpms_session_close', 'wpms_session_read', 'wpms_session_write', 'wpms_session_destroy', 'wpms_session_gc');
		session_start();

		if (!$_SESSION)
			$_SESSION = $saveGlobals;
		// Check if screen size change ...
		if (!empty($SCRW)) {
			if ($SCRW < 700)
				setG("SCRW", "1");
			elseif ($SCRW < 1000)
				setG("SCRW", "2");
			elseif ($SCRW < 1200)
				setG("SCRW", "3");
			else
				setG("SCRW", "4");
		}

		checkmaxRfInactive($RFS);

		if (getG("activeLiveUpgrade") && !getG("PRGR")) {
			def_output("SY", "GEN", "77", array("p1" => getG("activeLiveUpgrade")));
			exit;
		}


		if (getG("TIMEZONE"))
			date_default_timezone_set(getG("TIMEZONE"));
		if (!empty($LOGOFF)) {
			if ($SESS) {
				destroy_sess($SSID, $SESS);
				$SESS = "";
				if ($MODI)
					chkStatusModif(array($MODI => true));
			} else
				destroy_all_sess($SSID);
			if ($LOGOFF == "VCS") {
				include("../WpmsVoice.php");
				return;
			}
			if ($LOGOFF != "RF")
				exit;
		} elseif (!empty($CHGMODIF)) {
			$NOMODIF = true;

			if ($MODI) {
				$chkMod[$MODI] = true;
				$prvMod = $MODI;
			}
			if ($CHGMODIF == "__NONE__")
				$MODI = "";
			else {
				$MODI = $CHGMODIF;
				$par["MODI"][0]["val1"] = $MODI;
				$par["XLOCK"][] = "T0085";
				$stat = exec_sql("T0085", "read", $par);
				if ($stat[0]["Status_applicativo"] != "10" && $stat[0]["Status_applicativo"] != "20")
					exit;
			}
			$sesDat["fld"]["I0011"][0]["Sessione_id"] = $SSID;
			$sesDat["fld"]["I0011"][0]["Sessione_numero"] = $SESS;
			$sesDat["fld"]["I0011"][0]["Modificazione"] = $MODI;
			exec_sql("I0011", "update", $sesDat);
			if ($MODI)
				$chkMod[$MODI] = true;
			chkStatusModif($chkMod);
			exit;
		} else
			load_class_GN("GnMsg", "MSG");
		if ($dbgOn)
			send_msg("SY", "OBJ", 19);
		if (!empty($PREFS)) {
			$usr["fld"]["I0002"][0]["Utente"] = getG("USRN");
			$usr["fld"]["I0002"][0]["Preferenze"] = $PREFS;
			if (exec_sql("I0002", "update", $usr) == 1) {
				$DB->set_trans("C");
				def_output("SY", "GEN", "21");
			} else
				def_output("SY", "GEN", "23");
		}
		if ($SESS != "99")
			setG("locSession", $SSID . $SESS);
		else
			$SESS = "";
	}
}

//TESTE - Voice don't need token
if ($RFS && !$VCS)
	checkToken($RFS, $SSID, $SESS);

// start profiler
$xhProOn = false;
if (!empty($POSTVALUE) && function_exists("xhprof_enable") && $dbgP = getG("DBGP")) {
	if (($dbgP[0] == getG("ACTI") && $POSTACTION == 9) || ($dbgP[0] == $POSTVALUE && $POSTACTION == 1)) {
		$xhProOn = true;
		xhprof_enable(XHPROF_FLAGS_CPU + XHPROF_FLAGS_MEMORY, array("ignored_functions" => array("xhprof_disable")));
	}
}

if (!$err && !empty($NEXT))
	include($locDirInc . $NEXT);
elseif (!$UTL && !$RFS && !$SCRW && $repMessages[0]["text"] && $repMessages[0]["type"] != "Q")
	def_output();

// stop profiler
if ($xhProOn) {
	$xhProData = xhprof_disable();
	$xhProData = igbinary_serialize($xhProData);
	$outI0087["fld"]["I0087"][0] = array("Pid" => getmypid(), "Timestamp_profiler" => str_pad(str_replace(".", "", microtime(true)), 14, "0", STR_PAD_RIGHT), "Timestamp_info" => date("YmdHis"), "Utente" => getG("USRN"), "Profiler_processo" => $dbgP[1], "Azione_codice" => $dbgP[0], "Profiler_sessione" => $xhProData);
	exec_sql("I0087", "write", $outI0087);
	unset($xhProData, $outI0087, $dbgP);
}
if (getG("sysLog") || getG("DBGP"))
	writeWPMSstat();

if (!$err && getG("cmdBatch")) {
	$allCmds = getG("cmdBatch");
	setG("cmdBatch", false);
	foreach ($allCmds as $cmd)
		system($cmd);
} else {
	if ($SESS) {
		ob_end_flush();
		flush();
	}
}
?>