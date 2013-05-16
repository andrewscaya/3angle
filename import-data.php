<?php
include 'config.php';
include 'classes.php';

/* The file to parse will be on the command line */
$file = $argv[1];

/* Connect, empty the collection and create indexes */
$m = new MongoClient( 'mongodb://localhost:27017' );
$collection = $m->selectCollection( DATABASE, COLLECTION );
//$collection->drop();
$collection->ensureIndex( array( TYPE => 1 ) );
$collection->ensureIndex( array( LOC => '2dsphere' ) );
$collection->ensureIndex( array( TAGS => 1 ) );

/* Parse the nodes */
$z = new XMLReader();
$z->open( $argv[1]);
while ($z->read() && $z->name !== 'node' );
$count = 0;
$collection->remove( array( TYPE => 1 ), array( 'timeout' => 1800000 ) );

echo "Importing nodes:\n";
while ($z->name === 'node') {
	$dom = new DomDocument;
	$node = simplexml_import_dom($dom->importNode($z->expand(), true));

	/* #1: Create the document structure */
	$q = array();
	/* Add type, _id and loc elements here */
	$q[TYPE] = 1;
	$q['_id'] = "n" . (string) $node['id'];
	$geo = new GeoJSONPoint( $node['lon'], $node['lat'] );
	$q[LOC] = $geo->getGeoJSON();
	/* Check the parseNode implementation */
	parseNode($q, $node);

	/* #2: Write the insert command here */
	$collection->insert( $q );

	$z->next('node');
	$count++;
	if ($count % 1000 === 0) {
		echo ".";
	}
	if ($count % 100000 === 0) {
		echo "\n", $count, "\n";
	}
}
echo "\n";

/* Parse the ways */
$z = new XMLReader();
$z->open( $argv[1]);
while ($z->read() && $z->name !== 'way' );
$count = 0;
$collection->remove( array( TYPE => 2 ), array( 'timeout' => 1800000 ) );

echo "Importing ways:\n";
while ($z->name === 'way') {
	$dom = new DomDocument;
	$way = simplexml_import_dom($dom->importNode($z->expand(), true));

	/* #3: Create the document structure */
	$q = array();
	/* Add type and _id elements here */
	$q['_id'] = "w" . (string) $way['id'];
	$q[TYPE] = 2;
	/* Check the fetchLocations() and parseNode() implementations */
	fetchLocations($collection, $q, $way);
	parseNode($q, $way);

	try
	{
		$collection->insert( $q );
	}
	catch ( MongoCursorException $e )
	{
		echo "\n", $q['_id'], ': ', $e->getMessage(), "\n";
	}

	$z->next('way');
	if (++$count % 100 === 0) {
		echo ".";
	}
	if ($count % 10000 === 0) {
		echo "\n", $count, "\n";
	}
}
echo "\n";

/* Parse the relations */
$z = new XMLReader();
$z->open( $argv[1]);
while ($z->read() && $z->name !== 'relation' );
$count = 0;
$collection->remove( array( TYPE => 3 ), array( 'timeout' => 1800000 ) );

echo "Importing relations:\n";
while ($z->name === 'relation') {
	$dom = new DomDocument;
	$relation = simplexml_import_dom($dom->importNode($z->expand(), true));

	/* #3: Create the document structure */
	$q = array();
	/* Add type and _id elements here */
	$q['_id'] = "r" . (string) $relation['id'];
	$q[TYPE] = 3;
	/* Check the fetchLocations() and parseNode() implementations */
	parseNode($q, $relation);
	if ( !fetchMembers($collection, $q, $relation, $idsToDelete ) )
	{
		goto nextrel;
	}

	try
	{
		$collection->insert( $q );
		foreach ( $idsToDelete as $idToDelete )
		{
			$collection->remove( array( '_id' => $idToDelete ) );
		}
	}
	catch ( MongoCursorException $e )
	{
		echo "\n", $q['_id'], ': ', $e->getMessage(), "\n";
		var_dump( $q );
	}

nextrel:
	$z->next('relation');
	if (++$count % 100 === 0) {
		echo ".";
	}
	if ($count % 10000 === 0) {
		echo "\n", $count, "\n";
	}
}
echo "\n";

function fetchLocations($collection, &$q, $node)
{
	$tmp = $locations = $nodeIds = array();
	$currentLoc = null;

	foreach ($node->nd as $nd) {
		$nodeIds[] = 'n' . (int) $nd['ref'];
	}
	$r = $collection->find( array( '_id' => array( '$in' => $nodeIds ) ) );
	foreach ( $r as $n ) {
		$tmp[$n["_id"]] = GeoJSONPoint::fromGeoJson( $n[LOC] )->p;
	}
	foreach ( $nodeIds as $id ) {
		if (isset($tmp[$id])) {
			$locations[] = $tmp[$id];
		}
	}
	if ( $nodeIds[0] == $nodeIds[sizeof( $nodeIds ) - 1] )
	{
		/* Extra array encapsulation to support outer/inner rings */
		$geo = new GeoJSONPolygon( array( $locations ) );
	}
	else
	{
		$geo = new GeoJSONLineString( $locations );
	}
	$q[LOC] = $geo->getGeoJSON();
}

function fetchMembers($collection, &$q, $node, &$idsToDelete)
{
	$tmp = $outerIds = $innerIds = $rings = array();
	$currentLoc = null;

	foreach ( $node->member as $member )
	{
		if ( $member['type'] != "way" )
		{
			/* Right now, we'll only handle way members */
			return false;
		}
		switch ( $member['role'] )
		{
			case 'outer':
				$outerIds[] = 'w' . (int) $member['ref'];
				break;
			case 'inner':
				$innerIds[] = 'w' . (int) $member['ref'];
				break;
			default:
				/* If it's not inner or other we don't do anything with it yet */
				return false;
		}
	}

	$r = $collection->find( array( '_id' => array( '$in' => $outerIds ) ) );
	foreach ( $r as $n )
	{
		$rings[] = GeoJSONPolygon::fromGeoJson( $n[LOC] )->pg[0];
	}

	$r = $collection->find( array( '_id' => array( '$in' => $innerIds ) ) );
	foreach ( $r as $n )
	{
		$rings[] = GeoJSONPolygon::fromGeoJson( $n[LOC] )->pg[0];
	}

	$geo = new GeoJSONPolygon( $rings );

	$q[LOC] = $geo->getGeoJSON();

	$idsToDelete = array_merge( $outerIds, $innerIds );
	return true;
}

function parseNode(&$q, $sxml)
{
	$tagsCombined = array();
	$ignoreTags = array( 'created_by', 'abutters' );

	$meta = array();
	if ( isset( $sxml['version'] ) )
	{
		$meta['v'] = (int) $sxml['version'];
	}
	if ( isset( $sxml['changeset'] ) )
	{
		$meta['cs'] = (int) $sxml['changeset'];
	}
	if ( isset( $sxml['uid'] ) )
	{
		$meta['uid'] = (int) $sxml['uid'];
	}
	if ( isset( $sxml['timestamp'] ) )
	{
		$meta['ts'] = (int) strtotime( $sxml['timestamp'] );
	}

	foreach( $sxml->tag as $tag )
	{
		if (!in_array( $tag['k'], $ignoreTags)) {
			$tagsCombined[] = (string) $tag['k'] . '=' . (string) $tag['v'];
		}
	}

	if ( sizeof( $tagsCombined ) > 0 )
	{
		$q[TAGS] = $tagsCombined;
	}
	if ( sizeof( $meta ) > 0 )
	{
		$q[META] = $meta;
	}
}
