<?php

namespace Otomaties\WpSyncPosts;

use League\MimeTypeDetection\FinfoMimeTypeDetector;

class Media
{
    /**
     * The original media url
     *
     * @var string
     */
    private $url = '';

    /**
     * The original media filestream
     *
     * @var string|null
     */
    private $filestream = null;

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
     * @var string|null
     */
    private $filename = null;

    private $removeQueryString = true;

    /**
     * Set object properties
     *
     * @param  array  $media  Media array.
     */
    public function __construct(array $media)
    {
        $defaultMedia = [
            'url' => '',
            'filestream' => null,
            'filename' => null,
            'date_modified' => 'unchanged',
            'title' => strtok(pathinfo($media['url'], PATHINFO_FILENAME), '?'),
            'meta' => [],
            'remove_querystring' => true,
        ];

        $media = wp_parse_args($media, $defaultMedia);

        $this->url = $media['url'];
        $this->filestream = $media['filestream'];
        $this->filename = $media['filename'];
        $this->dateModified = $media['date_modified'];
        $this->title = $media['title'];
        $this->meta = $media['meta'];
        $this->removeQueryString = $media['remove_querystring'];
    }

    private function fileType($file)
    {
        $fileType = wp_check_filetype($file);

        $fileExtAndTypeAreFalse = array_reduce($fileType, function ($carry, $item) {
            return $carry && ($item === false);
        }, true);

        if ($fileExtAndTypeAreFalse) {
            $mimeTypeDetector = new FinfoMimeTypeDetector;
            $mimeType = $mimeTypeDetector->detectMimeTypeFromFile($file);
            switch ($mimeType) {
                case 'image/jpeg':
                    $fileType = [
                        'ext' => 'jpg',
                        'type' => $mimeType,
                    ];
                    break;
                case 'image/png':
                    $fileType = [
                        'ext' => 'png',
                        'type' => $mimeType,
                    ];
                    break;
                case 'image/gif':
                    $fileType = [
                        'ext' => 'gif',
                        'type' => $mimeType,
                    ];
                    break;
                case 'image/bmp':
                    $fileType = [
                        'ext' => 'bmp',
                        'type' => $mimeType,
                    ];
                    break;
                case 'image/tiff':
                    $fileType = [
                        'ext' => 'tiff',
                        'type' => $mimeType,
                    ];
                    break;
                case 'application/pdf':
                    $fileType = [
                        'ext' => 'pdf',
                        'type' => $mimeType,
                    ];
                    break;
                case 'application/msword':
                    $fileType = [
                        'ext' => 'doc',
                        'type' => $mimeType,
                    ];
                    break;
                case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                    $fileType = [
                        'ext' => 'docx',
                        'type' => $mimeType,
                    ];
                    break;
                case 'application/vnd.ms-excel':
                    $fileType = [
                        'ext' => 'xls',
                        'type' => $mimeType,
                    ];
                    break;
                case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                    $fileType = [
                        'ext' => 'xlsx',
                        'type' => $mimeType,
                    ];
                    break;
                case 'application/vnd.ms-powerpoint':
                    $fileType = [
                        'ext' => 'ppt',
                        'type' => $mimeType,
                    ];
                    break;
                case 'application/vnd.openxmlformats-officedocument.presentationml.presentation':
                    $fileType = [
                        'ext' => 'pptx',
                        'type' => $mimeType,
                    ];
                    break;
                default:
                    Logger::log('An error occured while downloading the attachment');
                    Logger::log(print_r($file, true));

                    return null;
            }
        }

        return $fileType;
    }

    private function fileName(array $fileType)
    {
        $filename = $this->filename ?? strtok(basename($this->url), '?');

        if (strpos($filename, $fileType['ext']) === false) {
            $filename = $filename.'.'.$fileType['ext'];
        }

        return $filename;
    }

    /**
     * Import and attach media
     *
     * @param  int|null  $postId  The ID of the post to attach media to
     * @return int|null The attachment ID
     */
    public function importAndAttachToPost($postId = null): ?int
    {

        if (! $this->url || $this->url == '') {
            Logger::log(sprintf('No attachment url given for post %s', $postId));

            return null;
        }

        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/image.php';

        $url = $this->removeQueryString ? strtok($this->url, '?') : $this->url;

        // Check if the exact file exists.
        $args = [
            'post_type' => 'attachment',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => 'original_url',
                    'value' => $url,
                ],
                [
                    'key' => 'original_date_modified',
                    'value' => $this->dateModified,
                ],
            ],
        ];

        $existingMedia = get_posts($args);

        Logger::log(sprintf(
            'Searching for existing attachment with original_url %s and original_modified_date %s',
            $url,
            $this->dateModified
        ));
        if (! empty($existingMedia)) {
            $attachmentId = $existingMedia[0]->ID;
            Logger::log(sprintf('Found attachment with ID #%s', $attachmentId));

            return $attachmentId;
        } else {
            Logger::log('No existing attachment found');
        }

        // Check if file is modified.
        $args = [
            'post_type' => 'attachment',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => 'original_url',
                    'value' => $url,
                ],
            ],
        ];

        // delete attachment if it is modified
        $existingMedia = get_posts($args);
        if (! empty($existingMedia)) {
            $attachmentId = $existingMedia[0]->ID;
            Logger::log(sprintf('Found modified attachment with ID #%s. Deleting ...', $attachmentId));
            wp_delete_attachment($attachmentId, true);
        } else {
            Logger::log('No modified attachment found');
        }

        Logger::log('Creating new attachment');

        if (isset($this->filestream)) {
            if (is_callable($this->filestream)) {
                try {
                    $this->filestream = call_user_func($this->filestream);
                } catch (\Exception $e) {
                    Logger::log($e->getMessage().' '.$this->url);

                    return null;
                }
            }
            // save filestream to temp file
            $tempFile = tempnam(sys_get_temp_dir(), 'wp-sync-posts');
            file_put_contents($tempFile, $this->filestream);
        } else {
            // Download file to temp dir.
            $timeOutInSeconds = 20;
            $tempFile = download_url($this->url, $timeOutInSeconds);
        }

        if (is_wp_error($tempFile)) {
            Logger::log('An error occured while downloading the attachment');
            Logger::log(print_r($tempFile, true));

            return null;
        }

        $fileType = $this->fileType($tempFile);

        $file = [
            'name' => $this->fileName($fileType),
            'type' => $fileType,
            'tmp_name' => $tempFile,
            'error' => 0,
            'size' => filesize($tempFile),
        ];

        $overrides = [
            'test_form' => false,
            'test_size' => true,
        ];

        $result = wp_handle_sideload($file, $overrides);

        if (! empty($result['error'])) {
            Logger::log('An error occured while importing the attachment');
            Logger::log(print_r($result, true));
            @unlink($tempFile);

            return null;
        }

        $filename = $result['file'];
        $type = $result['type'];

        $attachment = [
            'post_mime_type' => $type,
            'post_title' => $this->title,
            'post_content' => ' ',
            'post_status' => 'inherit',
        ];

        $attachId = wp_insert_attachment($attachment, $filename, $postId);

        $attach_data = wp_generate_attachment_metadata($attachId, $filename);
        wp_update_attachment_metadata($attachId, $attach_data);

        $required_meta = [
            'original_url' => $url,
            'original_date_modified' => $this->dateModified,
        ];

        $meta = array_merge($this->meta, $required_meta);

        foreach ($meta as $key => $value) {
            add_post_meta($attachId, $key, $value);
        }

        Logger::log(sprintf('New attachment created with ID %s', $attachId));
        @unlink($tempFile);

        return $attachId;
    }
}
