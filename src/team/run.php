<?php
////////////////////////////////////////////////////////////////////////////////
//BOCA Online Contest Administrator
//    Copyright (C) 2003-2013 by BOCA Development Team (bocasystem@gmail.com)
//
//    This program is free software: you can redistribute it and/or modify
//    it under the terms of the GNU General Public License as published by
//    the Free Software Foundation, either version 3 of the License, or
//    (at your option) any later version.
//
//    This program is distributed in the hope that it will be useful,
//    but WITHOUT ANY WARRANTY; without even the implied warranty of
//    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//    GNU General Public License for more details.
//    You should have received a copy of the GNU General Public License
//    along with this program.  If not, see <http://www.gnu.org/licenses/>.
////////////////////////////////////////////////////////////////////////////////
require('header.php');
$ds = DIRECTORY_SEPARATOR;
if($ds=="") $ds = "/";

if (isset($_POST["problem"]) && isset($_POST["language"]) &&
    ((isset($_FILES["sourcefile"]) && isset($_POST["Submit"]) && $_FILES["sourcefile"]["name"]!="") || (isset($_POST["data"]) && isset($_POST["name"])))) {
  if ((isset($_POST["confirmation"]) && $_POST["confirmation"] == "confirm") || (isset($_POST["data"]) && isset($_POST["name"]))) {
    if(($ct = DBContestInfo($_SESSION["usertable"]["contestnumber"])) == null) {
      if(isset($_POST['name']) && $_POST['name'] != '') {
	echo "\nRESULT: CONTEST NOT FOUND";
	exit;
      }
      ForceLoad("../index.php");
    }
    $prob = myhtmlspecialchars($_POST["problem"]);
    $lang = myhtmlspecialchars($_POST["language"]);

    if(!is_numeric($prob)) {
      $probs = DBGetProblems($_SESSION["usertable"]["contestnumber"],$_SESSION["usertable"]["usertype"]=='judge');
      $i = 0;
      $ss = "";
      for (;$i<count($probs);$i++) {
	if($probs[$i]["problem"]==$prob) {
	  $prob = $probs[$i]["number"];
	  break;
	}
	$ss .= $probs[$i]["problem"] . " ";
      }
      if($i >= count($probs)) {
	echo "\nRESULT: INVALID PROBLEM (options are: " . $ss . ")";
	exit;
      }
    }
    if(!is_numeric($lang)) {
      $langs = DBGetLanguages($_SESSION["usertable"]["contestnumber"]);
      $i = 0;
      $ss = "";
      for (;$i<count($langs);$i++) {
	if($langs[$i]["name"]==$lang) {
	  $lang = $langs[$i]["number"];
	  break;
	}
	$ss .= $langs[$i]["name"] . " ";
      }
      if($i >= count($langs)) {
	echo "\nRESULT: INVALID LANGUAGE (options are: " . $ss . ")";
	exit;
      }
    }
    $linesubmission = @file_get_contents($_SESSION["locr"] . $ds . "private" . $ds . 'run-using-command.config');
    if(trim($linesubmission) =='1') {
      if(!isset($_POST['name']) || $_POST['name'] == '') {
	echo "\nINVALID REQUEST";
	ForceLoad("../index.php");
	exit;
      }

    }
    if(isset($_POST['name']) && $_POST['name'] != '') {
      $runsfiles = $_SESSION["locr"] . $ds . "private" . $ds . 'runsfiles';
      @mkdir($runsfiles,0770);
      $temp = tempnam($runsfiles,"bkp-");
      $fout = fopen($temp,"wb");
      fwrite($fout,base64_decode($_POST['data']));
      fclose($fout);
      $size=filesize($temp);
      $name=$_POST['name'];
      if ($size > $ct["contestmaxfilesize"] || strlen($name)>100 || strlen($name)<1) {
	echo "\nRESULT: SUBMITTED FILE (OR NAME) TOO LARGE";
	exit;
      }
    } else {			
      $type=myhtmlspecialchars($_FILES["sourcefile"]["type"]);
      $size=myhtmlspecialchars($_FILES["sourcefile"]["size"]);
      $name=myhtmlspecialchars($_FILES["sourcefile"]["name"]);
      $temp=myhtmlspecialchars($_FILES["sourcefile"]["tmp_name"]);

      if ($size > $ct["contestmaxfilesize"]) {
	LOGLevel("User {$_SESSION["usertable"]["username"]} tried to submit file " .
		 "$name with $size bytes ({$ct["contestmaxfilesize"]} max allowed).", 1);
	MSGError("File size exceeds the limit allowed.");
	ForceLoad($runteam);
      }
      if (!is_uploaded_file($temp) || strlen($name)>100) {
	IntrusionNotify("file upload problem.");
	ForceLoad("../index.php");
      }
    }
    if(strpos($name,' ') === true || strpos($temp,' ') === true || strpos($name,'/') === true || strpos($temp,'/') === true ||
       strpos($name,'`') === true || strpos($temp,'`') === true || strpos($name,'\'') === true || strpos($temp,'\'') === true ||
       strpos($name, "\"") === true || strpos($temp, "\"") === true || strpos($name,'$') === true || strpos($temp,'$') === true) {
      if(isset($_POST['name']) && $_POST['name'] != '') {
	echo "\nRESULT: FILE NAME PROBLEM (EG CANNOT HAVE SPACES)";
	exit;
      }
      MSGError("File name cannot contain spaces.");
      ForceLoad($runteam);		
    }

    if(isset($_POST['pastcode']) && $_POST['pastcode'] != '')
      $shaf = myhtmlspecialchars($_POST["pastcode"]);
    else $shaf = @sha1_file($temp);

    if(@rename($temp, $temp . "." . sanitizeFilename($shaf)))
      $temp = $temp . "." . sanitizeFilename($shaf);

    //		$ac=array('contest','site','user','problem','lang','filename','filepath');
    //		$ac1=array('runnumber','rundate','rundatediff','rundatediffans','runanswer','runstatus','runjudge','runjudgesite',
    //			   'runjudge1','runjudgesite1','runanswer1','runjudge2','runjudgesite2','runanswer2',
    //			   'autoip','autobegindate','autoenddate','autoanswer','autostdout','autostderr','updatetime');
    $param = array('contest'=>$_SESSION["usertable"]["contestnumber"],
		   'site'=>$_SESSION["usertable"]["usersitenumber"],
		   'user'=>  $_SESSION["usertable"]["usernumber"],
		   'problem'=>$prob,
		   'lang'=>$lang,
		   'filename'=>$name,
		   'filepath'=>$temp);
    $compv = "web_" . sanitizeFilename(getIP()) . "_" . $_SESSION["usertable"]["contestnumber"].'_'.$_SESSION["usertable"]["usersitenumber"].'_'.$_SESSION["usertable"]["usernumber"];
    if(trim($linesubmission) =='1') {
      if(isset($_POST['comp']) && $_POST['comp'] != '') $compv=substr(trim(sanitizeFilename($_POST['comp'])),0,150);
      else {
	echo "\nRESULT: ERROR COMPUTER KEY";
	exit;
      }
    }
    $name = str_replace("-", "_", $name);
    $verify = $compv . '-'. $shaf . '-' . $name . '-'. $prob . '-' . $lang . '-' . 
      $_SESSION["usertable"]["contestnumber"].'-'.$_SESSION["usertable"]["usersitenumber"].'-'.$_SESSION["usertable"]["usernumber"];
    
    $pasthash=""; if(isset($_POST["pasthash"])) $pasthash=myhtmlspecialchars($_POST["pasthash"]);
    $pastvalhash=''; if(isset($_POST["pastvalhash"])) $pastvalhash=myhtmlspecialchars($_POST["pastvalhash"]);
    $pastval=''; if(isset($_POST["pastval"])) $pastval=myhtmlspecialchars($_POST["pastval"]);
    $pastabs=''; if(isset($_POST["pastabs"])) $pastabs=myhtmlspecialchars($_POST["pastabs"]);
    $verify1 = $pasthash . '-' . $pastvalhash .'-'. $pastval .'-'. $pastabs .'-'. $compv . '-'. $shaf . '-' . $name . '-'. $prob . '-' . $lang . '-' .
      $_SESSION["usertable"]["contestnumber"].'-'.$_SESSION["usertable"]["usersitenumber"].'-'.$_SESSION["usertable"]["usernumber"];

    @mkdir($_SESSION["locr"] . $ds . "private" . $ds . 'runslog',0770);
    $fcname = $_SESSION["locr"] . $ds . "private" . $ds . 'runslog' . $ds . 'runs-submitted-' . $_SESSION["usertable"]["contestnumber"].'-'.
      $_SESSION["usertable"]["usersitenumber"].'-'.$_SESSION["usertable"]["usernumber"];
    @mkdir($_SESSION["locr"] . $ds . "private" . $ds . 'comp',0770);
    $fcnamex = $_SESSION["locr"] . $ds . "private" . $ds . 'comp' . $ds . $compv . ".comp";
    $prevcomp = @file_get_contents($fcnamex);
    
    if($prevcomp === false || trim($prevcomp) == '') {
      @file_put_contents($fcnamex, $_SESSION["usertable"]["contestnumber"].'-'.$_SESSION["usertable"]["usersitenumber"].'-'.$_SESSION["usertable"]["usernumber"], LOCK_EX);
    } else {
      if(trim($prevcomp) != $_SESSION["usertable"]["contestnumber"].'-'.$_SESSION["usertable"]["usersitenumber"].'-'.$_SESSION["usertable"]["usernumber"]) {
	@file_put_contents($fcname . ".try", $verify1 . "-UNAUTH\n", FILE_APPEND | LOCK_EX);
	if(isset($_POST['name']) && $_POST['name'] != '') {
	  echo "\nRESULT: UNAUTHORIZED COMPUTER";
	  exit;
	}
	MSGError("Could not save computer info");
      }
    }
    
    //@file_put_contents($fcname . ".try", $verify1 . "\n", FILE_APPEND | LOCK_EX);
    $codes = @file($fcname . ".txt",FILE_IGNORE_NEW_LINES);
    if(is_array($codes) && in_array($verify,$codes)) {
      @file_put_contents($fcname . ".try", $verify1 . "-ALREADY\n", FILE_APPEND | LOCK_EX);
      if(isset($_POST['name']) && $_POST['name'] != '') {
	echo "\nRESULT: SAME FILE ALREADY SUBMITTED FOR THIS PROB/LANG";
	exit;
      }
      MSGError("Same file already submitted for this problem and language"); ForceLoad($runteam);
    } 
    
    if(isset($_POST['pastcode']) && $_POST['pastcode'] != '') {
      $pastcode = myhtmlspecialchars($_POST["pastcode"]);
      if(isset($_POST["pasthash"]) && isset($_POST["pastval"])) {
	$pasthash = myhtmlspecialchars($_POST["pasthash"]);
	$pastvalhash = myhtmlspecialchars($_POST["pastvalhash"]);
	$pastval = myhtmlspecialchars($_POST["pastval"]);
	$pastabs = myhtmlspecialchars($_POST["pastabs"]);
	if(is_readable($_SESSION["locr"] . $ds . "private" . $ds . 'run-past.config')) {
	  $pcodes = @file($_SESSION["locr"] . $ds . "private" . $ds . 'run-past.config');
	  $pastsubmission = array_map(function($element){ $sp=explode(' ',$element,4); return trim($sp[2]); }, $pcodes);
	  $key=-1;
	  
	  for($hh=0; $hh < count($pastsubmission); $hh++) 
	    if(myhash($pastsubmission[$hh] . $pastcode . $compv . $pastval) == $pastvalhash) { $key = $hh; break; }
	  if($key < 0) {
	    //if(($key=array_search($pastvalhash, $pastsubmission))===false) {
	    //	$pastsubmission = array_map(function($element){ $sp=explode(' ',$element,4); return myhash(trim($sp[2]) . trim($pastcode) . trim($pastabs)); }, $pcodes);
	    //	if(($key=array_search($pasthash, $pastsubmission))===false) {
	    echo "\nRESULT: INVALID SUBMISSION CODE (0)";
	    exit;
	    //	}
	  }
	} else {
	  @file_put_contents($fcname . ".try", $verify1 . "-BADCODE1\n", FILE_APPEND | LOCK_EX);
	  echo "\nRESULT: INVALID SUBMISSION CODE (1)";
	  exit;
	}
      } else {
	@file_put_contents($fcname . ".try", $verify1 . "-BADCODE2\n", FILE_APPEND | LOCK_EX);
	echo "\nRESULT: INVALID SUBMISSION CODE (2)";
	exit;
      }
      // cassio: being restrict with respect to internet connection in the client
      $pok = 'OK';
      if(!isset($_POST['oknet']) || !is_numeric($_POST['oknet']) || $_POST['oknet']>0) {
	$pok = 'OKNET';
	if(true) {
	  @file_put_contents($fcname . ".try", $verify1 . "-NET" . $_POST['oknet'] . "\n", FILE_APPEND | LOCK_EX);
	  echo "\nRESULT: INVALID SUBMISSION CODE (3)";
	  exit;
	}
      }
      if($pastval > 0) {
	$param['rundate']=time() - $pastval;
	$b = DBSiteInfo($_SESSION["usertable"]["contestnumber"], $_SESSION["usertable"]["usersitenumber"]);
	$dif = $b["currenttime"]; 
	$param['rundatediff']=$dif - $pastval;
      }
      
      $tardes = array_map(function($element){ $sp=explode(' ',$element,4); if(count($sp)>3) return 60*trim($sp[3]); return 0; }, $pcodes);
      ///////CASO DE COMECAR MAIS TARDE NO CENTRALIZADO
      if($key >= 0 && $tardes[$key] > 0) { //substr($_SESSION["usertable"]["username"],0,3) == 'XXX') {
	$param['rundate']=$param['rundate'] - $tardes[$key]; // 60*10 = 10 minutos
	$param['rundatediff']=$param['rundatediff'] - $tardes[$key];
      }
      $retv = DBNewRun ($param);
      if($retv == 2) {
	if(isset($_POST['oknet']) && is_numeric($_POST['oknet']) && $_POST['oknet']>0) $pok .= $_POST['oknet'];
	@file_put_contents($fcname . ".try", $verify1 . "-" . $pok . "-" . $param['rundatediff'] . "-" . $param['rundate'] . "-" . $b["currenttime"] . "\n", FILE_APPEND | LOCK_EX);
	@file_put_contents($fcname . ".txt", $verify . "\n", FILE_APPEND | LOCK_EX);
	echo "\nRESULT: RUN SUBMITTED SUCCESSFULLY ($pastval)";
      } else {
	if($retv == 0) {
	  echo "\nRESULT: CONTEST NOT RUNNING";
	  @file_put_contents($fcname . ".try", $verify1 . "-NOTRUNNING\n", FILE_APPEND | LOCK_EX);
	}			else {
	  echo "\nRESULT: UNKNOWN PROBLEM";
	  @file_put_contents($fcname . ".try", $verify1 . "-UNKNOWN\n", FILE_APPEND | LOCK_EX);
	}
      }
      exit;
    }
    if(trim($linesubmission) =='1') {
      @file_put_contents($fcname . ".try", $verify1 . "-BADCALL\n", FILE_APPEND | LOCK_EX);
      echo "\nINVALID REQUEST";
      exit;
    }
		
    ///////CASO DE COMECAR MAIS TARDE NO CENTRALIZADO
    if(substr($_SESSION["usertable"]["username"],0,3) == 'XXX') {
      $param['rundate']=$param['rundate'] - 60*10; // 10 minutos
      $param['rundatediff']=$param['rundatediff'] - 60*10;
    }
    $retv = DBNewRun ($param);
    if($retv==2)
      @file_put_contents($fcname . ".txt", $verify . "\n", FILE_APPEND | LOCK_EX);
    if(isset($_POST['name']) && $_POST['name'] != '') {
      if($retv == 2) {
	@file_put_contents($fcname . ".try", $verify1 . "-OK\n", FILE_APPEND | LOCK_EX); 
	echo "\nRESULT: RUN SUBMITTED SUCCESSFULLY";
      }			
      else {
	if($retv == 0) {
	  @file_put_contents($fcname . ".try", $verify1 . "-NOTRUNNING2\n", FILE_APPEND | LOCK_EX);
	  echo "\nRESULT: CONTEST NOT RUNNING"; 
	}
	else {
	  @file_put_contents($fcname . ".try", $verify1 . "-UNKNOWN\n", FILE_APPEND | LOCK_EX);
	  echo "\nRESULT: UNKNOWN PROBLEM";
	}
      }
      exit;
    }
    $_SESSION['forceredo']=true;
  }
  ForceLoad($runteam);
}
if(isset($_POST['name']) && $_POST['name'] != '') {
  echo "RESULT: PARAMETERS MISSING";
  exit;
}

$runtmp = $_SESSION["locr"] . $ds . "private" . $ds . "runtmp" . $ds . "run-contest" . $_SESSION["usertable"]["contestnumber"] . 
  "-site". $_SESSION["usertable"]["usersitenumber"] . "-user" . $_SESSION["usertable"]["usernumber"] . ".php";
$redo = TRUE;
if(!isset($_SESSION['forceredo']) || $_SESSION['forceredo']==false) {
  $actualdelay = 15;
  if(file_exists($runtmp)) {
    if(isset($strtmp) || (($strtmp = file_get_contents($runtmp,FALSE,NULL,0,1000000)) !== FALSE)) {
      list($d) = sscanf($strtmp,"%*s %d");
      if($d > time() - $actualdelay) {
	$conf=globalconf();
	if(isset($conf['doenc']) && $conf['doenc'])
	  $strtmp = decryptData(substr($strtmp,strpos($strtmp,"\n")+1),$conf["key"],'runtmp');
	else $strtmp = substr($strtmp,strpos($strtmp,"\n")+1);
	if($strtmp !== false)
	  $redo = FALSE;
      }
    }
  }
}
if($redo) {
  $_SESSION["popuptime"] = time();
  $_SESSION['forceredo']=false;
  if(($st = DBSiteInfo($_SESSION["usertable"]["contestnumber"],$_SESSION["usertable"]["usersitenumber"])) == null)
    ForceLoad("../index.php");
  $strtmp="<br>\n<table width=\"100%\" border=1>\n <tr>\n  <td><b>Run #</b></td>\n<td><b>Time</b></td>\n".
    "  <td><b>Problem</b></td>\n  <td><b>Language</b></td>\n  <td><b>Answer</b></td>\n  <td><b>File</b></td>\n </tr>\n";
  $strcolors = "0";
  $cmlinesubmission = @file_get_contents($_SESSION["locr"] . $ds . "private" . $ds . 'run-using-command.config');
  $cmeditor = (trim($cmlinesubmission) != '1'); // BOCA-CM: no web editor in command-line submission mode
  $run = DBUserRuns($_SESSION["usertable"]["contestnumber"],
		    $_SESSION["usertable"]["usersitenumber"],
		    $_SESSION["usertable"]["usernumber"]);
  for ($i=0; $i<count($run); $i++) {
    $strtmp .= " <tr>\n";
    $strtmp .= "  <td nowrap>" . $run[$i]["number"] . "</td>\n";
    $strtmp .= "  <td nowrap>" . dateconvminutes($run[$i]["timestamp"]) . "</td>\n";
    $strtmp .= "  <td nowrap>" . $run[$i]["problem"] . "</td>\n";
    $strtmp .= "  <td nowrap>" . $run[$i]["language"] . "</td>\n";
    //  $strtmp .= "  <td nowrap>" . $run[$i]["status"] . "</td>\n";
    if (trim($run[$i]["answer"]) == "") { 
      $run[$i]["answer"] = "Not answered yet";
      $strtmp .= "  <td>Not answered yet"; 
    }
    else {
      $strtmp .= "  <td>" . $run[$i]["answer"];
      if(false) {
	if(strpos($run[$i]["autoanswer"],"OKs") > 0)
	  $strtmp .= ' ' . substr($run[$i]["autoanswer"],strrpos($run[$i]["autoanswer"],'('));
      }
      if($run[$i]['yes']=='t') {
	$strtmp .= " <img alt=\"".$run[$i]["colorname"]."\" width=\"15\" ".
	  "src=\"" . balloonurl($run[$i]["color"]) ."\" />";
	$strcolors .= "\t" . $run[$i]["colorname"] . "\t" . $run[$i]["color"];
      }
    }
    $strtmp .= "</td>\n";
    $strtmp .= "<td nowrap><a href=\"../filedownload.php?" . filedownload($run[$i]["oid"],$run[$i]["filename"]) . "\">";
    $strtmp .= $run[$i]["filename"] . "</a>";
    // BOCA-CM: load this run's code into the editor
    if($cmeditor) $strtmp .= " <a href=\"#bocacm\" class=\"bocacm-openrun\" title=\"Open this code in the editor\"" .
      " data-url=\"" . htmlspecialchars("../filedownload.php?" . filedownload($run[$i]["oid"],$run[$i]["filename"]), ENT_QUOTES) . "\"" .
      " data-problem=\"" . htmlspecialchars($run[$i]["problem"], ENT_QUOTES) . "\"" .
      " data-language=\"" . htmlspecialchars($run[$i]["language"], ENT_QUOTES) . "\"" .
      " data-filename=\"" . htmlspecialchars($run[$i]["filename"], ENT_QUOTES) . "\"" .
      " onclick=\"return window.bocacmOpenRun ? bocacmOpenRun(this) : false;\">[edit]</a>";

    $strtmp .= "</td>\n";
		
    $strtmp .= " </tr>\n";
  }
  $strtmp .= "</table>";
  if (count($run) == 0) $strtmp .= "<br><center><b><font color=\"#ff0000\">NO RUNS AVAILABLE</font></b></center>";
  $linesubmission = @file_get_contents($_SESSION["locr"] . $ds . "private" . $ds . 'run-using-command.config');
  if(trim($linesubmission) == '1') {
    $strtmp .= "<br><br><center><b>To submit a program, use the command-line tool:</b>\n<br>".
      "<pre>boca-submit-run USER PASSWORD PROBLEM LANGUAGE FILE</pre><br>".
      "where USER is your username, PASSWORD is your password, <br>".
      "PROBLEM is one of { ";

    $prob = DBGetProblems($_SESSION["usertable"]["contestnumber"],$_SESSION["usertable"]["usertype"]=='judge');
    for ($i=0;$i<count($prob);$i++)
      $strtmp .= $prob[$i]["problem"] . " ";
    $strtmp .= "} and<br>LANGUAGE is one of { ";
    $lang = DBGetLanguages($_SESSION["usertable"]["contestnumber"]);
    for ($i=0;$i<count($lang);$i++)
      $strtmp .= $lang[$i]["name"] . " ";
    $strtmp .= "}<br>FILE is your submission file<br><br>\n";
  } else {

    // BOCA-CM: code editor with tabs. The editor builds the source file in the browser and
    // submits it through the same multipart form (field "sourcefile") as before, so the
    // server-side submission handling above is unchanged.
    $prob = DBGetProblems($_SESSION["usertable"]["contestnumber"],$_SESSION["usertable"]["usertype"]=='judge');
    $lang = DBGetLanguages($_SESSION["usertable"]["contestnumber"]);
    $cmprobs = array();
    for ($i=0;$i<count($prob);$i++)
      $cmprobs[] = array('number'=>$prob[$i]["number"], 'name'=>$prob[$i]["problem"],
			 'basefilename'=>$prob[$i]["basefilename"]);
    $cmlangs = array();
    for ($i=0;$i<count($lang);$i++)
      $cmlangs[] = array('number'=>$lang[$i]["number"], 'name'=>$lang[$i]["name"],
			 'extension'=>$lang[$i]["extension"]);
    $cmconf = array('storageKey'=>$_SESSION["usertable"]["contestnumber"].'-'.$_SESSION["usertable"]["usersitenumber"].
		    '-'.$_SESSION["usertable"]["usernumber"],
		    'problems'=>$cmprobs, 'languages'=>$cmlangs);
    $strtmp .= "<br><br><center><b>To submit a program, write or open it in the editor below, choose the problem and language, and click Send:</b></center><br>\n".
      "<link rel=\"stylesheet\" href=\"../codemirror/lib/codemirror.css?v=" . $BOCACMVERSION . "\" type=\"text/css\">\n".
      "<link rel=\"stylesheet\" href=\"editor.css?v=" . $BOCACMVERSION . "\" type=\"text/css\">\n".
      "<div id=\"bocacm\">\n".
      "  <div id=\"bocacm-warning\" class=\"bocacm-warning\" style=\"display:none\">The editor seems to be open in another browser tab or window. ".
      "Use only one, otherwise one of them may overwrite the code of the other.</div>\n".
      "  <div id=\"bocacm-nostorage\" class=\"bocacm-warning\" style=\"display:none\">Your browser is not allowing this page to store data: ".
      "the code in the editor will be lost when the page reloads. Keep a copy of your code in a file.</div>\n".
      "  <div id=\"bocacm-tabs\"></div>\n".
      "  <div id=\"bocacm-bar\">\n".
      "    <label>Problem: <select id=\"bocacm-problem\"></select></label>\n".
      "    <label>Language: <select id=\"bocacm-language\"></select></label>\n".
      "    <label>Open file: <input type=\"file\" id=\"bocacm-open\"></label>\n".
      "  </div>\n".
      "  <div id=\"bocacm-editor\"></div>\n".
      "  <noscript><p class=\"bocacm-warning\">The editor needs JavaScript.</p></noscript>\n".
      "  <div id=\"bocacm-actions\"><input type=\"button\" id=\"bocacm-send\" value=\"Send\"></div>\n".
      "</div>\n".
      "<form name=\"form1\" enctype=\"multipart/form-data\" method=\"post\" action=\"". $runteam ."\" class=\"bocacm-hidden\">\n".
      "  <input type=hidden name=\"confirmation\" value=\"noconfirm\" />\n".
      "  <input type=hidden name=\"problem\" value=\"-1\" />\n".
      "  <input type=hidden name=\"language\" value=\"-1\" />\n".
      "  <input type=hidden name=\"Submit\" value=\"Send\" />\n".
      "  <input type=\"file\" name=\"sourcefile\" />\n".
      "</form>\n".
      "<script type=\"text/javascript\">window.BOCACM_CONFIG = " .
      json_encode($cmconf, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) . ";</script>\n".
      "<script type=\"text/javascript\" src=\"../codemirror/lib/codemirror.js?v=" . $BOCACMVERSION . "\"></script>\n".
      "<script type=\"text/javascript\" src=\"../codemirror/mode/clike/clike.js?v=" . $BOCACMVERSION . "\"></script>\n".
      "<script type=\"text/javascript\" src=\"../codemirror/mode/python/python.js?v=" . $BOCACMVERSION . "\"></script>\n".
      "<script type=\"text/javascript\" src=\"../codemirror/addon/edit/matchbrackets.js?v=" . $BOCACMVERSION . "\"></script>\n".
      "<script type=\"text/javascript\" src=\"../codemirror/addon/edit/closebrackets.js?v=" . $BOCACMVERSION . "\"></script>\n".
      "<script type=\"text/javascript\" src=\"../codemirror/addon/selection/active-line.js?v=" . $BOCACMVERSION . "\"></script>\n".
      "<script type=\"text/javascript\" src=\"editor.js?v=" . $BOCACMVERSION . "\"></script>\n";
  }
  $conf=globalconf();
  if(isset($conf['doenc']) && $conf['doenc'])
    $strtmp1 = "<!-- " . time() . " --> <?php exit; ?>\t" . encryptData($strcolors,$conf["key"],false) . "\n" . encryptData($strtmp,$conf["key"],false);
  else
    $strtmp1 = "<!-- " . time() . " --> <?php exit; ?>\t" . $strcolors . "\n" . $strtmp;
  $randnum = session_id() . "_" . rand();
  if(file_put_contents($runtmp . "_" . $randnum, $strtmp1,LOCK_EX)===FALSE) {
    if(!isset($_SESSION['writewarn'])) {
      LOGError("Cannot write to the user-run cache file $runtmp -- performance might be compromised");
      $_SESSION['writewarn']=true;
    }
  }
  @rename($runtmp . "_" . $randnum, $runtmp);
}
echo $strtmp;
?>


</body>
</html>
