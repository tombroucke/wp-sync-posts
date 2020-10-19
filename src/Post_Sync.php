<?php //phpcs:ignore
namespace Otomaties\WP_Post_Sync;

/**
 * Sync WordPress posts with an external API
 */
class Post_Sync {

	/**
	 * An array of post ids which have been synced
	 *
	 * @var array
	 */
	protected $synced_post_ids = array();

	/**
	 * Merge default array, find and sync post
	 *
	 * @param  array $args WP Post arguments.
	 * @param  array $find Arguments to find existing post.
	 * @return int         Updated/created post ID
	 */
	public function sync( $args, $find ) {

		$defaults = array(
			'post_type'    => 'post',
			'post_title'   => '',
			'post_content' => '',
			'post_status'  => 'publish',
		);

		$args = wp_parse_args( $args, $defaults );

		$args['ID'] = $this->find_post( $args['post_type'], $find );
		return $this->save_post( $args );

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

			default:
				return false;
			break;
		}

	}

	/**
	 * Insert post into database
	 *
	 * @param  array $args Post arguments.
	 * @return int         Post ID
	 */
	protected function save_post( $args ) {

		$media = isset( $args['media'] ) ? $args['media'] : false;

		$available_parameters = array(
			'ID',
			'post_author',
			'post_date',
			'post_date_gmt',
			'post_content',
			'post_content_filtered',
			'post_title',
			'post_excerpt',
			'post_status',
			'post_type',
			'comment_status',
			'ping_status',
			'post_password',
			'post_name',
			'to_ping',
			'pinged',
			'post_modified',
			'post_modified_gmt',
			'post_parent',
			'menu_order',
			'post_mime_type',
			'guid',
			'post_category',
			'tags_input',
			'tax_input',
			'meta_input',
		);

		foreach ( $args as $key => $value ) {
			if ( ! in_array( $key, $available_parameters ) ) {
				unset( $args[ $key ] );
			}
		}

		$post_id = wp_insert_post( $args, true );

		// Import media.
		if ( $media ) {
			foreach ( $media as $key => $item ) {
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

		if ( is_numeric( $post_id ) ) {
			$this->add_to_synced_post_ids( $post_id );
		}
		return $post_id;

	}

	public function clean_up( $post_type, $force_delete = false ) {

		$args = array(
			'post_type'         => $post_type,
			'posts_per_page'    => -1,
			'post__not_in'      => $this->synced_post_ids(),
			'post_status'       => get_post_stati(),
		);
		$delete_posts = get_posts( $args );
		foreach ( $delete_posts as $delete_post ) {
			wp_delete_post( $delete_post->ID, $force_delete );
		}

	}

	private function add_to_synced_post_ids( $post_id ) {
		array_push( $this->synced_post_ids, $post_id );
	}

	private function synced_post_ids() {
		return $this->synced_post_ids;
	}

}
