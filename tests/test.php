<?php

// Autoload files using the Composer autoloader.
require_once __DIR__ . '/../vendor/autoload.php';

$syncer = new WpSyncPosts( 'post' );

$posts = wp_remote_get( 'https://myapi.com' );
foreach ( $posts as $post ) {

	$qualifier = array(
		'by' => 'meta_value',
		'value' => array(
			'key' => 'mykey',
			'value' => $post->meta->value
		),
		'params' => array(
			'post_type' => 'post'
		)
	);
	$postargs = array(
		'post_title' => $post->title
		'post_name' => $post->title,
		'post_date' => $post->date,
		'meta_input' => array(
			'mykey' => $post->meta->value
		)
	);
	$syncer->sync_post( $qualifier, $postargs );

}
// Use with caution. The $syncer object keeps track of all changed posts and will delete all posts of this post type which weren't tracked in the previous steps.
$syncer->clean_up();