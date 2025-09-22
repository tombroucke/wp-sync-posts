<?php

namespace Otomaties\WpSyncPosts;

class Syncer
{
    /**
     * The array of posts to sync
     */
    private array $posts = [];

    /**
     * Set post type
     */
    public function __construct(private string $postType = 'post')
    {
        //
    }

    private function add(string $className, array $args)
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
     */
    public function addPost(array ...$args): Post
    {
        return $this->add(Post::class, $args);
    }

    /**
     * Add post to posts to sync
     */
    public function addProduct(array ...$args): Product
    {
        return $this->add(Product::class, $args);
    }

    /**
     * Get posts to sync
     */
    public function posts(): array
    {
        return $this->posts;
    }

    /**
     * Execute sync & clean up afterwards
     */
    public function execute(bool $cleanUp = true, bool $forceDelete = true): array
    {
        if (! defined('WP_ADMIN')) {
            define('WP_ADMIN', true);
        }

        foreach ($this->posts as $post) {
            $post->save();
        }

        /* We need to execute setWpmlTranslation again after all posts are saved
        * because the original language might have been saved after the translation
        */
        foreach ($this->posts as $post) {
            $post->setWpmlTranslation();
        }

        if ($cleanUp) {
            $syncedPostIds = array_map(function ($post) {
                return $post->id();
            }, $this->posts);

            $args = [
                'post_type' => $this->postType,
                'posts_per_page' => -1,
                'post__not_in' => $syncedPostIds,
                'post_status' => get_post_stati(),
                'suppress_filters' => apply_filters('wp_sync_posts_suppress_filters', false),
                'fields' => 'ids',
            ];

            foreach (get_posts($args) as $abandonedPostId) {
                wp_delete_post($abandonedPostId, $forceDelete);
            }
        }

        return $this->posts();
    }
}
