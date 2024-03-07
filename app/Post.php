<?php //phpcs:ignore
namespace Otomaties\WpSyncPosts;

class Post
{
    /**
     * Arguments for wp_insert_post
     *
     * @var array
     */
    private $args = [];

    /**
     * List of valid arguments
     *
     * @var array
     */
    protected $availableArgs = [
        'ID',
        'post_author',
        'post_date',
        'post_date_gmt',
        'post_content',
        'post_content_filtered',
        'post_title',
        'post_excerpt',
        'post_status',
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
    ];

    /**
     * Default value for arguments
     *
     * @var array
     */
    private $defaultPostArgs = [
        'post_type'    => 'post',
        'post_title'   => '',
        'post_content' => '',
        'post_status'  => 'publish',
    ];

    /**
     * Array of media to import
     *
     * @var array
     */
    private $media = [];

    /**
     * ID of this post
     *
     * @var integer
     */
    private $id = 0;

    /**
     * Post type
     *
     * @var string
     */
    protected $postType = '';
    
    /**
     * Default query
     *
     * @var array
     */
    private $defaultQuery = [
        'by' => 'id',
        'value' => 0
    ];

    /**
     * Save post type, args & find match in database
     *
     * @param string $postType
     * @param array $args
     * @param array $existingPostQuery
     */
    public function __construct(string $postType, array $args, array $existingPostQuery)
    {
        $this->postType = $postType;

        $args = wp_parse_args($args, $this->defaultPostArgs);
        $this->media = $this->extractMedia($args);
        $this->args = $this->removeUnsupportedArgs($args, $this->availableArgs);
        
        $existingPostQuery = wp_parse_args($existingPostQuery, $this->defaultQuery);
        $this->id = $this->find($existingPostQuery);
    }

    /**
     * Get post ID. Returns 0 if post doesn't exist
     *
     * @return integer
     */
    public function id() : int
    {
        return $this->id;
    }

    /**
     * Set post ID
     *
     * @param integer $id
     * @return integer
     */
    private function setId(int $id) : int
    {
        return $this->id = $id;
    }

    /**
     * Extra media key from an array
     *
     * @param array $args
     * @return array
     */
    private function extractMedia(array $args) : array
    {
        return isset($args['media']) ? $args['media'] : [];
    }

    /**
     * Compare two arrays, remove keys from first array which aren't present in second array
     *
     * @param array $args The array with actual data
     * @param array $availableArgs The array to compare to
     * @return array The filtered array with actual data
     */
    protected function removeUnsupportedArgs(array $args, array $availableArgs) : array
    {
        return array_intersect_key($args, array_flip($availableArgs));
    }

    /**
     * Define post ID for existing post, remove ID for new posts
     * Define post_Type
     *
     * @param array $args
     * @return array
     */
    private function prepareArgs(array $args) : array
    {
        if ($this->id() > 0) {
            $args['ID'] = $this->id();
        } elseif (isset($args['ID'])) {
            unset($args['ID']);
        }
        $args['post_type'] = $this->postType;
        return $args;
    }

    /**
     * Find a post in the database
     *
     * @param array $existingPostQuery
     * @return integer
     */
    protected function find(array $query, string $postType = null) : int
    {
        if (!isset($query['by'])) {
            throw new \Exception('Query by is not set', 1);
        }
        if (!isset($query['value'])) {
            throw new \Exception('Query value is not set', 1);
        }

        if ($query['by'] == 'meta_value' & !isset($query['key'])) {
            throw new \Exception('Query key is not set', 1);
        }

        $findBy = $query['by'];
        $compare = isset($query['compare']) ? $query['compare'] : '=';
        $value = $query['value'];
        $postType = $postType ?: $this->postType;

        switch ($findBy) {
            case 'id':
                Logger::log(sprintf('Searching for post with ID #%s', $value));
                $postId = get_post_status($value) ? $value : 0;
                if ($postId > 0) {
                    Logger::log(sprintf('Found post: #%s', $postId));
                } else {
                    Logger::log(sprintf('No post found'));
                }
                return $postId;
                break;
            case 'meta_value':
                $postId = 0;
                $key = $query['key'];
                Logger::log(sprintf('Searching for post with meta key %s %s %s', $key, $compare, $value));
                $args = array(
                    'post_type'         => $postType,
                    'post_status'       => get_post_stati(),
                    'posts_per_page'    => 1,
                    'fields'            => 'ids',
                    'meta_query'        => array(
                        array(
                            'key'       => $key,
                            'value'     => $value,
                            'compare'   => $compare,
                        ),
                    ),
                );

                $postIds = get_posts($args);
                if (isset($postIds[0])) {
                    $postId = $postIds[0];
                    Logger::log(sprintf('Found post: #%s', $postId));
                } else {
                    Logger::log(sprintf('Post not found'));
                }
                return $postId;
                break;

            default:
                throw new \Exception(sprintf('%s is not a supported value for \'by\'', $query['by']), 1);
            break;
        }
    }

    /**
     * Insert/update post
     *
     * @return boolean Whether the post has been saved
     */
    public function save() : int
    {
        $args = $this->prepareArgs($this->args);
        $insert = !isset($args['ID']);

        $id = $insert ? wp_insert_post($args, true) : wp_update_post($args, true);

        if (!is_numeric($id)) {
            return false;
        }

        $this->setId($id);

        $mediaGroups = ['synced_images'];
        foreach ($this->media as $item) {
            if (isset($item['group']) && !in_array($item['group'], $mediaGroups)) {
                $mediaGroups[] = $item['group'];
            }
        }
        $existingMedia = [];
        foreach ($mediaGroups as $groupName) {
            $existingMedia[$groupName] = get_post_meta($this->id(), $groupName, true);
        }

        // Import media.
        if ($this->media) {
            $attachmentIds = [
                'synced_images' => [],
            ];
            foreach ($this->media as $key => $item) {
                $media = new Media($item);
                $attachmentId = $media->importAndAttachToPost($this->id());

                if (isset($item['key'])) {
                    update_post_meta($this->id(), $item['key'], $attachmentId);
                }

                if (isset($item['group'])) {
                    $attachmentIds[$item['group']][] = $attachmentId;
                } else {
                    $attachmentIds['synced_images'][] = $attachmentId;
                }

                if (isset($item['featured']) && $item['featured']) {
                    set_post_thumbnail($this->id(), $attachmentId);
                }
            }

            foreach ($attachmentIds as $key => $ids) {
                update_post_meta($this->id(), $key, $ids);
            }

            // TODO: Remove old media. doesnt work with wpml
            // foreach ($existingMedia as $groupName => $ids) {
            //     foreach ($ids as $id) {
            //         if (!in_array($id, $attachmentIds[$groupName])) {
            //             wp_delete_attachment($id, true);
            //         }
            //     }
            // }
        }

        /**
         * tax_input relies on the user having permissions to a taxonomy, so we need to manually assign the terms
         * @see https://wordpress.stackexchange.com/questions/210229/tax-input-not-working-wp-insert-post
         */
        if (isset($this->args['tax_input']) && !empty($this->args['tax_input'])) {
            foreach ($this->args['tax_input'] as $taxonomy => $terms) {
                wp_set_object_terms($this->id(), $terms, $taxonomy);
            }
        }

        return $id;
    }
}
