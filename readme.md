# Sync posts
```php
use \Otomaties\WP_Post_Sync\Post_Sync;

$syncer = new Post_Sync();
$post_type = 'post';

$response = wp_remote_get( 'https://example.com' );
$external_posts = wp_remote_retrieve_body( $response );

foreach ( $external_posts as $external_post ) {

	$new_post = array(
		'post_title' => $external_post->getTitle(),
		'post_content' => $external_post->getTitle(),
		'post_date' => gmdate( 'Y-m-d H:i:s', $external_post->getCreatedTime() ),
		'post_status' => 'publish',
		'post_type' => $external_post_type,
		'meta_input' => array(
			'external_id' => $external_post->getId(),
			'other_meta' => $external_post->otherMeta(),
		),
		'media' => array(
			array(
				'key'           => false,
				'featured'      => true,
				'url'           => $external_post->getThumbnail()->url(),
				'date_modified' => gmdate( 'Y-m-d H:i:s', $external_post->getCreatedTime() ),
			),
		),
	);

	$find = array(
		'by'    => 'meta_value',
		'key'   => 'external_id',
		'value' => $external_post->getId(),
	);

	$syncer->sync( $new_post, $find );

}
$syncer->clean_up( $post_type );
die();
```
		
# Sync products
```php
use \Otomaties\WP_Post_Sync\Product_Sync;

$external_posts = array(
	array(
		'post_title' => 'Guitar',
		'media' => array(
			array(
				'key'           => false,
				'featured'      => true,
				'url'           => 'https://via.placeholder.com/800x600.png?text=Synced+media',
			),
		),
		'woocommerce' => array(
			'_sku' => 'sku1',
			'_sale_price' => '',
			'product_cat' => array( 16, 17 ),
			'product_type' => 'variable',
			'available_attributes' => array( 'color', 'size' ),
			'variations' => array(
				array(
					'woocommerce' => array(
						'attributes' => array(
							'color' => 'blue',
							'size' => '10',
						),
						'_regular_price' => '13',
						'_sku' => 'var_sku1',
						'_stock' => '8',
					),
					'media' => array(
						array(
							'key'           => false,
							'featured'      => true,
							'url'           => 'https://via.placeholder.com/800x600.png?text=Variation+1',
						),
					),
				),
				array(
					'woocommerce' => array(
						'attributes' => array(
							'color' => 'blue',
							'size' => '11',
						),
						'_regular_price' => '14',
						'_sku' => 'var_sku2',
						'_stock' => '8',
					),
					'media' => array(
						array(
							'key'           => false,
							'featured'      => true,
							'url'           => 'https://via.placeholder.com/800x600.png?text=Variation+2',
						),
					),
				),
				array(
					'woocommerce' => array(
						'attributes' => array(
							'color' => 'blue',
							'size' => '12',
						),
						'_regular_price' => '25',
						'_sku' => 'var_sku3',
						'_stock' => '8',
					),
					'media' => array(
						array(
							'key'           => false,
							'featured'      => true,
							'url'           => 'https://via.placeholder.com/800x600.png?text=Variation+3',
						),
					),
				),
				array(
					'woocommerce' => array(
						'attributes' => array(
							'color' => 'blellow',
							'size' => '12',
						),
						'_regular_price' => '26',
						'_sku' => 'var_sku4',
						'_stock' => '8',
						'_weight' => '0.250',
						'_length' => '50',
						'_width' => '10',
						'_height' => '20',
						'_variation_description' => 'Description',
						'_backorders' => 'no',
					),
					'media' => array(
						array(
							'key'           => false,
							'featured'      => true,
							'url'           => 'https://via.placeholder.com/800x600.png?text=Variation+4',
						),
					),
				),
			),
		),
	),
);

$syncer = new Product_Sync();
foreach ( $external_posts as $external_post ) {

	$find_product = array(
		'by' => 'sku',
		'value' => $external_post['woocommerce']['_sku'],
	);

	$find_variation = array(
		'by' => 'sku',
	);
	$syncer->sync( $external_post, $find_product, $find_variation );

}
$syncer->clean_up( 'product' );
die();
```