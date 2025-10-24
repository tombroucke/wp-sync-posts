# WP Sync Posts

Synchronise posts between an external provider (API, .csv, ...) and WordPress.

## Installation

```
composer require tombroucke/wp-sync-posts
```

## Usage

### Basic usage

```php
$syncer = new \Otomaties\WpSyncPosts\Syncer('post');

$externalPosts = [
	[
		'title' => 'API post 1 title',
		'id' => 'api-post-1',
	],
	[
		'title' => 'API post 2 title',
		'id' => 'api-post-2',
	],
];

foreach ($externalPosts as $externalPost) {
	$args = [
		'post_title' => $externalPost['title'],
		'meta_input' => [
			'external_id' => $externalPost['id'],
		],
	];

	$existingPostQuery = [
		'by' => 'meta_value',
		'key' => 'external_id',
		'value' => $externalPost['id'],
	];

	$syncer->addPost($args, $existingPostQuery);
}

$syncer->execute();
```

### Advanced usage

#### Media

This library can import external media to the media library

```php
$args = [
	'post_title' => $externalPost['title'],
	'meta_input' => [
		'external_id' => $externalPost['id'],
	],
	'media' => [
		[
			'url' => $externalPost['thumbnail']['url'],
			'featured' => true,
            'date_modified' => gmdate('Y-m-d H:i:s', $externalPost['thumbnail']['date_modified']), // If this field changes, the old media gets deleted and the new one is downloaded
		],
		[
			'url' => $externalPost['floor_plan']['url'],
            'date_modified' => gmdate('Y-m-d H:i:s', $externalPost['floor_plan']['date_modified']),
			'key' => 'floor_plan', // You can get this media using get_post_meta($postId, 'floor_plan', true);
			'group' => 'documents' // You can fetch all media for this post in this group using get_post_meta($postId, 'documents', true); Defaults to 'synced_images'
		],
	],
]
```

#### Meta

You can add additional post meta in the meta_input field

```php
$args = [
	'post_title' => $externalPost['title'],
	'meta_input' => [
		'external_id' => $externalPost['id'],
		'key' => 'value'
	],
]
```

#### Taxonomy

You can assign posts to taxonomies using the tax_input field

```php
$args = [
	'post_title' => $externalPost['title'],
	'meta_input' => [
		'external_id' => $externalPost['id'],
	],
	'tax_input' => [
		'custom_taxonomy' => [16, 57],
	],
]
```

#### Callbacks

You can use callbacks in post arguments.

```php
$args = [
	'post_title' => $externalPost['title'],
	'post_status' => fn (\Otomaties\WpSyncPosts\Post $post) => $post->id() ? 'publish' : 'draft', // publish if post exists, draft if it doesn't
	'meta_input' => [
		'external_id' => $externalPost['id'],
	],
]
```

#### WPML support

```php
$args = [
	'post_title' => $externalPost['title'],
	'meta_input' => [
		'external_id' => $externalPost['id'],
	],
	'lang'                          => $externalPost['language'],
	'wpml_reference'                => $externalPost['id'] . '_' . $externalPost['language'],
	'wpml_original_post_reference'  => $externalPost['id'] . '_' . 'en',
];
```

#### WooCommerce Products

```php
use \Otomaties\WpSyncPosts\Syncer;

$syncer = new Syncer('product');

$externalPosts = [
	[
		'title' => 'API post 1 title',
		'id' => 'api-post-1',
		'product_type' => 'simple',
		'sku' => 'API-POST-1-SKU',
	],
	[
		'title' => 'API post 2 title',
		'id' => 'api-post-2',
		'product_type' => 'variable',
		'available_attributes' => ['color', 'size'],
		'sku' => 'API-POST-2-SKU',
		'variations' => [
			[
				'attributes' => [
					'color' => 'Blellow',
					'size' => 'M',
				],
				'price' => 29.99,
				'sku' => 'API-POST-2-RED-M',
				'stockQuantity' => 10,
				'weight' => 0.5,
				'length' => 10,
				'width' => 5,
				'height' => 2,
				'description' => 'Red Medium variation',
				'backorders' => 'no',
				'thumbnail' => [
					'url' => 'https://via.placeholder.com/800x600.png?text=Variation+1',
					'date_modified' => time(),
				],
			],
			[
				'attributes' => [
					'color' => 'Black',
					'size' => 'L',
				],
				'price' => 34.99,
				'sku' => 'API-POST-2-BLUE-L',
				'stockQuantity' => 5,
				'weight' => 0.6,
				'length' => 12,
				'width' => 6,
				'height' => 3,
				'description' => 'Blue Large variation',
				'backorders' => 'yes',
				'thumbnail' => [
					'url' => 'https://via.placeholder.com/800x600.png?text=Variation+2',
					'date_modified' => time(),
				],
			],
		],
	],
];


foreach ($externalPosts as $externalPost) {
	$existingVariationQuery = [];

	$args = [
		'post_title' => $externalPost['title'],
		'meta_input' => [
			'test' => 'value2',
			'external_id' => $externalPost['id'],
		],
		'woocommerce' => [
			'product_type' => $externalPost['product_type'], // string: 'variable', 'simple', ...
			'meta_input' => [
				'_sku' => $externalPost['sku'],
			],
		],
	];

	if ($externalPost['product_type'] === 'variable') {

		$args['woocommerce']['available_attributes'] = $externalPost['available_attributes'];
		$args['woocommerce']['variations'] = [];

		foreach(($externalPost['variations'] ?? []) as $variation) {
			$args['woocommerce']['variations'][] = [
				'woocommerce' => [
					'attributes' => [
						'color' => $variation['attributes']['color'], // string
						'size' => $variation['attributes']['size'], // string
					],
					'meta_input' => [
						'_regular_price' => $variation['price'], // float
						'_sku' => $variation['sku'], // string
						'_stock' => $variation['stockQuantity'], // int
						'_weight' => $variation['weight'], // float
						'_length' => $variation['length'], // float
						'_width' => $variation['width'], // float
						'_height' => $variation['height'], // float
						'_variation_description' => $variation['description'],
						'_backorders' => $variation['backorders'], // 'yes' or 'no'
					]
				],
				'media' => [
					[
						'key'           => false,
						'featured'      => true,
						'url'           => $variation['thumbnail']['url'], // String e.g. 'https://via.placeholder.com/800x600.png?text=Variation+4'
						'date_modified' => gmdate('Y-m-d H:i:s', $variation['thumbnail']['date_modified']),
					],
				],
			];
		}

		$existingVariationQuery = [
			'by' => 'sku',
		];
	}

	$existingPostQuery = [
		'by'    => 'meta_value', // id, meta_value, sku
		'key'   => 'external_id', // Only if 'by' => 'meta_value'
		'value' => $externalPost['id'], // Unique value to identify this post
	];
	$syncer->addProduct($args, $existingPostQuery, $existingVariationQuery);
}

$syncer->execute();
```
