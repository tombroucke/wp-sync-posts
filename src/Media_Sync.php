<?php //phpcs:ignore
namespace Otomaties\WP_Post_Sync;

/**
 * Sync WordPress media
 */
class Media_Sync {

	/**
	 * The original media url
	 *
	 * @var string
	 */
	private $url;

	/**
	 * The original media date modified (Y-m-d H:i:s)
	 *
	 * @var string
	 */
	private $date_modified;

	/**
	 * The media title
	 *
	 * @var string
	 */
	private $title;

	/**
	 * Attachment meta
	 *
	 * @var string
	 */
	private $meta;

	/**
	 * Set object properties
	 *
	 * @param array $media Media array.
	 */
	public function __construct( $media ) {

		$default_media = array(
			'url'           => '',
			'date_modified' => 'unchanged',
			'title'         => strtok( pathinfo( $media['url'], PATHINFO_FILENAME ), '?' ),
			'meta'          => array(),
		);

		$media = wp_parse_args( $media, $default_media );

		$this->url           = $media['url'];
		$this->date_modified = $media['date_modified'];
		$this->title         = $media['title'];
		$this->meta          = $media['meta'];

	}

	/**
	 * Import and attach media
	 *
	 * @param  int $post_id The ID of the post to attach media to.
	 * @return int          The attachment ID
	 */
	public function import_and_attach_to_post( $post_id ) {

		require_once( ABSPATH . 'wp-admin/includes/file.php' );
		require_once( ABSPATH . 'wp-admin/includes/image.php' );

		// Check if the exact file exists.
		$args = array(
			'post_type'      => 'attachment',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'    => 'original_url',
					'value'  => strtok($this->url, '?'),
				),
				array(
					'key'    => 'original_date_modified',
					'value'  => $this->date_modified,
				),
			),
		);

		$existing_media = get_posts( $args );
		if ( ! empty( $existing_media ) ) {
			return $existing_media[0]->ID;
		}

		// Check if file is modified.
		$args = array(
			'post_type'      => 'attachment',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'    => 'original_url',
					'value'  => strtok($this->url, '?'),
				),
			),
		);

		$existing_media = get_posts( $args );
		if ( ! empty( $existing_media ) ) {
			wp_delete_attachment( $existing_media[0]->ID, true );
		}

		// Download file to temp dir.
		$timeout_seconds = 10;
		$temp_file = download_url( $this->url, $timeout_seconds );

		if ( ! is_wp_error( $temp_file ) ) {
			$file = array(
				'name'     => strtok( basename( $this->url ), '?' ), // image.png.
				'type'     => 'image/png',
				'tmp_name' => $temp_file,
				'error'    => 0,
				'size'     => filesize( $temp_file ),
			);

			$overrides = array(
				'test_form' => false,
				'test_size' => true,
			);

			$result = wp_handle_sideload( $file, $overrides );

			if ( ! empty( $result['error'] ) ) {
				return false;
			} else {
				$filename  = $result['file']; // Full path to the file.
				$local_url = $result['url'];  // URL to the file in the uploads dir.
				$type      = $result['type']; // MIME type of the file.

				$attachment = array(
					'post_mime_type' => $result['type'],
					'post_title'     => $this->title,
					'post_content'   => ' ',
					'post_status'    => 'inherit',
				);

				$attach_id = wp_insert_attachment( $attachment, $result['file'], $post_id );

				$attach_data = wp_generate_attachment_metadata( $attach_id, $result['file'] );
				wp_update_attachment_metadata( $attach_id, $attach_data );

				$required_meta = array(
					'original_url' => strtok( $this->url, '?'),
					'original_date_modified' => $this->date_modified,
				);

				$meta = array_merge( $this->meta, $required_meta );

				foreach ( $meta as $key => $value ) {
					add_post_meta( $attach_id, $key, $value );
				}

				return $attach_id;
			}
		}

	}

}
