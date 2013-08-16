<?php
include '../config.php';
include '../classes.php';
include '../display.php';

ob_start("ob_gzhandler");

ini_set('display_errors', 1);
ini_set('error_reporting', -1);

header('Content-type: text/plain');
$m = new MongoClient( 'mongodb://localhost' );
$d = $m->selectDb( DATABASE );
$sets = $d->flickr->distinct( "sets" );
natcasesort( $sets ); 
$sets = array( "all" ) + array_values( $sets );

echo json_encode( $sets, JSON_PRETTY_PRINT );
?>
