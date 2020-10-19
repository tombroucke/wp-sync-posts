<?php //phpcs:ignore
namespace Otomaties\WP_Post_Sync;

/**
 * Sync WordPress posts with an external API
 */
class Product_Sync extends Post_Sync {

	private $find_variation;

	/**
	 * Merge default array, find and sync post
	 *
	 * @param  array $args WP Post arguments.
	 * @param  array $find Arguments to find existing post.
	 * @return int         Updated/created post ID
	 */
	public function sync( $args, $find_product, $find_variation = null ) {

		$this->find_variation = $find_variation;

		$defaults = array(
			'post_type' => 'product',
			'post_title' => '',
			'post_content' => '',
			'post_status' => 'publish',
		);

		$args = wp_parse_args( $args, $defaults );

		$args['ID'] = $this->find_post( $args['post_type'], $find_product );
		$product_id = $this->save_post( $args );
		$this->woocommerce_meta( $product_id, $args );
		return $product_id;

	}

	/**
	 * Find an existing post
	 *
	 * @param  string $post_type The post type for this post.
	 * @param  array  $find      Arguments to find existing post.
	 * @return int|boolean       The post ID or false
	 */
	protected function find_post( $post_type, $find ) {

		switch ( $find['by'] ) {
			case 'post_id':
				return get_post_status( $find['value'] ) ? $find['value'] : false;
				break;
			case 'meta_value':
				$args = array(
					'post_type'         => $post_type,
					'post_status'       => get_post_stati(),
					'posts_per_page'    => 1,
					'fields'            => 'ids',
					'meta_query'        => array(
						array(
							'key'       => $find['key'],
							'value'     => $find['value'],
							'compare'   => isset( $find['compare'] ) ? $find['compare'] : '=',
						),
					),
				);

				$posts = get_posts( $args );
				if ( isset( $posts[0] ) ) {
					return $posts[0];
				}
				return false;
				break;
			case 'sku':
				$args = array(
					'post_type'         => $post_type,
					'post_status'       => get_post_stati(),
					'posts_per_page'    => 1,
					'fields'            => 'ids',
					'meta_query'        => array(
						array(
							'key'       => '_sku',
							'value'     => $find['value'],
						),
					),
				);

				$posts = get_posts( $args );
				if ( isset( $posts[0] ) ) {
					return $posts[0];
				}
				return false;
			break;

			default:
				return;
			break;
		}

	}

	/**
	 *
	 * Convert post to product with price, stock, ...
	 *
	 * @param  int   $product_id Required.
	 * @param  array $args Meta.
	 * @return int   $post_id
	 */
	protected function woocommerce_meta( $product_id, $args ) {

		$wc_args = $args['woocommerce'];

		$possible_keys = array(
			'_visibility',
			'_stock_status',
			'total_sales',
			'_downloadable',
			'_virtual',
			'_regular_price',
			'_sale_price',
			'_purchase_note',
			'_featured',
			'_weight',
			'_length',
			'_width',
			'_height',
			'_sku',
			'_product_attributes',
			'_sale_price_dates_from',
			'_sale_price_dates_to',
			'_price',
			'_sold_individually',
			'_backorders',
		);
		foreach ( $possible_keys as $key ) {
			if ( isset( $wc_args[ $key ] ) ) {
				update_post_meta( $product_id, $key, $wc_args[ $key ] );
			}
		}

		// Sync stock.
		$this->sync_product_stock( $product_id, ( isset( $wc_args['_stock'] ) ? $wc_args['_stock'] : null ), ( isset( $wc_args['_backorders'] ) ? $wc_args['_backorders'] : null ) );

		// Set categories.
		if ( isset( $wc_args['product_cat'] ) && is_array( $wc_args['product_cat'] ) ) {
			wp_set_post_terms( $product_id, $wc_args['product_cat'], 'product_cat' );
		}

		// Set product type.
		if ( isset( $wc_args['product_type'] ) ) {
			wp_set_object_terms( $product_id, $wc_args['product_type'], 'product_type' );
		}

		// Variations.
		if ( isset( $wc_args['product_type'] ) && isset( $wc_args['product_type'] ) == 'variation' ) {

			// Create attributes.
			$this->insert_product_attributes( $product_id, $wc_args['available_attributes'], $wc_args['variations'] );
			// Create variations.
			$this->sync_product_variations( $product_id, $wc_args['variations'] );

		}

		return $product_id;

	}

	/**
	 * Sync product stock, set backorders, manage stock
	 *
	 * @param int    $product_id   The id of the product.
	 * @param int    $stock_amount The amount of products in stock.
	 * @param string $backorders   Whether backorders are allowed.
	 */
	private function sync_product_stock( $product_id, $stock_amount, $backorders ) {

		if ( ! isset( $backorders ) ) {
			update_post_meta( $product_id, '_backorders', 'no' );
		}

		if ( isset( $stock_amount ) ) {

			wc_update_product_stock( $product_id, $stock_amount );
			if ( $stock_amount <= 0 ) {
				if ( isset( $backorders ) && 'no' != $backorders ) {
					update_post_meta( $product_id, '_stock_status', 'instock' );
				} else {
					update_post_meta( $product_id, '_stock_status', 'outofstock' );
				}
			} else {
				update_post_meta( $product_id, '_stock_status', 'instock' );
			}
			update_post_meta( $product_id, '_manage_stock', 'yes' );
		} else {
			update_post_meta( $product_id, '_manage_stock', 'no' );
		}

	}

	/**
	 * Add new product attributes
	 *
	 * @param  int   $post_id              The post ID.
	 * @param  array $available_attributes The available attributes.
	 * @param  array $variations           The variations.
	 * @return void
	 */
	private function insert_product_attributes( $post_id, $available_attributes, $variations ) {

		foreach ( $available_attributes as $attribute ) {
			$values = array();

			foreach ( $variations as $variation ) {
				$attribute_keys = array_keys( $variation['woocommerce']['attributes'] );

				foreach ( $attribute_keys as $key ) {
					if ( $key === $attribute ) {
						$values[] = $variation['woocommerce']['attributes'][ $key ];
					}
				}
			}
			$values = array_unique( $values );
			$this->proccess_add_attribute(
				array(
					'attribute_name' => $attribute,
					'attribute_label' => $attribute,
					'attribute_type' => 'text',
					'attribute_orderby' => 'menu_order',
					'attribute_public' => false,
				)
			);
			wp_set_object_terms( $post_id, $values, 'pa_' . $attribute );
		}

		$product_attributes_data = array();

		foreach ( $available_attributes as $attribute ) {
			$product_attributes_data[ 'pa_' . $attribute ] = array(

				'name'         => 'pa_' . $attribute,
				'value'        => '',
				'is_visible'   => '1',
				'is_variation' => '1',
				'is_taxonomy'  => '1',

			);
		}

		update_post_meta( $post_id, '_product_attributes', $product_attributes_data );
	}

	/**
	 *
	 * Synchronize production variations
	 *
	 * @param int   $product_id The product ID.
	 * @param array $variations Variations.
	 */
	private function sync_product_variations( $product_id, $variations ) {

		$synced_variations = array();

		foreach ( $variations as $index => $variation ) {
			$find_variation = $this->find_variation;
			$by = $find_variation['by'];
			if( $by == 'sku' ) {
				$find_variation = array(
					'by' => $by,
					'value' => $variation['woocommerce']['_sku']
				);
			}


			$post_id = $this->find_post( 'product_variation', $find_variation );
			if ( 0 == $post_id ) {
				$post_id = $this->insert_product_variation( $product_id, $index, $variation, count( $variations ) );
			} else {
				$post_id = $this->update_product_variation( $post_id, $variation );
			}

			// Import media.
			if ( isset( $variation['media'] ) ) {
				foreach ( $variation['media']as $key => $item ) {
					$media_sync = new Media_Sync( $item );
					$attachment_id = $media_sync->import_and_attach_to_post( $post_id );

					if ( isset( $item['key'] ) ) {
						update_post_meta( $post_id, $item['key'], $attachment_id );
					}

					if ( isset( $item['featured'] ) && $item['featured'] ) {
						set_post_thumbnail( $post_id, $attachment_id );
					}
				}
			}

			array_push($synced_variations, $post_id);
		}

		$this->clean_up_variations( $product_id, $synced_variations );
	}

	/**
	 * Add new product variations
	 *
	 * @param  int   $product_id       The product ID.
	 * @param  int   $index            Index of the current variation.
	 * @param  array $variation        Variation array.
	 * @param  int   $variations_count Total amount of variations.
	 * @return void
	 */
	private function insert_product_variation( $product_id, $index, $variation, $variations_count ) {

		$variation_post = array(

			'post_title'  => 'Variation #' . $index . ' of ' . $variations_count . ' for product#' . $product_id,
			'post_name'   => 'product-' . $product_id . '-variation-' . $index,
			'post_status' => 'publish',
			'post_parent' => $product_id,
			'post_type'   => 'product_variation',
			'guid'        => home_url() . '/?product_variation=product-' . $product_id . '-variation-' . $index,

		);

		$variation_id = wp_insert_post( $variation_post );

		foreach ( $variation['woocommerce']['attributes'] as $attribute => $value ) {

			$attribute_term = get_term_by( 'name', $value, 'pa_' . $attribute );
			update_post_meta( $variation_id, 'attribute_pa_' . $attribute, $attribute_term->slug );

		}

		$this->sync_variation_meta( $variation_id, $variation );
		$this->sync_product_stock( $variation_id, ( isset( $variation['woocommerce']['_stock'] ) ? $variation['woocommerce']['_stock'] : null ), ( isset( $variation['woocommerce']['_backorders'] ) ? $variation['woocommerce']['_backorders'] : null ) );

		return $variation_id;

	}

	/**
	 * Update product variation
	 *
	 * @param  int   $variation_id The ID of the variation.
	 * @param  array $variation    The variation array.
	 * @return void
	 */
	private function update_product_variation( $variation_id, $variation ) {

		foreach ( $variation['woocommerce']['attributes'] as $attr => $value ) {
			update_post_meta( $variation_id, 'attribute_pa_' . $attr, $value );
		}
		$this->sync_variation_meta( $variation_id, $variation );
		$this->sync_product_stock( $variation_id, ( isset( $variation['woocommerce']['_stock'] ) ? $variation['woocommerce']['_stock'] : null ), ( isset( $variation['woocommerce']['_backorders'] ) ? $variation['woocommerce']['_backorders'] : null ) );

		return $variation_id;

	}

	/**
	 * Sync variation meta
	 *
	 * @param  int   $variation_id The ID of the variation.
	 * @param  array $variation    The variation array.
	 * @return void
	 */
	private function sync_variation_meta( $variation_id, $variation ) {

		$possible_keys = array(
			'_visibility',
			'_stock_status',
			'total_sales',
			'_downloadable',
			'_virtual',
			'_regular_price',
			'_sale_price',
			'_purchase_note',
			'_featured',
			'_weight',
			'_length',
			'_width',
			'_height',
			'_sku',
			'_product_attributes',
			'_sale_price_dates_from',
			'_sale_price_dates_to',
			'_price',
			'_sold_individually',
			'_backorders',
			'_variation_description',
		);
		foreach ( $possible_keys as $key ) {

			if ( isset( $variation['woocommerce'][ $key ] ) ) {
				update_post_meta( $variation_id, $key, $variation['woocommerce'][ $key ] );

			}
		}

	}

	/**
	 * Insert attribute
	 *
	 * @param  array $attribute Attribute array.
	 * @return WP_Error|boolean
	 */
	private function proccess_add_attribute( $attribute ) {
		global $wpdb;

		if ( empty( $attribute['attribute_type'] ) ) {
			$attribute['attribute_type'] = 'text';}
		if ( empty( $attribute['attribute_orderby'] ) ) {
			$attribute['attribute_orderby'] = 'menu_order';}
		if ( empty( $attribute['attribute_public'] ) ) {
			$attribute['attribute_public'] = 0;}

		if ( empty( $attribute['attribute_name'] ) || empty( $attribute['attribute_label'] ) ) {
			return new \WP_Error( 'error', __( 'Please, provide an attribute name and slug.', 'woocommerce' ) );
		} elseif ( ( $valid_attribute_name = $this->valid_attribute_name( $attribute['attribute_name'] ) ) && is_wp_error( $valid_attribute_name ) ) {
			return $valid_attribute_name;
		} elseif ( taxonomy_exists( wc_attribute_taxonomy_name( $attribute['attribute_name'] ) ) ) {
			return new \WP_Error( 'error', sprintf( __( 'Slug "%s" is already in use. Change it, please.', 'woocommerce' ), sanitize_title( $attribute['attribute_name'] ) ) );
		}

		$wpdb->insert( $wpdb->prefix . 'woocommerce_attribute_taxonomies', $attribute );

		do_action( 'woocommerce_attribute_added', $wpdb->insert_id, $attribute );

		flush_rewrite_rules();
		delete_transient( 'wc_attribute_taxonomies' );

		return true;
	}

	/**
	 * Check if attribute name is valid
	 *
	 * @param  string $attribute_name The desired name of the attribute
	 * @return WP_Error|boolean
	 */
	private function valid_attribute_name( $attribute_name ) {
		if ( strlen( $attribute_name ) >= 28 ) {
			return new \WP_Error( 'error', sprintf( __( 'Slug "%s" is too long (28 characters max). Shorten it, please.', 'woocommerce' ), sanitize_title( $attribute_name ) ) );
		} elseif ( wc_check_if_attribute_name_is_reserved( $attribute_name ) ) {
			return new \WP_Error( 'error', sprintf( __( 'Slug "%s" is not allowed because it is a reserved term. Change it, please.', 'woocommerce' ), sanitize_title( $attribute_name ) ) );
		}

		return true;
	}

	private function clean_up_variations( $post_parent, $synced_variations ) {

		$args = array(
			'post_type'         => 'product_variation',
			'posts_per_page'    => -1,
			'post_parent'    	=> $post_parent,
			'post__not_in'      => $synced_variations,
			'post_status'       => get_post_stati(),
		);
		$delete_posts = get_posts( $args );
		foreach ( $delete_posts as $delete_post ) {
			wp_delete_post( $delete_post->ID, true );
		}

	}

}
