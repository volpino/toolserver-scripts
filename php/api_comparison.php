<?php

/* Thanks to X! aka soxred93.
 * This code by him with some little modifications
 * I think that it requires some style revison though */

//Set error stuff
error_reporting(E_ALL);
ini_set("display_errors", 1);
ini_set("memory_limit", '64M');
ini_set('user_agent', 'SoNet BOT');

//Requires
require_once('/home/sonet/Peachy/Init.php');
require_once('/home/sonet/database.inc');

$time_start = microtime(true);

$pgVerbose = array();
$pgHooks['StartLogin'][] = 'fixlogin';

//If there is a failure, do it pretty.
function toDie($msg) {
    $data = array("error" => $msg);
    print_result($data);
    die();
}

function print_result($data) {
    global $_GET;
    if (isset ($_GET["callback"])) {
        echo $_GET["callback"].'('.json_encode ($data).')';
    }
    else {
        header ('Content-type: application/json');
        echo json_encode($data);
    }
}

function fixlogin( &$config ) {
    $config['httpecho'] = true;
}

function get_links($lang, $page, $wgRequest,
                   $toolserver_username, $toolserver_password) {
    $wiki = $wgRequest->getSafeVal('wiki', 'wikipedia');
    $url = $lang.'.'.$wiki.'.org';
    //Load database
    $dbr = new Database('sql-toolserver',
                        $toolserver_username,
                        $toolserver_password,
                        'toolserver');

    $res = $dbr->select('wiki',
                 array('dbname', 'server',),
                 array('domain' => "$lang.$wiki.org"));

    if (!count($res)) {
        toDie("No wiki found!");
    }

    $dbr = new Database('sql-s'.$res[0]['server'],
                        $toolserver_username,
                        $toolserver_password,
                        $res[0]['dbname']);

    //Initialize Peachy
    $site = Peachy::newWiki(null, null, null, 'http://'.$url.'/w/api.php');

    try {
        $pageClass = $site->initPage($page, null,
                                     !$wgRequest->getSafeVal('getBool',
                                                             'nofollowredir'));
    }
    catch (BadTitle $e) {
        toDie ("Page not found!");
    }

    catch (Exception $e) {
        toDie($e->getMessage());
    }

    //Check for page existance
    if (!$pageClass->get_exists())
        toDie("Invalid article name!");

    //Start doing the DB request
    $conds = array ('pl_from = '.$dbr->strencode ($pageClass->get_id ()));

    $links = $dbr->select (array('pagelinks'),
                           array('pl_title', 'pl_namespace'),
                           $conds, array('LIMIT' => 50000));

    if (!count($links))
        toDie ("There are no outer links");
    $data = array();
    foreach ($links as $id => $link) {
        if (!is_numeric($link["pl_title"][0])) {
            // skip pages that start with a number (e.g.: years)
            $page = false;
            if ($link['pl_namespace'] == 0) {
                $page = str_replace(" ", "_", $link["pl_title"]);
            }
            else if ($link['pl_namespace'] == 1) {
                $page = "Talk:".str_replace(" ", "_", $link["pl_title"]);
            }
            else if ($link['pl_namespace'] == 2) {
                $page = "User:".str_replace(" ", "_", $link["pl_title"]);
            }
            else if ($link['pl_namespace'] == 3) {
                $page = "User_Talk:".str_replace(" ", "_", $link["pl_title"]);
            }
            else if ($link['pl_namespace'] == 4) {
                $page = "Wikipedia:".str_replace(" ", "_", $link["pl_title"]);
            }
            else if ($link['pl_namespace'] == 12) {
                $page = "Help:".str_replace(" ", "_", $link["pl_title"]);
            }
            if ($page) {
                $data[] = $page;
            }
        }
    }
    return array_unique($data);
}


$wgRequest = new WebRequest();
$lang1 = $wgRequest->getSafeVal('l1');
$article1 = trim(str_replace(array('&#39;', '%20'), array('\'', ' '),
                            $wgRequest->getSafeVal('a1')));
$article1 = urldecode($article1);

$lang2 = $wgRequest->getSafeVal('l2');
$article2 = trim(str_replace(array('&#39;', '%20'), array('\'', ' '),
                            $wgRequest->getSafeVal('a2')));
$article2 = urldecode($article2);
if (!($article1 && $article2 && $lang1 && $lang2)) {
    toDie("Please specify two articles and their language");
}

$links1 = get_links($lang1, $article1, $wgRequest,
                    $toolserver_username, $toolserver_password);
$links2 = get_links($lang2, $article2, $wgRequest,
                    $toolserver_username, $toolserver_password);

//links1 must be the min list
$swapped = false;
if (count($links1) > count($links2)) {
    $tmp = $links1;
    $links1 = $links2;
    $links2 = $tmp;

    $tmp = $lang1;
    $lang1 = $lang2;
    $lang2 = $tmp;
    $swapped = true;
}

$wiki = $wgRequest->getSafeVal('wiki', 'wikipedia');
$url = $lang1.'.'.$wiki.'.org';
$site = Peachy::newWiki(null, null, null, 'http://'.$url.'/w/api.php');

$result = array();

// send 10 pages a time to wikipedia api.php
for ($i=0; $i<=(count($links1)/10); $i++) {
    $current_pages = array_slice($links1, $i*10, 10, true);
    $base = "http://".$lang1.".wikipedia.org/w/api.php?action=query&prop=langlinks&titles=".
            urlencode(implode("|", $current_pages))."&lllimit=500&redirects&format=json";
    $cont = true;
    $url = $base;

    while ($cont) {
        $data = json_decode(file_get_contents($url), true);
        //populate result
        foreach ($data["query"]["pages"] as $id => $elem) {
            $title = urlencode($elem["title"]);
            if (array_key_exists("langlinks", $elem)) {
                foreach ($elem["langlinks"] as $ll) {
                    if ($ll["lang"] == $lang2) {
                        $result[$title] = str_replace(" ", "_", $ll["*"]);
                        break;
                    }
                }
            }
            if (!array_key_exists($title, $result)) {
                $result[$title] = str_replace(" ", "_", $elem["title"]);
            }
        }

        //if retrieved data is not complete follow llcontinue
        if (array_key_exists("query-continue", $data)) {
            $url = $base."&llcontinue=".$data["query-continue"]["langlinks"]["llcontinue"];
        }
        else {
            $cont = false;
        }
    }
}

//check for redirects
$tmp = $links2;
for ($i=0; $i<=(count($tmp)/40.0); $i++) {
    $current_pages = array_slice($tmp, $i*40, 40);
    $url = "http://".$lang2.".wikipedia.org/w/api.php?action=query&titles=".
           urlencode(implode("|", $current_pages))."&redirects&format=json";
    $data = json_decode(file_get_contents($url), true);
    if ($data && array_key_exists("redirects", $data["query"])) {
        $from = array();
        $to = array();
        foreach ($data["query"]["redirects"] as $id => $elem) {
            $from[] = str_replace(" ", "_", $elem["from"]);
            $to[] = str_replace(" ", "_", $elem["to"]);
        }
        $links2 = array_diff($links2, $from);
        $links2 = array_merge($links2, $to);
    }
}

//create output array
$output = array("matching" => array(),
                "nonmatching1" => array(),
                "nonmatching2" => array());
$matching = array();

//check for matching links
$match = 0;
foreach ($result as $original => $langlink) {
    $original = urldecode($original);
    if (in_array($langlink, $links2)) {
        if (!$swapped) {
            $output["matching"][] = array($original, $langlink);
        }
        else {
            $output["matching"][] = array($langlink, $original);
        }
        $matching[] = $langlink;
        $match++;
    }
    else {
        if (!$swapped) {
            $output["nonmatching1"][] = $original;
        }
        else {
            $output["nonmatching2"][] = $original;
        }
    }
}

//fill nonmatching with remaining links
foreach ($links2 as $link) {
    if (!(in_array($link, $matching))) {
        if (!$swapped) {
            $output["nonmatching2"][] = $link;
        }
        else {
            $output["nonmatching1"][] = $link;
        }
    }
}

$exectime = microtime(true) - $time_start;
if (count($links1) > 3) {
    $res = $match / count($result);
}
else {
    $res = "N/A";
}

$output["result"] = $res;
$output["exectime"] = $exectime;
$output["a1"] = $article1;
$output["a2"] = $article2;
if (!$swapped) {
    $output["l1"] = $lang1;
    $output["l2"] = $lang2;
}
else {
    $output["l1"] = $lang2;
    $output["l2"] = $lang1;
}
print_result($output);

mysql_connect("sql-toolserver", $toolserver_username, $toolserver_password)
        or die("Unable to connect to MySQL");
mysql_select_db('u_sonet')
        or die("Could not select db!");
$qry = "CREATE TABLE IF NOT EXISTS `api_comparison_log` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `when` TIMESTAMP DEFAULT NOW() NOT NULL,
            `left_lang` VARCHAR(20) NOT NULL,
            `left_page` TEXT NOT NULL,
            `right_lang` VARCHAR(20) NOT NULL,
            `right_page` TEXT NOT NULL,
            `left_links` INT NOT NULL,
            `right_links` INT NOT NULL,
            `matching_links` INT NOT NULL,
            `similarity_index` REAL NOT NULL
        );";
mysql_query($qry);
$qry = "INSERT INTO `api_comparison_log` (
            `left_lang`,
            `left_page`,
            `right_lang`,
            `right_page`,
            `left_links`,
            `right_links`,
            `matching_links`,
            `similarity_index`)
        VALUES (
            '".mysql_real_escape_string(strip_tags($output["l1"]))."',
            '".mysql_real_escape_string(strip_tags($output["a1"]))."',
            '".mysql_real_escape_string(strip_tags($output["l2"]))."',
            '".mysql_real_escape_string(strip_tags($output["a2"]))."',
            ".(count($output["nonmatching1"])+$match).",
            ".(count($output["nonmatching2"])+$match).",
            ".$match.",
            ".($match/count($result))."
        );";
mysql_query($qry) or die("Logging failed");

?>
