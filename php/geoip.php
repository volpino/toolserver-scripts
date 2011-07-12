<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

include("geoipcity.inc");
include("geoipregionvars.php");

$gi = geoip_open("GeoLiteCity.dat",GEOIP_STANDARD);
getGeoDataFromIP($gi, $_SERVER['REMOTE_ADDR']);
geoip_close($gi);

function getGeoDataFromIP($gi, $ip) {
    $record = geoip_record_by_addr($gi,$ip);
    print $record->country_code . " " . $record->country_code3 . " " . $record->country_name . "\n";
    print $record->city . "\n";
    print $record->postal_code . "\n";
    print $record->latitude . "\n";
    print $record->longitude . "\n";
}
?>

