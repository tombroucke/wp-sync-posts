<?php //phpcs:ignore
namespace Otomaties\WpSyncPosts;

class Syncer
{
    /**
     * The post type to sync
     *
     * @var string
     */
    private $postType = 'post';

    /**
     * The array of posts to sync
     *
     * @var array
     */
    private $posts = [];

    /**
     * Set post type
     *
     * @param string $postType
     */
    public function __construct(string $postType = 'post')
    {
        $this->postType = $postType;
    }

    public function add($className, $args)
    {
        $class = new \ReflectionClass($className);
        $objArgs = $args;
        array_unshift($objArgs, $this->postType);
        $newPost = $class->newInstanceArgs($objArgs);
        $this->posts[] = $newPost;
        return $newPost;
    }

    /**
     * Add post to posts to sync
     *
     * @param array $args
     * @param array $existingPostQuery
     * @return void
     */
    public function addPost(...$args)
    {
        $className = '\\Otomaties\\WpSyncPosts\\Post';
        return $this->add($className, $args);
    }

    /**
     * Add post to posts to sync
     *
     * @param array $args
     * @param array $existingPostQuery
     * @return void
     */
    public function addProduct(...$args)
    {
        $className = '\\Otomaties\\WpSyncPosts\\Product';
        return $this->add($className, $args);
    }

    /**
     * Execute sync & clean up afterwards
     *
     * @param boolean $cleanUp
     * @param boolean $forceDelete
     * @return void
     */
    public function execute(bool $cleanUp = true, bool $forceDelete = true) : void
    {
        foreach ($this->posts as $post) {
            $post->save();
        }

        if ($cleanUp) {
            $postIds = array_map(function ($post) {
                return $post->id();
            }, $this->posts);
            $args = array(
                'post_type'         => $this->postType,
                'posts_per_page'    => -1,
                'post__not_in'      => $postIds,
                'post_status'       => get_post_stati(),
                'suppress_filters'  => apply_filters('wp_sync_posts_suppress_filters', false),
            );
            $delete_posts = get_posts($args);
            foreach ($delete_posts as $delete_post) {
                wp_delete_post($delete_post->ID, $forceDelete);
            }
        }
    }
}
