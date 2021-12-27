# Sync posts
```php
use \Otomaties\WpSyncPosts\Syncer;

$syncer = new Syncer('post');

$response = wp_remote_get('https://example.com');
$externalPosts = wp_remote_retrieve_body($response);

foreach ($externalPosts as $externalPost) {
    $args = [
        'post_title' => $externalPost->title(),
        'post_content' => $externalPost->content(),
        'post_date' => gmdate('Y-m-d H:i:s', $externalPost->createdTime()),
        'post_status' => 'publish',
        'meta_input' => [
            'external_id' => $externalPost->id(),
            'other_meta' => $externalPost->otherMeta(),
        ),
        'media' => [
            [
                'key'           => false,
                'featured'      => true,
                'url'           => $externalPost->thumbnail()->url(),
                'date_modified' => gmdate('Y-m-d H:i:s', $externalPost->createdTime()),
            ],
        ],
    ];

    $existingPostQuery = [
        'by'    => 'meta_value', // id, meta_value
        'key'   => 'external_id', // Only if 'by' => 'meta_value'
        'value' => $externalPost->id(), // Unique value to identify this post
    ];

    $syncer->addPost($args, $existingPostQuery);
}

$syncer->execute();
```
		
# Sync products
```php
use \Otomaties\WpSyncPosts\Syncer;

$syncer = new Syncer('product');

$response = wp_remote_get('https://example.com');
$externalPosts = wp_remote_retrieve_body($response);

foreach ($externalPosts as $externalPost) {
    $args = [
		'post_title' => $externalPost->title(),
		'media' => [
			[
				'key'           => false,
				'featured'      => true,
				'url'           => $externalPost->thumbnail()->url(), // String e.g. 'https://via.placeholder.com/800x600.png?text=Variation+4'
                'date_modified' => gmdate('Y-m-d H:i:s', $externalPost->thumbnail()->createdTime()),
			],
		],
		'meta_input' => array(
			'external_id' => $externalPost->id(),
		),
		'tax_input' => array(
			'product_cat' => [16, 57],
		),
		'woocommerce' => [
			'product_type' => $externalPost->productType(), // string: 'variable', 'simple', ...
			'available_attributes' => $externalPost->availableAttributes(), // Array of attributes e.g. ['color', 'size']
			'variations' => [], // Array of available variations
			'meta_input' => array(
				'_sku' => $externalPost->sku(),
			),
		],
	];

	foreach($externalPost->variations() as $variation) {
		$args['variations'] = [
			'woocommerce' => [
				'attributes' => [
					'color' => $variation->attribute('color'), // string
					'size' => $variation->attribute('size'), // string
				],
				'meta_input' => [
					'_regular_price' => $variation->price(), // float
					'_sku' => $variation->sku(), // string
					'_stock' => $variation->stockQuantity(), // int
					'_weight' => $variation->weight(), // float
					'_length' => $variation->length(), // float
					'_width' => $variation->width(), // float
					'_height' => $variation->height(), // float
					'_variation_description' => $variation->description(),
					'_backorders' => $variation->backorders(), // 'yes' or 'no'
				]
			],
			'media' => [
				[
					'key'           => false,
					'featured'      => true,
					'url'           => $variation->thumbnail()->url(), // String e.g. 'https://via.placeholder.com/800x600.png?text=Variation+4'
                	'date_modified' => gmdate('Y-m-d H:i:s', $variation->thumbnail()->createdTime()),
				],
			],
		],
	}

    $existingPostQuery = [
        'by'    => 'meta_value', // id, meta_value, sku
        'key'   => 'external_id', // Only if 'by' => 'meta_value'
        'value' => $externalPost->id(), // Unique value to identify this post
    ];

	$existingVariationQuery = [
		'by' => 'sku',
	];

    $syncer->addPost($args, $existingPostQuery, $existingVariationQuery);
}

$syncer->execute();
```
