<?php

/* Thanks to X! aka soxred93.
 * This code by him with some little modifications
 * I think that it requires some style revison though */

//Set error stuff
error_reporting(E_ALL);
ini_set("display_errors", 1);
ini_set("memory_limit", '64M');


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
    $record = geoip_record_by_addr($gi, $ip);
    if ($record) {
        return array($ip,
                     $record->city." ".$record->country_name,
                     $record->latitude, $record->longitude);
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

$gi = geoip_open("GeoLiteCity.dat", GEOIP_STANDARD);
$wgRequest = new WebRequest();

$get_year_count = isset($_GET["year_count"]);
$get_editors = isset($_GET["editors"]);
$get_anons = isset($_GET["anons"]);
$get_top_ten = isset($_GET["top_ten"]);
$get_top_fifty = isset($_GET["top_fifty"]);

$max_editors = $wgRequest->getSafeVal ('max_editors');

$article = trim(str_replace(array('&#39;', '%20'), array('\'', ' '),
                            $wgRequest->getSafeVal('article')));
$article = urldecode ($article);
if (!$article) {
    toDie("Please specify an article name");
}

$wiki = $wgRequest->getSafeVal('wiki', 'wikipedia');
$lang = $wgRequest->getSafeVal('lang', 'en');
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
$pgVerbose = array();
$pgHooks['StartLogin'][] = 'fixlogin';
function fixlogin( &$config ) {
    $config['httpecho'] = true;
}
$site = Peachy::newWiki(null, null, null, 'http://'.$url.'/w/api.php');

try {
    $pageClass = $site->initPage($article, null,
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
if (!$pageClass->exists())
    toDie("Invalid article name!");


//Start doing the DB request
$conds = array ('rev_page = '.$dbr->strencode ($pageClass->get_id ()));
$start = $end = false;

if ($wgRequest->getSafeVal ('getBool', 'begin')) {
    $conds[] = 'UNIX_TIMESTAMP(rev_timestamp) > '.$dbr->strencode(strtotime($wgRequest->getSafeVal ('begin')));
    $start = ($wgRequest->getSafeVal ('getBool', 'begin')) ? $wgRequest->getSafeVal ('begin') : null;
}

if ($wgRequest->getSafeVal ('getBool', 'end')) {
    $conds[] = 'UNIX_TIMESTAMP(rev_timestamp) < '.$dbr->strencode (strtotime ($wgRequest->getSafeVal ('endn')));
    $end = ($wgRequest->getSafeVal ('getBool', 'end')) ? $wgRequest->getSafeVal ('end') : null;
}

$history = $dbr->select (array ('revision'),
                         array ('rev_user_text', 'rev_user', 'rev_timestamp',
                                'rev_comment', 'rev_minor_edit', 'rev_len'),
                         $conds, array ('LIMIT' => 50000));

if (!count($history))
    toDie ("There are no revisions");

//Now we can start our master array. This one will be HUGE!
$data = array ('first_edit' => array('timestamp' => strtotime($history[0]['rev_timestamp']),
                                      'user' => $history[0]['rev_user_text']),
               'year_count' => array(),
               'count' => 0,
               'editors' => array(),
               'anons' =>array(),
               'minor_count' => 0,
               'count_history' => array ('today' => 0,
                                         'week' => 0,
                                         'month' => 0,
                                         'year' => 0));

$first_edit_parse = date_parse($history[0]['rev_timestamp']);


//And now comes the logic for filling said master array
foreach ($history as $id => $rev) {
    $data['last_edit'] = strtotime($rev['rev_timestamp']);
    $data['count']++;

    //Sometimes, with old revisions (2001 era), the revisions from 2002 come before 2001
    if (strtotime ($rev['rev_timestamp']) <
        strtotime ($data['first_edit']['timestamp'])) {
        $data['first_edit'] = array ('timestamp' => date('Y-m-d\TH:i:s\Z', strtotime($rev['rev_timestamp'])),
                                     'user' => htmlspecialchars($rev['rev_user_text']));
        $first_edit_parse = date_parse($data['first_edit']['timestamp']);
    }


    $timestamp = date_parse($rev['rev_timestamp']);

    //Fill in the blank arrays for the year and 12 months
    if (!isset ($data['year_count'][$timestamp['year']])) {
        $data['year_count'][$timestamp['year']] = array ('all' => 0,
                                                         'minor' => 0,
                                                         'anon' => 0,
                                                         'months' => array());

        for ($i = 1; $i <= 12; $i++) {
            $data['year_count'][$timestamp['year']]['months'][$i] =
                array ('all' => 0,
                       'minor' => 0,
                       'anon' => 0,
                       'size' => array());
        }
    }

    //Increment counts
    $data['year_count'][$timestamp['year']]['all']++;
    $data['year_count'][$timestamp['year']]['months'][$timestamp['month']]['all']++;
    $data['year_count'][$timestamp['year']]['months'][$timestamp['month']]['size'][] = number_format (($rev['rev_len'] / 1024), 2);

    //Now to fill in various user stats
    $username = htmlspecialchars($rev['rev_user_text']);
    if (!isset($data['editors'][$username])) {
        $data['editors'][$username] = array ('all' => 0,
                                             'minor' => 0,
                                             'first' => date('d F Y, H:i:s', strtotime($rev['rev_timestamp'])),
                                             'last' => null,
                                             'atbe' => null,
                                             'minorpct' => 0,
                                             'size' => array(),
                                             'urlencoded' => str_replace(array('+'), array('_'), urlencode($rev['rev_user_text'])));
    }

    //Increment these counts...
    $data['editors'][$username]['all']++;
    $data['editors'][$username]['last'] = date('d F Y, H:i:s', strtotime($rev['rev_timestamp']));
    $data['editors'][$username]['size'][] = number_format(($rev['rev_len'] / 1024), 2);

    if (!$rev['rev_user']) {
        //Anonymous, increase counts
        $data['anons'][date('Y-m-d\TH:i:s\Z', strtotime ($rev['rev_timestamp']))] = getGeoDataFromIP($username);
        $data['year_count'][$timestamp['year']]['anon']++;
        $data['year_count'][$timestamp['year']]['months'][$timestamp['month']]['anon']++;
    }

    if ($rev['rev_minor_edit']) {
        //Logged in, increase counts
        $data['minor_count']++;
        $data['year_count'][$timestamp['year']]['minor']++;
        $data['year_count'][$timestamp['year']]['months'][$timestamp['month']]['minor']++;
        $data['editors'][$username]['minor']++;
    }

    //Increment "edits per <time>" counts
    if (strtotime($rev['rev_timestamp']) > strtotime('-1 day'))
        $data['count_history']['today']++;
    if (strtotime($rev['rev_timestamp']) > strtotime('-1 week'))
        $data['count_history']['week']++;
    if (strtotime ($rev['rev_timestamp']) > strtotime ('-1 month'))
        $data['count_history']['month']++;
    if (strtotime ($rev['rev_timestamp']) > strtotime ('-1 year'))
        $data['count_history']['year']++;

}


//Fill in years with no edits
for ($year = $first_edit_parse['year']; $year <= date ('Y'); $year++) {
    if (!isset ($data['year_count'][$year])) {
        $data['year_count'][$year] = array ('all' => 0,
                                            'minor' => 0,
                                            'anon' => 0,
                                            'months' => array());
        for ($i = 1; $i <= 12; $i++) {
            $data['year_count'][$year]['months'][$i] = array ('all' =>0,
                                                              'minor' => 0,
                                                              'anon' =>0,
                                                              'size' => array());
        }
    }
}


//Add more general statistics
$data['totaldays'] = floor((strtotime ($data['last_edit']) -
                     strtotime ($data['first_edit']['timestamp'])) / 60 / 60 / 24);
$data['average_days_per_edit'] = number_format ($data['totaldays'] / $data['count'], 2);
$data['edits_per_month'] = ($data['totaldays']) ? number_format($data['count'] / ($data['totaldays'] / (365 / 12)), 2) : 0;
$data['edits_per_year'] = ($data['totaldays']) ? number_format($data['count'] / ($data['totaldays'] / 365), 2) : 0;
$data['edits_per_editor'] = number_format($data['count'] / count($data['editors']), 2);
$data['editor_count'] = count($data['editors']);
$data['anon_count'] = count($data['anons']);

//Various sorts
arsort($data['editors']);
ksort($data['year_count']);

//Fix the year counts
$num = 0;
$cum = 0;
$scum = 0;

foreach ($data['year_count'] as $year => $months) {
    //Unset months before the first edit and after the last edit
    foreach ($months['months'] as $month => $tmp) {
        if ($year == $first_edit_parse['year']) {
            if ($month < $first_edit_parse['month'])
                unset ($data['year_count'][$year]['months'][$month]);
            }
        if ($year == date ('Y')) {
            if ($month > date ('m'))
                unset ($data['year_count'][$year]['months'][$month]);
        }
    }

    //Calculate anon/minor percentages
    $data['year_count'][$year]['pcts']['anon'] = ($data['year_count'][$year]['all']) ?
        number_format(($data['year_count'][$year]['anon'] / $data['year_count'][$year]['all']) * 100, 2) : 0.00;
    $data['year_count'][$year]['pcts']['minor'] = ($data['year_count'][$year]['all']) ?
        number_format(($data['year_count'][$year]['minor'] / $data['year_count'][$year]['all']) * 100, 2) : 0.00;


    //Continue with more stats...
    foreach ($data['year_count'][$year]['months'] as $month => $tmp) {
        //More percentages...
        $data['year_count'][$year]['months'][$month]['pcts']['anon'] = ($tmp['all']) ?
            number_format(($tmp['anon'] / $tmp['all']) * 100, 2) : 0.00;
        $data['year_count'][$year]['months'][$month]['pcts']['minor'] = ($tmp['all']) ?
            number_format(($tmp['minor'] / $tmp['all']) * 100, 2) : 0.00;

        //XID and cumulative are used in the flash graph
        $data['year_count'][$year]['months'][$month]['xid'] = $num;
        $data['year_count'][$year]['months'][$month]['cumulative'] = $cum + $tmp['all'];

        if (count ($tmp['size'])) {
            $data['year_count'][$year]['months'][$month]['size'] = number_format((array_sum($tmp['size']) / count($tmp['size'])), 2);
        }
        else {
            $data['year_count'][$year]['months'][$month]['size'] = 0;
        }
        $data['year_count'][$year]['months'][$month]['sizecumulative'] = $scum + $data['year_count'][$year]['months'][$month]['size'];
        $num++;
        $cum += $tmp['all'];
        $scum += $data['year_count'][$year]['months'][$month]['size'];
    }
}


//Top 10% info
$data['top_ten'] = array ('editors' => array(), 'count' => 0);
$data['top_fifty'] = array ();


//Now to fix the user info...
$tmp = $tmp2 = 0;
foreach ($data['editors'] as $editor => $info) {
    //Is the user in the top 10%?
    if ($tmp <= (int) (count ($data['editors']) * 0.1)) {
        $data['top_ten']['editors'][] = $editor;
        $data['top_ten']['count'] += $info['all'];
        $tmp++;
    }
    //Is the user in the 50 highest editors?
    if ($tmp < 50) {
        $data['top_fifty'][] = $editor;
    }

    $data['editors'][$editor]['minorpct'] = ($info['all']) ?
        number_format (($info['minor'] / $info['all']) * 100, 2) : 0.00;

    if ($info['all'] > 1) {
        $data['editors'][$editor]['atbe'] = (int) ((strtotime ($info['last']) -
            strtotime ($info['first'])) / $info['all']);
    }

    if (count($info['size'])) {
        $data['editors'][$editor]['size'] = number_format((array_sum ($info['size']) / count ($info['size'])), 2);
    }
    else {
        $data['editors'][$editor]['size'] = 0;
    }

    $tmp2++;
}

if (!$get_year_count)
    unset ($data["year_count"]);
if (!$get_editors)
    unset ($data["editors"]);
if (!$get_anons)
    unset ($data["anons"]);
if (!$get_top_ten)
    unset ($data["top_ten"]);
if (!$get_top_fifty)
    unset ($data["top_fifty"]);

if ($max_editors) {
    $data["editors"] = array_slice ($data["editors"], 0, (int) $max_editors);
}

print_result($data);

geoip_close ($gi);

?>
