<?php
namespace Otomaties\WpSyncPosts;

class Media
{
    /**
     * The original media url
     *
     * @var string
     */
    private $url = '';

    /**
     * The original media date modified (Y-m-d H:i:s)
     *
     * @var string
     */
    private $dateModified = '';

    /**
     * The media title
     *
     * @var string
     */
    private $title = '';

    /**
     * Attachment meta
     *
     * @var array
     */
    private $meta = [];

    /**
     * The filename to use for the media
     *
     * @var string
     */
    private $filename = null;

    private $removeQueryString = true;

    /**
     * Set object properties
     *
     * @param array $media Media array.
     */
    public function __construct(array $media)
    {
        $default_media = array(
            'url'                   => '',
            'filename'              => null,
            'date_modified'         => 'unchanged',
            'title'                 => strtok(pathinfo($media['url'], PATHINFO_FILENAME), '?'),
            'meta'                  => [],
            'remove_querystring'    => true,
        );

        $media = wp_parse_args($media, $default_media);

        $this->url                  = $media['url'];
        $this->filename             = $media['filename'];
        $this->dateModified         = $media['date_modified'];
        $this->title                = $media['title'];
        $this->meta                 = $media['meta'];
        $this->removeQueryString    = $media['remove_querystring'];
    }

    /**
     * Import and attach media
     *
     * @param [type] $postId    The ID of the post to attach media to
     * @return integer|null     The attachment ID
     */
    public function importAndAttachToPost($postId) : ?int
    {

        if (!$this->url || $this->url == '') {
            Logger::log(sprintf('No attachment url given for post %s', $postId));
            return null;
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $url = $this->removeQueryString ? strtok($this->url, '?') : $this->url;

        // Check if the exact file exists.
        $args = array(
            'post_type'      => 'attachment',
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'    => 'original_url',
                    'value'  => $url,
                ),
                array(
                    'key'    => 'original_date_modified',
                    'value'  => $this->dateModified,
                ),
            ),
        );
        
        $existingMedia = get_posts($args);

        Logger::log(sprintf('Searching for existing attachment with original_url %s and original_modified_date %s', $url, $this->dateModified));
        if (! empty($existingMedia)) {
            $attachmentId = $existingMedia[0]->ID;
            Logger::log(sprintf('Found attachment with ID #%s', $attachmentId));
            return $attachmentId;
        } else {
            Logger::log('No existing attachment found');
        }

        // Check if file is modified.
        $args = array(
            'post_type'      => 'attachment',
            'posts_per_page' => 1,
            'meta_query'     => array(
                array(
                    'key'    => 'original_url',
                    'value'  => $url,
                ),
            ),
        );

        // delete attachment if it is modified
        $existingMedia = get_posts($args);
        if (!empty($existingMedia)) {
            $attachmentId = $existingMedia[0]->ID;
            Logger::log(sprintf('Found modified attachment with ID #%s. Deleting ...', $attachmentId));
            wp_delete_attachment($attachmentId, true);
        } else {
            Logger::log('No modified attachment found');
        }

        Logger::log('Creating new attachment');
        // Download file to temp dir.
        $timeOutInSeconds = 20;
        $tempFile = download_url($this->url, $timeOutInSeconds);

        if (is_wp_error($tempFile)) {
            Logger::log('An error occured while downloading the attachment');
            Logger::log(print_r($tempFile, 1));
            return null;
        }

        $file = array(
            'name'     => $this->filename ?? strtok(basename($this->url), '?'),
            'type'     => wp_check_filetype($tempFile),
            'tmp_name' => $tempFile,
            'error'    => 0,
            'size'     => filesize($tempFile),
        );

        $overrides = array(
            'test_form' => false,
            'test_size' => true,
        );

        $result = wp_handle_sideload($file, $overrides);

        if (!empty($result['error'])) {
            Logger::log('An error occured while importing the attachment');
            Logger::log(print_r($result, 1));
            return null;
        }

        $filename  = $result['file'];
        $type  = $result['type'];

        $attachment = array(
            'post_mime_type' => $type,
            'post_title'     => $this->title,
            'post_content'   => ' ',
            'post_status'    => 'inherit',
        );

        $attachId = wp_insert_attachment($attachment, $filename, $postId);

        $attach_data = wp_generate_attachment_metadata($attachId, $filename);
        wp_update_attachment_metadata($attachId, $attach_data);

        $required_meta = array(
            'original_url' => $url,
            'original_date_modified' => $this->dateModified,
        );

        $meta = array_merge($this->meta, $required_meta);

        foreach ($meta as $key => $value) {
            add_post_meta($attachId, $key, $value);
        }

        Logger::log(sprintf('New attachment created with ID %s', $attachId));
        return $attachId;
    }
}
