<?php

/* Thanks to X! aka soxred93.
 * This code by him with some little modifications
 * I think that it requires some style revison though */

//Set error stuff
error_reporting(E_ALL);
ini_set("display_errors", 1);
ini_set("memory_limit", '64M');
ini_set('user_agent', 'sonet group');

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
            if ($link['pl_namespace'] == 0) {
                $data[] = str_replace(" ", "_", $link["pl_title"]);
            }
            else if ($link['pl_namespace'] == 1) {
                $data[] = "Talk:".str_replace(" ", "_", $link["pl_title"]);
            }
            else if ($link['pl_namespace'] == 2) {
                $data[] = "User:".str_replace(" ", "_", $link["pl_title"]);
            }
            else if ($link['pl_namespace'] == 3) {
                $data[] = "User_Talk:".str_replace(" ", "_", $link["pl_title"]);
            }
            else if ($link['pl_namespace'] == 4) {
                $data[] = "Wikipedia:".str_replace(" ", "_", $link["pl_title"]);
            }
            else if ($link['pl_namespace'] == 12) {
                $data[] = "Help:".str_replace(" ", "_", $link["pl_title"]);
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
//echo count($links1)."\n";
//echo count($links2)."\n";
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

//Comparing the links lists!
$wiki = $wgRequest->getSafeVal('wiki', 'wikipedia');
$url = $lang1.'.'.$wiki.'.org';
$site = Peachy::newWiki(null, null, null, 'http://'.$url.'/w/api.php');

$no_match = 0;
$match = 0;

$result = array();

for ($i=0; $i<(count($links1)/10.0); $i++) {
    $current_pages = array_slice($links1, $i*10, 10);
    $base = "http://".$lang1.".wikipedia.org/w/api.php?action=query&prop=langlinks&titles=".
           implode("|", $current_pages)."&lllimit=500&redirects&format=json";
    $cont = true;
    $url = $base;

    while ($cont) {
        $data = json_decode(file_get_contents($url), true);
        //echo $url."<br/>";
        foreach ($data["query"]["pages"] as $id => $elem) {
            if (array_key_exists("langlinks", $elem)) {
                foreach ($elem["langlinks"] as $ll) {
                    if ($ll["lang"] == $lang2) {
                        $result[$elem["title"]] = str_replace(" ", "_", $ll["*"]);
                        break;
                    }
                }
            }
        }

        if (array_key_exists("query-continue", $data)) {
            $url = $base."&llcontinue=".$data["query-continue"]["langlinks"]["llcontinue"];
        }
        else {
            $cont = false;
        }
    }
}

$output = array("matching" => array(),
                "nonmatching1" => array(),
                "nonmatching2" => array());
$matching = array();
foreach ($result as $original => $langlink) {
    //echo $link;
    if (in_array($langlink, $links2)) {
        if (!$swapped) {
            $output["matching"][] = array($original, $langlink);
        }
        else {
            $output["matching"][] = array($langlink, $original);
        }
        $matching[] = $langlink;
        $match += 1;
        //echo " matches <br/>";
    }
    else {
        if (!$swapped) {
            $output["nonmatching1"][] = $original;
        }
        else {
            $output["nonmatching2"][] = $original;
        }
        $no_match += 1;
        //echo " doesn't match <br/>";
    }
}

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

//echo $match."\n";
//echo $no_match;
$exectime = microtime(true) - $time_start;
if (count($links1) > 3) {
    $res = $match/count($links1);
}
else {
    $res = "N/A";
}

$output["result"] = $res;
$output["exectime"] = $exectime;

print_result($output);

?>
