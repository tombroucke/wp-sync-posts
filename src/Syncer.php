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

    /**
     * Add post to posts to sync
     *
     * @param array $args
     * @param array $existingPostQuery
     * @return void
     */
    public function addPost(...$args)
    {

        $className = '\\Otomaties\\WpSyncPosts\\' . $this->postTypeToClassName($this->postType);
        if (!class_exists($className)) {
            $className = '\\Otomaties\\WpSyncPosts\\Post';
        }
        $class = new \ReflectionClass($className);
        $objArgs = $args;
        array_unshift($objArgs, $this->postType);
        $newPost = $class->newInstanceArgs($objArgs);
        $this->posts[] = $newPost;
        return $newPost;
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
            );
            $delete_posts = get_posts($args);
            foreach ($delete_posts as $delete_post) {
                wp_delete_post($delete_post->ID, $forceDelete);
            }
        }
    }

    private function postTypeToClassName(string $postType)
    {
        $postTypeUnderscores = str_replace('-', '_', $postType);
        return str_replace('_', '', ucwords($postTypeUnderscores, '_'));
    }
}
