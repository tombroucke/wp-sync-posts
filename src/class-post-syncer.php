<?php

class WP_Post_Syncer{

	public $debug;
	protected $synced_ids = array();

	/**
	 * @param string $post_type Required.
	 * @param boolean $debug
	 */
	function __construct( $debug = false ){

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
	public function sync_post( $postargs ){

		$post = $this->find_post( $postargs['post_type'], $postargs['qualifier'] );
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
	 * @param    array $qualifier Required. Options: by, key, value, post_status 
	 * @return   mixed
	 *
	 */
	protected function find_post( $post_type, $qualifier ){

		switch ( $qualifier['by'] ) {
			case 'post_id':
				return ( get_post_status( $qualifier['value'] ) ? $qualifier['value'] : false );
				break;
			case 'meta_value':
				$args = array(
					'post_type' => $post_type,
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
			
			default:
				return;
			break;
		}

	}

	/**
	 *
	 * Save new post
	 *
	 * @param    array $args Required. Options: post_title, post_status, post_date, post_name, ...
	 * @return   int $postid
	 *
	 */
	protected function save_post( $args ){

		$postarr = array(
			'post_type'		=> $args['post_type'],
			'post_status'   => ( isset( $args['post_status'] ) ? $args['post_status'] : 'publish' ),
		);

		$extra_parameters = array(
			'post_title',
			'post_name',
			'post_author',
			'post_date',
			'post_date_gmt',
			'post_content',
			'post_content_filtered',
			'post_excerpt',
			'comment_status',
			'ping_status',
			'post_password',
			'to_ping',
			'pinged',
			'post_modified',
			'post_modified_gmt',
			'post_parent',
			'menu_order',
			'post_mime_type',
			'guid',
			'post_category',
			'tax_input',
			'meta_input'
		);

		foreach ( $extra_parameters as $parameter ) {
			if( isset( $args[$parameter] ) ){
				$postarr[$parameter] = $args[$parameter];
			}
		}
		$post_id = wp_insert_post( $postarr, $wp_error = false );
		
		$this->manipulate( $post_id, $args );

		array_push( $this->synced_ids, $post_id );
		return $post_id;

	}


	/**
	 *
	 * Update existing post
	 *
	 * @param    int $post_id Required. post ID
	 * @param    array $args Required. Options: qualifier, post_title, post_status, post_date, post_name, ...
	 * @return   int $postid
	 *
	 */
	protected function manipulate( $post_id, $args ){

		return $post_id;

	}

	/**
	 *
	 * Update existing post
	 *
	 * @param    int $id Required. post ID
	 * @param    array $args Required. Options: qualifier, post_title, post_status, post_date, post_name, ...
	 * @return   int $postid
	 *
	 */
	protected function update_post( $id, $args ){		

		$postarr = array(
			'ID'			=> $id,
			'post_type'		=> $args['post_type'],
			'post_status'   => ( isset( $args['post_status'] ) ? $args['post_status'] : 'publish' )
		);

		$extra_parameters = array(
			'post_title', 'post_name', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_content_filtered', 'post_excerpt', 'comment_status', 'ping_status', 'post_password', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_parent', 'menu_order', 'post_mime_type', 'guid', 'post_category', 'tax_input', 'meta_input'
		);
		foreach ( $extra_parameters as $parameter ) {
			if( isset( $args[$parameter] ) ){
				$postarr[$parameter] = $args[$parameter];
			}
		}
		$post_id = wp_update_post( $postarr );

		$this->manipulate( $post_id, $args );

		array_push( $this->synced_ids, $post_id );
		return $post_id;

	}

	/**
	 *
	 * Remove unsynced posts
	 *
	 * @param    array $params Required. Options: force_delete
	 *
	 */
	public function clean_up( $post_type, $params = array() ){

		if( !isset( $post_type ) ){
			return new WP_Error( 'Error', 'Undefined attribute \'post_type\'' );
		}
		$args = array(
			'post_type' => $post_type,
			'posts_per_page' => -1,
			'post__not_in' => $this->synced_ids,
			'post_status' => array('publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash')
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
	protected function debug( $value, $comment = '' ){

		if( $this->debug ){

			echo $comment;
			echo '<pre>';
			print_r( $value );
			echo '</pre>';

		}

	}

}
