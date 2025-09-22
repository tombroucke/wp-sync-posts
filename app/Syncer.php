<?php

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
     * @param  array  $args
     */
    public function addPost(...$args)
    {
        $className = '\\Otomaties\\WpSyncPosts\\Post';

        return $this->add($className, $args);
    }

    /**
     * Add post to posts to sync
     *
     * @param  array  $args
     */
    public function addProduct(...$args)
    {
        $className = '\\Otomaties\\WpSyncPosts\\Product';

        return $this->add($className, $args);
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
            $postIds = array_map(function ($post) {
                return $post->id();
            }, $this->posts);
            $args = [
                'post_type' => $this->postType,
                'posts_per_page' => -1,
                'post__not_in' => $postIds,
                'post_status' => get_post_stati(),
                'suppress_filters' => apply_filters('wp_sync_posts_suppress_filters', false),
            ];
            $delete_posts = get_posts($args);
            foreach ($delete_posts as $delete_post) {
                wp_delete_post($delete_post->ID, $forceDelete);
            }
        }

        return $this->posts();
    }
}
