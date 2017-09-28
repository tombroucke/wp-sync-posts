<?php

class Wp_Post_Syncer{

	public $post_type;
	public $debug;
	private $synced_ids = array();

	/**
	 * @param string $post_type Required.
	 * @param boolean $debug
	 */
	function __construct( $post_type, $debug = false ){

		$this->post_type = $post_type;
		$this->debug = $debug;

	}

	/**
	 *
	 * Check if a post exist, create or update it
	 *
	 * @param    array $qualifier Required. see @param $by in find_post_by()
	 * @param    array $postargs Required. see @param $value in find_post_by()
	 * @return   mixed
	 *
	 */
	public function sync_post( $qualifier, $postargs ){

		$post = $this->find_post_by( $qualifier['by'], $qualifier['value'], ( isset( $qualifier['params'] ) ? $qualifier['params'] : array() ) );
		if( !$post ){
			$this->save_post( $postargs );
		}
		else{
			$this->update_post( $post, $postargs );
		}

	}

	/**
	 *
	 * Search for existing post
	 *
	 * @param    string $by Required. Options: post_id, meta_value
	 * @param    mixed $value Required. Can be string for post_id or array for meta_value
	 * @param    array $params Optional. Options: post_type, post_status
	 * @return   mixed
	 *
	 */
	private function find_post_by( $by, $value, $params = array() ){

		switch ( $by ) {
			case 'post_id':
				return ( get_post_status( $value ) ? $value : false );
				break;
			case 'meta_value':
				$args = array(
					'post_type' => $this->post_type,
					'post_status' => ( isset( $params['posts_status'] ) ? $params['posts_status'] : get_post_stati() ),
					'posts_per_page' => 1,
					'fields' => 'ids',
					'meta_query' => array(
						array(
							'key' => $value['key'],
							'value' => $value['value'],
							'compare' => '='
						)
					)
				);

				$this->debug( $args, 'Arguments to find post' );

				$vacancies = get_posts( $args );

				if( isset( $vacancies[0] ) ){
					return $vacancies[0];
				}
				else{
					return false;
				}
			break;
			
			default:
				return;
			break;
		}

	}

	/**
	 *
	 * Save new post
	 *
	 * @param    array $args Required. Options: post_title, post_type, post_status, post_date, post_name, ...
	 * @return   int $postid
	 *
	 */
	private function save_post( $args ){

		$postarr = array(
			'post_title'    => $args['post_title'],
			'post_type'		=> $this->post_type,
			'post_status'   => ( isset( $args['post_status'] ) ? $args['post_status'] : 'publish' ),
			'post_name'		=> ( isset( $args['post_name'] ) ? $args['post_name'] : $args['post_title'] ),
		);

		$extra_parameters = array(
			'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_content_filtered', 'post_excerpt', 'comment_status', 'ping_status', 'post_password', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_parent', 'menu_order', 'post_mime_type', 'guid', 'post_category', 'tax_input', 'meta_input'
		);
		foreach ( $extra_parameters as $parameter ) {
			if( isset( $args[$parameter] ) ){
				$postarr[$parameter] = $args[$parameter];
			}
		}
		$post_id = wp_insert_post( $postarr, $wp_error = false );

		array_push( $this->synced_ids, $post_id );
		return $post_id;

	}

	/**
	 *
	 * Update existing post
	 *
	 * @param    int $id Required. post ID
	 * @param    array $args Required. Options: post_title, post_type, post_status, post_date, post_name, ...
	 * @return   int $postid
	 *
	 */
	private function update_post( $id, $args ){		

		$postarr = array(
			'ID'			=> $id,
			'post_title'    => $args['post_title'],
			'post_type'		=> $this->post_type,
			'post_status'   => ( isset( $args['post_status'] ) ? $args['post_status'] : 'publish' ),
			'post_name'		=> ( isset( $args['post_name'] ) ? $args['post_name'] : $args['post_title'] )
		);

		$extra_parameters = array(
			'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_content_filtered', 'post_excerpt', 'comment_status', 'ping_status', 'post_password', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_parent', 'menu_order', 'post_mime_type', 'guid', 'post_category', 'tax_input', 'meta_input'
		);
		foreach ( $extra_parameters as $parameter ) {
			if( isset( $args[$parameter] ) ){
				$postarr[$parameter] = $args[$parameter];
			}
		}
		$post_id = wp_update_post( $postarr );

		array_push( $this->synced_ids, $post_id );
		return $post_id;

	}

	/**
	 *
	 * Remove unsynced posts
	 *
	 * @param    array $params Required. Options: post_type, force_delete
	 *
	 */
	public function clean_up( $params = array() ){

		if( !isset( $this->post_type ) ){
			return new WP_Error( 'Error', 'Undefined attribute \'post_type\'' );
		}
		$args = array(
			'post_type' => $this->post_type,
			'posts_per_page' => -1,
			'post__not_in' => $this->synced_ids
		);
		$delete_posts = get_posts( $args );
		foreach( $delete_posts as $delete_post ){
			wp_delete_post( $delete_post->ID, ( isset( $params['force_delete'] ) ? $params['force_delete'] : false ) );
		}

	}

	/**
	 *
	 * Show debug information
	 *
	 * @param    array $value Required.
	 * @param    string $comment Optional. 
	 *
	 */
	private function debug( $value, $comment = '' ){

		if( $this->debug ){

			echo $comment;
			echo '<pre>';
			print_r( $value );
			echo '</pre>';

		}

	}

}
