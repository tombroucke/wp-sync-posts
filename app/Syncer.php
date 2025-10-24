<?php

namespace Otomaties\WpSyncPosts;

use Illuminate\Support\Collection;

class Syncer
{
    /**
     * The collection of posts to sync
     *
     * @var Collection<int, Post|Product>
     */
    private Collection $posts;

    /**
     * Set post type
     */
    public function __construct(private string $postType = 'post')
    {
        $this->posts = collect();
    }

    /**
     * Create and add a new post instance to the sync collection
     *
     * @param  class-string  $className  The fully qualified class name to instantiate
     * @param  array<string, mixed>  $args  Arguments to pass to the class constructor (postType will be prepended)
     * @return object The created post instance
     *
     * @throws \ReflectionException If the class cannot be instantiated
     * @throws \InvalidArgumentException If the class doesn't exist
     */
    private function add(string $className, array $args): object
    {
        if (! class_exists($className)) {
            throw new \InvalidArgumentException("Class {$className} does not exist");
        }

        $class = new \ReflectionClass($className);
        array_unshift($args, $this->postType);
        $newPost = $class->newInstanceArgs($args);

        $this->posts->push($newPost);

        return $newPost;
    }

    /**
     * Add post to posts to sync
     *
     * @param  array<string, mixed>  ...$args
     * @return Post The created post instance
     */
    public function addPost(array ...$args): Post
    {
        return $this->add(Post::class, $args);
    }

    /**
     * Add post to posts to sync
     *
     * @param  array<string, mixed>  ...$args
     * @return Product The created product instance
     */
    public function addProduct(array ...$args): Product
    {
        return $this->add(Product::class, $args);
    }

    /**
     * Get posts to sync
     *
     * @return Collection<int, Post|Product> The collection of posts to sync
     */
    public function posts(): Collection
    {
        return $this->posts;
    }

    /**
     * Execute sync & clean up afterwards
     *
     * @param  bool  $cleanUp  Whether to clean up abandoned posts.
     * @param  bool  $forceDelete  Whether to force delete posts (bypass trash).
     */
    public function execute(bool $cleanUp = true, bool $forceDelete = true): self
    {
        if (! defined('WP_ADMIN')) {
            define('WP_ADMIN', true);
        }

        $this->posts
            ->each(fn ($post) => $post->save())
            // We need to execute setWpmlTranslation again after all posts are saved
            // because the original language might have been saved after the translation
            ->each(fn ($post) => $post->setWpmlTranslation());

        if ($cleanUp) {
            $this->cleanupAbandonedPosts($forceDelete);
        }

        return $this;
    }

    /**
     * Clean up abandoned posts that are no longer in the sync collection
     *
     * @param  bool  $forceDelete  Whether to force delete posts (bypass trash)
     */
    public function cleanupAbandonedPosts(bool $forceDelete = true): void
    {
        $syncedPostIds = $this->posts
            ->map(fn ($post) => $post->id())
            ->filter()
            ->toArray();

        $args = [
            'post_type' => $this->postType,
            'posts_per_page' => -1,
            'post__not_in' => $syncedPostIds,
            'post_status' => get_post_stati(),
            'suppress_filters' => apply_filters('wp_sync_posts_suppress_filters', false),
            'fields' => 'ids',
        ];

        $abandonedPostIds = get_posts($args);

        if (empty($abandonedPostIds)) {
            return;
        }

        foreach ($abandonedPostIds as $abandonedPostId) {
            if (! wp_delete_post($abandonedPostId, $forceDelete)) {
                Logger::log('Failed to delete post ID '.$abandonedPostId);
            }
        }
    }
}
