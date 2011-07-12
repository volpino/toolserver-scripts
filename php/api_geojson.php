<?php

// Set error stuff
error_reporting(E_ALL);
ini_set("display_errors", 1);
ini_set("memory_limit", '64M' );


//Requires
require_once('/home/sonet/Peachy/Init.php');
require_once('/home/sonet/database.inc');
include("geoipcity.inc");
include("geoipregionvars.php");

//If there is a failure, do it pretty.
function toDie($msg) {
    $data = array("error" => $msg);
    print_result($data);
    die();
}


function getGeoDataFromIP($ip) {
    global $gi;
    $record = geoip_record_by_addr($gi,$ip);
    if ($record) {
        return array($ip,
                     $record->country_name,
                     $record->latitude,
                     $record->longitude);
    }
    return array($ip, "", 0, 0);
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

$gi = geoip_open("GeoLiteCity.dat",GEOIP_STANDARD);
$wgRequest = new WebRequest();

$article = trim(str_replace(array('&#39;','%20'), array('\'',' '),
                $wgRequest->getSafeVal('article')));
$article = urldecode($article);
if (!$article) {
    toDie("Please specify an article name");
}
$wiki = $wgRequest->getSafeVal( 'wiki', 'wikipedia' );
$lang = $wgRequest->getSafeVal( 'lang', 'en' );
$url = $lang.'.'.$wiki.'.org';

//Load database
$dbr = new Database('sql-toolserver',
                    $toolserver_username,
                    $toolserver_password,
                    'toolserver'
);

$res = $dbr->select('wiki',
                    array( 'dbname', 'server', ),
                    array( 'domain' => "$lang.$wiki.org" )
);

if (!count($res)) {
    toDie("No wiki found!");
}

$dbr = new Database('sql-s' . $res[0]['server'],
                    $toolserver_username,
                    $toolserver_password,
                    $res[0]['dbname']
);


//Initialize Peachy
$pgVerbose = array();
$pgHooks['StartLogin'][] = 'fixlogin';
function fixlogin( &$config ) {
    $config['httpecho'] = true;
}
$site = Peachy::newWiki(null, null, null, 'http://'.$url.'/w/api.php');

try {
    $pageClass = $site->initPage($article, null, !$wgRequest->getSafeVal('getBool', 'nofollowredir'));
}
catch( BadTitle $e ) {
    toDie("Page not found!");
}
catch( Exception $e ) {
    toDie($e->getMessage());
}

//Check for page existance
if (!$pageClass->exists()) toDie("Invalid article name!");


//Start doing the DB request
$conds = array('rev_page = ' . $dbr->strencode($pageClass->get_id()));
$start = $end = false;

if ($wgRequest->getSafeVal('getBool', 'begin')) {
    $conds[] = 'UNIX_TIMESTAMP(rev_timestamp) > '.$dbr->strencode(strtotime($wgRequest->getSafeVal('begin')));
    $start = ($wgRequest->getSafeVal('getBool', 'begin')) ? $wgRequest->getSafeVal('begin') : null;
}
if ($wgRequest->getSafeVal( 'getBool', 'end')) {
    $conds[] = 'UNIX_TIMESTAMP(rev_timestamp) < ' . $dbr->strencode(strtotime($wgRequest->getSafeVal('end')));
    $end = ($wgRequest->getSafeVal('getBool', 'end')) ? $wgRequest->getSafeVal('end') : null;
}

$history = $dbr->select(array('revision'),
                        array('rev_user_text',
                              'rev_user',
                              'rev_timestamp',
                              'rev_comment',
                              'rev_minor_edit',
                              'rev_len' ),
                        $conds,
                        array('LIMIT' => 50000));
if (!count($history)) toDie("No revisions found");

//Now we can start our master array. This one will be HUGE!
$data = array("type" => "FeatureCollection",
                        "features" => array());
$countries = array();

//And now comes the logic for filling said master array
foreach ($history as $id => $rev) {
    //Now to fill in various user stats
    $username = htmlspecialchars($rev['rev_user_text']);

    if (!$rev['rev_user']) {
        //Anonymous, increase counts
        $geodata = getGeoDataFromIP($username);
        if ($geodata[2] != 0 && $geodata[3] != 0) {
            array_push($data["features"],
                       array("type" => "Feature",
                             "geometry" => array("type" => "Point",
                                                 "coordinates" => array($geodata[3],
                                                                        $geodata[2])),
                             "properties" => array("when" => strtotime($rev['rev_timestamp']),
                                                   "country" => $geodata[1])));
            if ($countries[$geodata[1]]) {
                $countries[$geodata[1]]++;
            }
            else {
                $countries[$geodata[1]] = 1;
            }
        }
    }

}

array_multisort($countries, SORT_DESC);

$data["stats"] = array("top_countries" => array_slice($countries, 0, 10));
print_result($data);

geoip_close($gi);

?>

