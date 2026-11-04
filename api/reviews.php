<?php
/**
 * GET /api/reviews.php
 * Approved reviews for the homepage, newest first, with the average ratings.
 */

require __DIR__ . '/lib.php';

$rows = bv_rows( "SELECT name, location, rating, professionalism, convenience, text, approved_at FROM reviews WHERE status = 'approved' ORDER BY approved_at DESC LIMIT 60" );
$list = array_map(
	function ( $r ) {
		return array(
			'name'            => $r['name'],
			'location'        => $r['location'],
			'rating'          => (int) $r['rating'],
			'professionalism' => (int) $r['professionalism'],
			'convenience'     => (int) $r['convenience'],
			'text'            => $r['text'],
			'date'            => substr( (string) $r['approved_at'], 0, 10 ),
			'verified'        => true,
		);
	},
	$rows
);
header( 'Cache-Control: public, max-age=300' );
http_response_code( 200 );
header( 'Content-Type: application/json; charset=utf-8' );
echo json_encode( array( 'reviews' => $list ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
