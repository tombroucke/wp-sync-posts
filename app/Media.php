<?php

namespace Otomaties\WpSyncPosts;

use Illuminate\Support\Str;
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
     * Media meta
     *
     * @var \Illuminate\Support\Collection<string, mixed>
     */
    private $meta;

    /**
     * The filename to use for the media
     *
     * @var string|null
     */
    private $filename = null;

    /**
     * Supported MIME type mappings
     */
    private const MIME_TYPE_MAPPINGS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/bmp' => 'bmp',
        'image/tiff' => 'tiff',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];

    /**
     * Set object properties
     *
     * @param array<string, mixed> $media Media array.
     */
    public function __construct(array $media)
    {
        $this->url = $media['url'] ?? '';
        $this->filestream = $media['filestream'] ?? null;
        $this->filename = $media['filename'] ?? null;
        $this->dateModified = $media['date_modified'] ?? 'unchanged';
        $this->title = $media['title'] ?? strtok(pathinfo($media['url'], PATHINFO_FILENAME), '?');

        /** @var array<string, mixed> $metaData */
        $metaData = $media['meta'] ?? [];
        $this->meta = collect($metaData);
    }

    /**
     * Determine the file type using WordPress functions or custom detection
     *
     * @param string $file The path to the file
     * @return array<string, string>|null Array with 'ext' and 'type' keys or null if unsupported
     */
    private function determineFileType(string $file): ?array
    {
        $fileType = wp_check_filetype($file);

        if ($fileType['ext'] !== false && $fileType['type'] !== false) {
            return $fileType;
        }

        // Fall back to custom MIME detection
        return $this->customDetectMimeType($file);
    }

    /**
     * Custom MIME type detection using finfo
     *
     * @param string $file The path to the file
     * @return array<string, string>|null Array with 'ext' and 'type' keys or null if unsupported
     */
    private function customDetectMimeType(string $file): ?array
    {
        $mimeTypeDetector = new FinfoMimeTypeDetector();
        $mimeType = $mimeTypeDetector->detectMimeTypeFromFile($file);

        if (!isset(self::MIME_TYPE_MAPPINGS[$mimeType])) {
            Logger::log("Unsupported MIME type detected: {$mimeType}");
            Logger::log("File: " . print_r($file, true));
            return null;
        }

        return [
            'ext' => self::MIME_TYPE_MAPPINGS[$mimeType],
            'type' => $mimeType,
        ];
    }

    /**
     * Generate a filename with the correct extension
     *
     * @param string $extension The file extension
     * @return string The generated filename
     */
    private function generateFileName(string $extension)
    {
        $baseFilename = $this->filename ?? $this->extractFilenameFromUrl();

        return Str::of($baseFilename)
            ->beforeLast('.' . $extension)
            ->append('.' . $extension)
            ->toString();
    }

    /**
     * Extract filename from URL
     *
     * @return string The extracted filename
     */
    private function extractFilenameFromUrl(): string
    {
        return strtok(basename($this->url), '?');
    }

    /**
     * Find existing media by original URL and date modified
     *
     * @param string $url The original media URL
     * @param string $dateModified The original media date modified (Y-m-d H:i:s)
     * @return int|null The attachment ID if found, null otherwise
     */
    public function findExistingMedia(string $url, string $dateModified): ?int
    {
        $existingMedia = get_posts([
            'post_type' => 'attachment',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => 'original_url',
                    'value' => $url,
                ],
                [
                    'key' => 'original_date_modified',
                    'value' => $dateModified,
                ],
            ],
        ]);

        if (count($existingMedia) > 0) {
            return $existingMedia[0]->ID;
        }

        return null;
    }

    /**
     * Remove outdated media if it exists
     *
     * @param string $url The original media URL
     * @return bool|int The attachment ID if removed, false otherwise
     */
    public function maybeRemoveOutdatedMedia(string $url): bool|int
    {
        $existingMedia = get_posts([
            'post_type' => 'attachment',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => 'original_url',
                    'value' => $url,
                ],
            ],
        ]);

        if (count($existingMedia) > 0) {
            $attachmentId = $existingMedia[0]->ID;
            if (wp_delete_attachment($attachmentId, true)) {
                return $attachmentId;
            } else {
                Logger::log(sprintf('Failed to delete outdated attachment ID %s', $attachmentId));
            }
        }

        return false;
    }

    /**
     * Download the file to a temporary location
     *
     * @return null|string|false|\WP_Error The path to the temporary file or WP_Error on failure
     */
    private function downloadTempFile(): null|string|false|\WP_Error
    {
        if (isset($this->filestream)) {
            if (is_callable($this->filestream)) {
                try {
                    $this->filestream = call_user_func($this->filestream);
                } catch (\Exception $e) {
                    Logger::log($e->getMessage() . ' ' . $this->url);

                    return null;
                }
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'wp-sync-posts');
            file_put_contents($tempFile, $this->filestream);
        } else {
            $tempFile = download_url($this->url, 30);
        }

        return $tempFile;
    }

    /**
     * Handle file upload in WordPress
     *
     * @param string $tempFile The path to the temporary file
     * @return array<string, mixed> The upload result from wp_handle_sideload
     */
    private function handleFileUploadInWordPress(string $tempFile): array
    {
        $fileType = $this->determineFileType($tempFile);

        $file = [
            'name' => $this->generateFileName($fileType['ext']),
            'type' => $fileType['type'],
            'tmp_name' => $tempFile,
            'error' => 0,
            'size' => filesize($tempFile),
        ];
        $overrides = [
            'test_form' => false,
            'test_size' => true,
        ];

        return wp_handle_sideload($file, $overrides);
    }

    /**
     * Create a WordPress attachment from the uploaded file
     *
     * @param array<string, string> $upload The upload array returned by wp_handle_sideload
     * @param int|null $postId The ID of the post to attach the media to
     * @return int The attachment ID
     */
    private function createAttachment(array $upload, ?int $postId): int
    {
        $filename = $upload['file'];
        $type = $upload['type'];

        $attachment = [
            'post_mime_type' => $type,
            'post_title' => $this->title,
            'post_content' => ' ',
            'post_status' => 'inherit',
        ];

        $attachmentId = wp_insert_attachment($attachment, $filename, $postId);

        $attach_data = wp_generate_attachment_metadata($attachmentId, $filename);
        wp_update_attachment_metadata($attachmentId, $attach_data);

        $this->meta
            ->merge([
                'original_url' => $this->url,
                'original_date_modified' => $this->dateModified,
            ])
            ->filter()
            ->each(function ($value, $key) use ($attachmentId) {
                add_post_meta($attachmentId, $key, $value);
            });

        return $attachmentId;
    }

    /**
     * Import and attach media
     *
     * @param int|null $postId The ID of the post to attach media to
     * @return int|null The attachment ID
     */
    public function importAndAttachToPost($postId = null): ?int
    {
        $this->requireWordPressIncludes();

        if (empty($this->url)) {
            Logger::log(sprintf('No attachment url given for post %s', $postId));
            return null;
        }

        if ($existingMediaId = $this->findExistingMedia($this->url, $this->dateModified)) {
            Logger::log(sprintf('Media already exists: #%s. Skipping', $existingMediaId));
            return $existingMediaId;
        }

        $this->removeOutdatedMediaIfExists();

        $tempFile = $this->downloadTempFile();
        if ($this->isDownloadError($tempFile)) {
            return null;
        }

        $uploadResult = $this->handleFileUploadInWordPress($tempFile);
        if ($this->hasUploadError($uploadResult)) {
            $this->cleanupTempFile($tempFile);
            return null;
        }

        $attachmentId = $this->createAttachment($uploadResult, $postId);

        Logger::log(sprintf('New attachment created with ID %s', $attachmentId));
        @unlink($tempFile);

        return $attachmentId;
    }

    /**
     * Load required WordPress files for media handling
     *
     * @return void
     */
    private function requireWordPressIncludes(): void
    {
        collect([
            ABSPATH . 'wp-admin/includes/file.php',
            ABSPATH . 'wp-admin/includes/image.php',
        ])
        ->filter(fn($file) => file_exists($file))
        ->each(fn($file) => require_once $file);
    }

    /**
     * Remove outdated media if it exists
     *
     * @return void
     */
    private function removeOutdatedMediaIfExists(): void
    {
        if ($removedId = $this->maybeRemoveOutdatedMedia($this->url)) {
            Logger::log(sprintf('Outdated attachment removed: #%s', $removedId));
        }
    }

    /**
     * Check if downloading the file resulted in an error
     *
     * @param null|string|false|\WP_Error $tempFile
     * @return bool True if there was an error, false otherwise
     */
    private function isDownloadError(null|string|false|\WP_Error $tempFile): bool
    {
        if (is_wp_error($tempFile)) {
            Logger::log(sprintf('Failed to download file from %s. %s', $this->url, $tempFile->get_error_message()));
            return true;
        }

        if ($tempFile === null || $tempFile === false) {
            Logger::log(sprintf('Failed to download file from %s. Download returned null/false', $this->url));
            return true;
        }

        return false;
    }

    /**
     * Check if the upload resulted in an error
     *
     * @param array<string, string> $uploadResult
     * @return bool True if there was an error, false otherwise
     */
    private function hasUploadError(array $uploadResult): bool
    {
        if (!empty($uploadResult['error'])) {
            Logger::log('File upload error: ' . print_r($uploadResult, true));
            return true;
        }
        return false;
    }

    /**
     * Clean up the temporary file
     *
     * @param string $tempFile
     * @return void
     */
    private function cleanupTempFile(string $tempFile): void
    {
        @unlink($tempFile);
    }
}
