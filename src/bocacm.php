<?php
////////////////////////////////////////////////////////////////////////////////
// BOCA-CM helpers
//
//    This program is free software: you can redistribute it and/or modify
//    it under the terms of the GNU General Public License as published by
//    the Free Software Foundation, either version 3 of the License, or
//    (at your option) any later version.
////////////////////////////////////////////////////////////////////////////////

// The autojudge prefixes its answer with "[passed/total] " (see private/autojudging.php).
// Returns array(passed, total), or null when the answer has no test count (e.g. compilation
// error, or a run judged by an autojudge without BOCA-CM).
function bocacmParseTests($autoanswer) {
  if(preg_match('/^\[(\d+)\/(\d+)\]/', (string) $autoanswer, $m))
    return array((int) $m[1], (int) $m[2]);
  return null;
}

// "Show number of passed tests to teams" is stored per contest in a small file in private/
// (no database change needed).
function bocacmShowTestsFile($contest) {
  return dirname(__FILE__) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR .
    'bocacm-showtests-contest' . ((int) $contest) . '.config';
}
function bocacmShowTests($contest) {
  return trim((string) @file_get_contents(bocacmShowTestsFile($contest))) == '1';
}
function bocacmSetShowTests($contest, $on) {
  return @file_put_contents(bocacmShowTestsFile($contest), $on ? "1\n" : "0\n", LOCK_EX) !== false;
}

// Mask of "release every answer automatically" for a problem (same rule as admin/problem.php).
function bocacmAllAnswersMask($contest) {
  $all = DBGetAnswers($contest);
  $mask = 0;
  for ($g = 0; $g < count($all); $g++) {
    if ($all[$g]['fake'] == 't') continue;
    $mask |= pow(2, $g);
  }
  return $mask;
}

// Automatic release of autojudge answers needs BOTH the site option (Site page, "Autojudge")
// and, for each problem, the answers selected in the Problems page. Returns
// array('site' => bool, 'problems' => array(name => 'all'|'custom'|'none')).
function bocacmAutoReleaseStatus($contest, $site) {
  $st = DBSiteInfo($contest, $site);
  $mask = bocacmAllAnswersMask($contest);
  $probs = array();
  $c = DBConnect();
  $r = DBExec($c, "select problemname, problemautojudge from problemtable where contestnumber=" . ((int) $contest) .
	      " and fake!='t' and problemfullname !~ '(DEL)' order by problemnumber", "bocacmAutoReleaseStatus(problems)");
  $n = DBnlines($r);
  for ($i = 0; $i < $n; $i++) {
    $a = DBRow($r, $i);
    $v = (int) $a['problemautojudge'];
    $probs[$a['problemname']] = ($v == $mask) ? 'all' : (($v == 0) ? 'none' : 'custom');
  }
  return array('site' => ($st != null && $st['siteautojudge'] == 't'), 'problems' => $probs);
}

// On: enable the site option and release every answer for every problem.
// Off: disable the site option only (per-problem choices are kept for next time).
function bocacmSetAutoRelease($contest, $site, $on) {
  $contest = (int) $contest; $site = (int) $site; $t = time();
  $c = DBConnect();
  DBExec($c, "begin work", "bocacmSetAutoRelease(begin)");
  DBExec($c, "update sitetable set siteautojudge='" . ($on ? 't' : 'f') . "', updatetime=$t " .
	 "where contestnumber=$contest and sitenumber=$site", "bocacmSetAutoRelease(site)");
  if ($on) {
    $mask = bocacmAllAnswersMask($contest);
    DBExec($c, "update problemtable set problemautojudge=$mask, updatetime=$t " .
	   "where contestnumber=$contest and fake!='t'", "bocacmSetAutoRelease(problems)");
  }
  DBExec($c, "commit work", "bocacmSetAutoRelease(commit)");
  return true;
}
?>
