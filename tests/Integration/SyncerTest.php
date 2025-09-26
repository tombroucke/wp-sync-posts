<?php

namespace Tests\Integration;

use Otomaties\WpSyncPosts\Syncer;
use Yoast\WPTestUtils\WPIntegration\TestCase;

if (isUnitTest()) {
    return;
}

/*
 * We need to provide the base test class to every integration test.
 * This will enable us to use all the WordPress test goodies, such as
 * factories and proper test cleanup.
 */
uses(TestCase::class);

beforeEach(function () {
    parent::setUp();

    $this->postArgs = [
        'post_type' => 'post',
        'posts_per_page' => -1,
        'post_status' => 'any',
        'orderby' => 'ID',
        'order' => 'ASC',
    ];

    // Cleanup all posts
    collect(get_posts($this->postArgs))
        ->each(fn ($post) => wp_delete_post($post->ID, true));

    // Insert demo post
    $this->originalPostId = wp_insert_post([
        'post_title' => 'Existing Post',
        'post_content' => 'This is an existing post content.',
        'post_status' => 'publish',
        'meta_input' => [
            'external_id' => 'test-post-69',
        ],
    ]);

    // // Set up a REST server instance.
    // global $wp_rest_server;

    // $this->server = $wp_rest_server = new \WP_REST_Server();
    // do_action('rest_api_init', $this->server);
});

afterEach(function () {
    // global $wp_rest_server;
    // $wp_rest_server = null;

    parent::tearDown();
});

test('Readme demo works', function () {
    $syncer = new \Otomaties\WpSyncPosts\Syncer('post');

    $externalPosts = [
        [
            'title' => 'API post 1 title',
            'id' => 'api-post-1',
        ],
        [
            'title' => 'API post 2 title',
            'id' => 'api-post-2',
        ],
    ];

    foreach ($externalPosts as $externalPost) {
        $args = [
            'post_title' => $externalPost['title'],
            'meta_input' => [
                'external_id' => $externalPost['id'],
            ],
        ];

        $existingPostQuery = [
            'by' => 'meta_value',
            'key' => 'external_id',
            'value' => $externalPost['id'],
        ];

        $syncer->addPost($args, $existingPostQuery);
    }

    $postsBeforeFirstSync = get_posts($this->postArgs);
    $this->assertCount(1, $postsBeforeFirstSync);
    $syncer->execute();
    $postsAfterFirstSync = get_posts($this->postArgs);
    $this->assertCount(2, $postsAfterFirstSync);

    $syncer = new \Otomaties\WpSyncPosts\Syncer('post');

    $externalPosts = [
        [
            'title' => 'API post 1 updated title',
            'id' => 'api-post-1',
        ],
        [
            'title' => 'API post 2 updated title',
            'id' => 'api-post-2',
        ],
    ];

    foreach ($externalPosts as $externalPost) {
        $args = [
            'post_title' => $externalPost['title'],
            'meta_input' => [
                'external_id' => $externalPost['id'],
            ],
        ];

        $existingPostQuery = [
            'by' => 'meta_value',
            'key' => 'external_id',
            'value' => $externalPost['id'],
        ];

        $syncer->addPost($args, $existingPostQuery);
    }

    $syncer->execute();
    $postsAfterSecondSync = get_posts($this->postArgs);
    $this->assertCount(2, $postsAfterSecondSync);
    $this->assertEquals('API post 1 updated title', get_post($postsAfterSecondSync[0]->ID)->post_title);
    $this->assertEquals(collect($postsAfterFirstSync)->pluck('ID'), collect($postsAfterSecondSync)->pluck('ID'));
});

test('posts can be synced', function () {
    $testPostAmount = 9;

    $syncer = new Syncer('post');
    for ($i = 1; $i <= $testPostAmount; $i++) {
        $post = [
            'post_title' => "Test Post {$i}",
            'post_content' => "This is the content of test post {$i}.",
            'meta_input' => [
                'external_id' => "test-post-{$i}",
            ],
        ];

        $existingPostQuery = [
            'by' => 'meta_value',
            'key' => 'external_id',
            'value' => "test-post-{$i}",
        ];

        $syncer->addPost(
            $post,
            $existingPostQuery,
        );
    }

    // Test if the correct amount of posts exist before sync
    $postsIdsBeforeFirstSync = collect(get_posts($this->postArgs))->pluck('ID')->toArray();
    $this->assertCount(1, $postsIdsBeforeFirstSync);
    $this->assertContains($this->originalPostId, $postsIdsBeforeFirstSync);

    $results = $syncer->execute();

    // Test if the correct amount of posts exist after first sync
    $postsIdsAfterFirstSync = collect(get_posts($this->postArgs))->pluck('ID')->toArray();
    $this->assertCount($testPostAmount, $postsIdsAfterFirstSync);

    // assert original post not in array
    $this->assertNotContains($this->originalPostId, $postsIdsAfterFirstSync);

    $syncer = new Syncer('post');
    for ($i = 1; $i <= $testPostAmount; $i++) {
        $post = [
            'post_title' => "Test Post {$i}",
            'post_content' => "This is the updated content of test post {$i}.",
            'meta_input' => [
                'external_id' => "test-post-{$i}",
            ],
        ];

        $existingPostQuery = [
            'by' => 'meta_value',
            'key' => 'external_id',
            'value' => "test-post-{$i}",
        ];

        $syncer->addPost(
            $post,
            $existingPostQuery,
        );
    }

    $results = $syncer->execute();

    $postsIdsAfterSecondSync = collect(get_posts($this->postArgs))->pluck('ID')->toArray();
    $this->assertCount($testPostAmount, $postsIdsAfterSecondSync);

    $this->assertEquals($postsIdsAfterFirstSync, $postsIdsAfterSecondSync);
});

test('custom post type can be synced', function () {
    wp_insert_post([
        'post_title' => 'Existing Book',
        'post_content' => 'This is an existing book content.',
        'post_status' => 'publish',
        'post_type' => 'book',
        'meta_input' => [
            'external_id' => 'test-book-69',
        ],
    ]);

    $syncer = new Syncer('book');

    $testPostAmount = 2;

    for ($i = 1; $i <= $testPostAmount; $i++) {
        $post = [
            'post_title' => fn () => "Test Book {$i}",
            'post_content' => "This is the content of test book {$i}.",
            'post_status' => $i === 1 ? 'publish' : 'draft',
            'meta_input' => [
                'external_id' => "test-book-{$i}",
            ],
        ];

        $existingPostQuery = [
            'by' => 'meta_value',
            'key' => 'external_id',
            'value' => "test-book-{$i}",
        ];

        $syncer->addPost(
            $post,
            $existingPostQuery,
        );
    }

    $args = array_merge($this->postArgs, [
        'post_type' => 'book',
    ]);

    $postsBeforeFirstSync = get_posts($args);
    $this->assertCount(1, $postsBeforeFirstSync);

    $results = $syncer->execute();

    $postsAfterFirstSync = get_posts($args);
    $this->assertCount($testPostAmount, $postsAfterFirstSync);
});

test('Closures can be passed', function () {
    $syncer = new Syncer('post');

    $testPostAmount = 9;

    for ($i = 1; $i <= $testPostAmount; $i++) {
        $post = [
            'post_title' => fn () => "Test Post {$i}",
            'post_content' => fn () => "This is the content of test post {$i}.",
            'post_status' => fn (\Otomaties\WpSyncPosts\Post $post) => $post->id() ? 'publish' : 'draft',
            'meta_input' => [
                'external_id' => "test-post-{$i}-closures",
            ],
        ];

        $existingPostQuery = [
            'by' => 'meta_value',
            'key' => 'external_id',
            'value' => "test-post-{$i}-closures",
        ];

        $syncer->addPost(
            $post,
            $existingPostQuery,
        );
    }

    $results = $syncer->execute();

    $posts = get_posts($this->postArgs);
    $this->assertCount($testPostAmount, $posts);

    $postsStati = collect(get_posts($this->postArgs))->pluck('post_status')
        ->unique()
        ->values()
        ->toArray();
    $this->assertEquals(['draft'], $postsStati);

    $syncer = new Syncer('post');
    for ($i = 1; $i <= $testPostAmount; $i++) {
        $post = [
            'post_title' => "Test Post {$i}",
            'post_content' => "This is the content of test post {$i}.",
            'post_status' => fn ($post) => $post->id() ? 'publish' : 'draft',
            'meta_input' => [
                'external_id' => "test-post-{$i}-closures",
            ],
        ];

        $existingPostQuery = [
            'by' => 'meta_value',
            'key' => 'external_id',
            'value' => "test-post-{$i}-closures",
        ];

        $syncer->addPost(
            $post,
            $existingPostQuery,
        );
    }

    $results = $syncer->execute();

    $posts = get_posts($this->postArgs);
    $this->assertCount($testPostAmount, $posts);

    $postsStati = collect(get_posts($this->postArgs))->pluck('post_status')
        ->unique()
        ->values()
        ->toArray();
    $this->assertEquals(['publish'], $postsStati);
});
