<?php
include '../config.php';
include '../classes.php';
include '../display.php';
include '../tags.php';

ob_start("ob_gzhandler");

ini_set('display_errors', 1);
ini_set('html_errors', 0);
ini_set('error_reporting', -1);

header('Content-type: text/plain');
$m = new MongoClient( 'mongodb://localhost' );
$d = $m->selectDb( DATABASE );
$c = $d->selectCollection( COLLECTION );
$center = new GeoJSONPoint( (float) $_GET['lon'], (float) $_GET['lat'] );

$res = $c->aggregate( array(
	'$geoNear' => array(
		'near' => $center->getGeoJson(),
		'distanceField' => 'distance',
		'distanceMultiplier' => 1,
		'maxDistance' => 5000,
		'spherical' => true,
//		'query' => array( TAGS => 'amenity=post_box', 'meta.finished' => [ '$ne' => true ] ),
		'query' => array( TAGS => 'amenity=post_box' ),
		'limit' => 1,
	)
) );

$s = array();
if ( array_key_exists( 'result', $res ) )
{
	$s = $res['result'];
}

foreach( $s as &$r )
{
	$tags = Functions::split_tags( $r[TAGS] );

	/* Find closest street */
	$query = [ LOC => [ '$near' => $r[LOC] ], TAGS => new MongoRegex('/^highway=(trunk|pedestrian|service|primary|secondary|tertiary|residential|unclassified)/' ) ];
	$road = $c->findOne( $query );
	$roadTags = Functions::split_tags( $road[TAGS] );
	$roadName = array_key_exists( 'name', $roadTags ) ? $roadTags['name'] : "Unknown " . $roadTags['highway'];
	$s[] = $road;

	/* Find all roads that intersect with the $road */
	$q = $c->find( [
		LOC => [ '$geoIntersects' => [ '$geometry' => $road[LOC] ] ],
		TAGS => new MongoRegex('/^highway=(trunk|pedestrian|service|primary|secondary|tertiary|residential|unclassified)/' ),
		'_id' => [ '$ne' => $road['_id'] ],
	] );

	$intersectingWays = array();
	foreach ( $q as $crossRoad )
	{
		$crossTags = Functions::split_tags( $crossRoad[TAGS] );
		if ( !in_array( "name={$roadName}", $crossRoad ) && array_key_exists( 'name', $crossTags ) )
		{
			$intersectingWays[] = $crossRoad['_id'];
		}
	}

	/* Find closest road to the point, only using $intersectingWay roads */
	$res = $c->aggregate( array(
		'$geoNear' => array(
			'near' => $r[LOC],
			'distanceField' => 'distance',
			'distanceMultiplier' => 1,
			'maxDistance' => 5000,
			'spherical' => true,
			'query' => array( '_id' => [ '$in' => $intersectingWays ], TAGS => [ '$ne' => "name={$roadName}" ] ),
			'limit' => 1,
		)
	) );

	$intersectingRoad = false;

	if ( array_key_exists( 'result', $res ) && ( count( $res['result'] ) > 0 ) )
	{
		$intersectingRoad = $res['result'][0];

		$roadTags = Functions::split_tags( $intersectingRoad[TAGS] );
		if ( array_key_exists( 'name', $roadTags ) )
		{
			$intersectRoadName = $roadTags['name'];
		}
		else if ( array_key_exists( 'ref', $roadTags ) )
		{
			$intersectRoadName = $roadTags['ref'];
		}
		else
		{
			$intersectRoadName = "???";
		}
		$s[] = $intersectingRoad;
	}

	/* If there is a ref, use it, otherwise set ??? */
	if ( array_key_exists( 'ref', $tags ) )
	{
		$pbref = $tags['ref'];
	}
	else
	{
		$pbref = '???';
	}

	/* Add name tag */
	if ( ! $intersectingRoad )
	{
		$desc = "On $roadName";
	}
	else
	{
		if ( $intersectingRoad['distance'] < 20 )
		{
			$desc = "On $roadName, on the corner with $intersectRoadName";
		}
		else
		{
			$desc = "On $roadName, near $intersectRoadName";
		}
	}

	$r['desc'] = $desc;
	$r['ref'] = $pbref;
	$r['distance'] = (int) $r['distance'];

	$dir = initial_bearing( $center->getGeoJson(), $r[LOC] );

	$windlabel = array ('N','NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW','SW', 'WSW', 'W', 'WNW', 'NW', 'NNW');
	$label = $windlabel[ fmod((($dir + 11.25) / 22.5),16) ];

	$r['direction'] = $dir;
	$r[TAGS][] = "name={$pbref}<br/>{$desc}";

	$r['score'] = 0;
	if ( array_key_exists( 'meta', $r ) )
	{
		if ( array_key_exists( 'visited', $r['meta'] ) )
		{
			$r['score'] = 50;
		}
		if ( array_key_exists( 'finished', $r['meta'] ) )
		{
			$r['score'] = 100;
		}
	}
}

if ( array_key_exists( 'simple', $_GET ) )
{
	unset( $r['ts'], $r['m'], $r['ty'], $r['_id'], $r['direction'] );
	$r['l'] = $r['l']['coordinates'];
	$r['w'] = $label;
	echo json_encode( $r, JSON_PRETTY_PRINT );
}
else
{
	$rets = format_response( $s, false );

	echo json_encode( $rets, JSON_PRETTY_PRINT );
}
?>
