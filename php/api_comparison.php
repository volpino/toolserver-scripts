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
                            array('pl_title'),
                            $conds, array('LIMIT' => 50000));

    if (!count($links))
        toDie ("There are no outer links");
    $data = array();
    foreach ($links as $id => $link) {
        $data[] = str_replace(" ", "_", $link["pl_title"]);
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
    toDie("Please specify an article name");
}

$links1 = get_links($lang1, $article1, $wgRequest,
                    $toolserver_username, $toolserver_password);
$links2 = get_links($lang2, $article2, $wgRequest,
                    $toolserver_username, $toolserver_password);

//links1 must be the min list
echo count($links1)."\n";
echo count($links2)."\n";
if (count($links1) > count($links2)) {
    $tmp = $links1;
    $links1 = $links2;
    $links2 = $tmp;

    $tmp = $lang1;
    $lang1 = $lang2;
    $lang2 = $tmp;
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
    $base = "http://en.wikipedia.org/w/api.php?action=query&prop=langlinks&titles=".
           implode("|", $current_pages)."&lllimit=500&redirects&format=json";
    $cont = true;
    $url = $base;

    while ($cont) {
        $data = json_decode(file_get_contents($url), true);
        echo $url."<br/>";
        foreach ($data["query"]["pages"] as $id => $elem) {
            if (array_key_exists("langlinks", $elem)) {
                foreach ($elem["langlinks"] as $ll) {
                    if ($ll["lang"] == $lang2) {
                        $result[] = str_replace(" ", "_", $ll["*"]);
                        break;
                    }
                }
            }
        }

        if (array_key_exists("query-continue", $data)) {
            echo "CONTINUE<br/>";
            $url = $base."&llcontinue=".$data["query-continue"]["langlinks"]["llcontinue"];
        }
        else {
            $cont = false;
        }
    }
}

foreach ($links2 as $link) {
    echo $link;
    if (in_array($link, $result)) {
        $match += 1;
        echo " matches <br/>";
    }
    else {
        $no_match += 1;
        echo " doesn't match <br/>";
    }
}


/*foreach ($links1 as $link) {
    try {
        $pageClass = $site->initPage($link, null,
                                     !$wgRequest->getSafeVal('getBool',
                                                             'nofollowredir'));
    }
    catch (Exception $e) {
        continue;
    }

    $langlinks = $pageClass->get_langlinks();

    $name = null;
    foreach ($langlinks as $ll) {
        $res = explode(":", $ll, 2);
        if ($res[0] == $lang2) {
            $name = str_replace(" ", "_", $res[1]);
            break;
        }
    }

    if ($name && (in_array($name, $links2))) {
        $match += 1;
        echo $link." matches <br/>";
    }
    else {
        $no_match += 1;
        echo $link." doesn't match <br/>";
    }
    //print_r($pageClass);
    //echo "--------------------";
}*/

echo $match."\n";
echo $no_match;

?>
