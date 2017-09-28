<?php

class WP_Product_Syncer extends WP_Post_Syncer{

	/**
	 */
	function __construct( $debug = false ){

		$this->post_type = 'product';
		$this->debug = $debug;

	}

	/**
	 *
	 * Search for existing post
	 *
	 * @param    array $qualifier Required. Options: by, key, value, post_status 
	 * @return   mixed
	 *
	 */
	protected function find_post( $qualifier ){

		switch ( $qualifier['by'] ) {
			case 'post_id':
			return ( get_post_status( $qualifier['value'] ) ? $qualifier['value'] : false );
			break;
			case 'meta_value':
			$args = array(
				'post_type' => $this->post_type,
				'post_status' => ( isset( $qualifier['posts_status'] ) ? $qualifier['posts_status'] : get_post_stati() ),
				'posts_per_page' => 1,
				'fields' => 'ids',
				'meta_query' => array(
					array(
						'key' => $qualifier['key'],
						'value' => $qualifier['value'],
						'compare' => '='
					)
				)
			);

			$this->debug( $args, 'Arguments to find post' );

			$posts = get_posts( $args );

			if( isset( $posts[0] ) ){
				return $posts[0];
			}
			else{
				return false;
			}
			break;
				case 'sku':
				return wc_get_product_id_by_sku( $qualifier['value'] ) ;
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
	 * @param    int $product_id Required.
	 * @param    array $args Required. Options: qualifier, post_title, post_status, post_date, post_name, ...
	 * @return   int $post_id
	 *
	 */
	protected function manipulate( $product_id, $args ){

		$wc_args = $args['woocommerce'];

		// Add product meta
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
		foreach ($possible_keys as $key) {
			if( isset( $wc_args[$key] ) ){
				update_post_meta( $product_id, $key, $wc_args[$key] );
			}
		}

		// Sync stock
		$this->sync_product_stock( $product_id, ( isset($wc_args['_stock']) ? $wc_args['_stock'] : null), ( isset($wc_args['_backorders']) ? $wc_args['_backorders'] : null) );

		// Set categories
		if( isset( $wc_args['product_cat'] ) && is_array( $wc_args['product_cat'] ) ){
			wp_set_post_terms($product_id, $wc_args['product_cat'], 'product_cat');	
		}

		// Set product type
		if( isset( $wc_args['product_type'] ) ){
			wp_set_object_terms( $product_id, $wc_args['product_type'], 'product_type' );
		}
		else{
			wp_set_object_terms( $product_id, 'simple', 'product_type' );
		}

		// Variations
		if( isset( $wc_args['product_type'] ) && isset( $wc_args['product_type'] ) == 'variation' ){

			// Create attributes
			$this->insert_product_attributes( $product_id, $wc_args['available_attributes'], $wc_args['variations'] );
			// Create variations
			$this->sync_product_variations( $product_id, $wc_args['variations'] );

		}

		return $product_id;

	}

	/**
	 *
	 * Sync product stock, set backorders, manage stock
	 *
	 * @param    int $product_id Required
	 * @param    int $stock_amount Required
	 * @param    string $backorders Required
	 *
	 */
	private function sync_product_stock( $product_id, $stock_amount, $backorders ){

		if( !isset( $backorders ) ){
			update_post_meta( $product_id, '_backorders', 'no' );
		}
		if( isset( $stock_amount ) ){
			
			wc_update_product_stock( $product_id, $stock_amount );
			if( $stock_amount <= 0  ){
				if( isset( $backorders ) && $backorders != 'no' ){
					update_post_meta( $product_id, '_stock_status', 'instock' );
				}
				else{
					update_post_meta( $product_id, '_stock_status', 'outofstock' );
				}
			}
			else{
				update_post_meta( $product_id, '_stock_status', 'instock' );
			}
			update_post_meta( $product_id, '_manage_stock', 'yes' );
		}
		else{
			update_post_meta( $product_id, '_manage_stock', 'no' );
		}

	}

	/**
	 *
	 * Add new product attributes
	 *
	 * @param    int $post_id Required
	 * @param    array $available_attributes Required
	 * @param    array $variations Required
	 *
	 */
	private function insert_product_attributes ($post_id, $available_attributes, $variations){

		foreach ($available_attributes as $attribute){   
			$values = array();

			foreach ($variations as $variation){
				$attribute_keys = array_keys($variation['attributes']);

				foreach ($attribute_keys as $key){
					if ($key === $attribute){
						$values[] = $variation['attributes'][$key];
					}
				}
			}
			$values = array_unique($values);
			$this->proccess_add_attribute( array( 'attribute_name' => $attribute, 'attribute_label' => $attribute, 'attribute_type' => 'text', 'attribute_orderby' => 'menu_order', 'attribute_public' => false ) );
			wp_set_object_terms( $post_id, $values, 'pa_' . $attribute );
		}

		$product_attributes_data = array();

		foreach ($available_attributes as $attribute){
			$product_attributes_data['pa_' . $attribute] = array(

				'name'         => 'pa_' . $attribute,
				'value'        => '',
				'is_visible'   => '1',
				'is_variation' => '1',
				'is_taxonomy'  => '1'

			);
		}

		update_post_meta($post_id, '_product_attributes', $product_attributes_data);
	}

	/**
	 *
	 * Synchronize production variations
	 *
	 * @param    int $product_id Required
	 * @param    array $variations Required
	 *
	 */
	private function sync_product_variations ( $product_id, $variations )  {

		foreach ($variations as $index => $variation){

			$qualifier = array(
				'by' => $variation['qualifier']['by'],
				'value' => $variation['qualifier']['value'],
			);
			$var_product = $this->find_post( $qualifier );

			if( $var_product == 0 ){
				$this->insert_product_variation( $product_id, $index, $variation, count( $variations ) );
			}
			else{
				$this->update_product_variation( $var_product, $variation );
			}
		}
	}

	/**
	 *
	 * Add new product variations
	 *
	 * @param    int $product_id Required
	 *
	 */
	private function insert_product_variation( $product_id, $index, $variation, $variations_count ){

		
		$variation_post = array( 

			'post_title'  => 'Variation #' . $index . ' of ' . $variations_count . ' for product#' . $product_id,
			'post_name'   => 'product-' . $product_id . '-variation-' . $index,
			'post_status' => 'publish',
			'post_parent' => $product_id,
			'post_type'   => 'product_variation',
			'guid'        => home_url() . '/?product_variation=product-' . $product_id . '-variation-' . $index

		);

		$variation_id = wp_insert_post( $variation_post );

		foreach ($variation['attributes'] as $attribute => $value){

			$attribute_term = get_term_by( 'name', $value, 'pa_' . $attribute);
			update_post_meta( $variation_id, 'attribute_pa_' . $attribute, $attribute_term->slug );

		}

		$this->sync_variation_meta( $variation_id, $variation );
		$this->sync_product_stock( $variation_id, ( isset($variation['_stock']) ? $variation['_stock'] : null), ( isset($variation['_backorders']) ? $variation['_backorders'] : null) );

	}

	private function update_product_variation( $variation_id, $variation ){

		foreach ($variation['attributes'] as $attr => $value) {
			update_post_meta( $variation_id, 'attribute_pa_' . $attr, $value );
		}
		$this->sync_variation_meta( $variation_id, $variation );
		$this->sync_product_stock( $variation_id, ( isset($variation['_stock']) ? $variation['_stock'] : null), ( isset($variation['_backorders']) ? $variation['_backorders'] : null) );

	}

	private function sync_variation_meta( $variation_id, $variation ){

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
		foreach ($possible_keys as $key) {

			if( isset( $variation[$key] ) ){
				update_post_meta( $variation_id, $key, $variation[$key] );

			}

		}

	}

	private function proccess_add_attribute($attribute){
		global $wpdb;

		if (empty($attribute['attribute_type'])) { $attribute['attribute_type'] = 'text';}
		if (empty($attribute['attribute_orderby'])) { $attribute['attribute_orderby'] = 'menu_order';}
		if (empty($attribute['attribute_public'])) { $attribute['attribute_public'] = 0;}

		if ( empty( $attribute['attribute_name'] ) || empty( $attribute['attribute_label'] ) ) {
			return new WP_Error( 'error', __( 'Please, provide an attribute name and slug.', 'woocommerce' ) );
		} elseif ( ( $valid_attribute_name = $this->valid_attribute_name( $attribute['attribute_name'] ) ) && is_wp_error( $valid_attribute_name ) ) {
			return $valid_attribute_name;
		} elseif ( taxonomy_exists( wc_attribute_taxonomy_name( $attribute['attribute_name'] ) ) ) {
			return new WP_Error( 'error', sprintf( __( 'Slug "%s" is already in use. Change it, please.', 'woocommerce' ), sanitize_title( $attribute['attribute_name'] ) ) );
		}

		$wpdb->insert( $wpdb->prefix . 'woocommerce_attribute_taxonomies', $attribute );

		do_action( 'woocommerce_attribute_added', $wpdb->insert_id, $attribute );

		flush_rewrite_rules();
		delete_transient( 'wc_attribute_taxonomies' );

		return true;
	}

	private function valid_attribute_name( $attribute_name ) {
		if ( strlen( $attribute_name ) >= 28 ) {
			return new WP_Error( 'error', sprintf( __( 'Slug "%s" is too long (28 characters max). Shorten it, please.', 'woocommerce' ), sanitize_title( $attribute_name ) ) );
		} elseif ( wc_check_if_attribute_name_is_reserved( $attribute_name ) ) {
			return new WP_Error( 'error', sprintf( __( 'Slug "%s" is not allowed because it is a reserved term. Change it, please.', 'woocommerce' ), sanitize_title( $attribute_name ) ) );
		}

		return true;
	}


}
