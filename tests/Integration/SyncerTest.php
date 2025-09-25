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

test('posts can be synced', function () {
    // insert post
    $originalPostId = wp_insert_post([
        'post_title'   => 'Existing Post',
        'post_content' => 'This is an existing post content.',
        'post_status'  => 'publish',
        'meta_input'   => [
            'external_id' => 'test-post-69',
        ],
    ]);


    $testPostAmount = 9;

    $syncer = new Syncer('post');
    for ($i = 1; $i <= $testPostAmount; $i++) {
        $post = [
            'post_title'   => "Test Post {$i}",
            'post_content' => "This is the content of test post {$i}.",
            'meta_input'   => [
                'external_id' => "test-post-{$i}",
            ],
        ];

        $existingPostQuery = [
            'by'    => 'meta_value',
            'key'   => 'external_id',
            'value' => "test-post-{$i}",
        ];

        $syncer->addPost(
            $post,
            $existingPostQuery,
        );
    }

    $args = [
        'post_type'      => 'post',
        'posts_per_page' => -1,
        'post_status'    => get_post_stati(),
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];

    // Test if the correct amount of posts exist before sync
    $postsIdsBeforeFirstSync = get_posts($args);
    $this->assertCount(1, $postsIdsBeforeFirstSync);
    $this->assertContains($originalPostId, $postsIdsBeforeFirstSync);

    $results = $syncer->execute();

    // Test if the correct amount of posts exist after first sync
    $postsIdsAfterFirstSync = get_posts($args);
    $this->assertCount($testPostAmount, $postsIdsAfterFirstSync);

    // assert original post not in array
    $this->assertNotContains($originalPostId, $postsIdsAfterFirstSync);

    $syncer = new Syncer('post');
    for ($i = 1; $i <= $testPostAmount; $i++) {
        $post = [
            'post_title'   => "Test Post {$i}",
            'post_content' => "This is the updated content of test post {$i}.",
            'meta_input'   => [
                'external_id' => "test-post-{$i}",
            ],
        ];

        $existingPostQuery = [
            'by'    => 'meta_value',
            'key'   => 'external_id',
            'value' => "test-post-{$i}",
        ];

        $syncer->addPost(
            $post,
            $existingPostQuery,
        );
    }

    $args = [
        'post_type'      => 'post',
        'posts_per_page' => -1,
        'post_status'    => get_post_stati(),
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];

    $results = $syncer->execute();

    $postsIdsAfterSecondSync = get_posts($args);
    $this->assertCount($testPostAmount, $postsIdsAfterSecondSync);

    $this->assertEquals($postsIdsAfterFirstSync, $postsIdsAfterSecondSync);
});

test('custom post type can be synced', function () {
    wp_insert_post([
        'post_title'   => 'Existing Book',
        'post_content' => 'This is an existing book content.',
        'post_status'  => 'publish',
        'post_type'    => 'book',
        'meta_input'   => [
            'external_id' => 'test-book-69',
        ],
    ]);

    $syncer = new Syncer('book');

    $testPostAmount = 2;

    for ($i = 1; $i <= $testPostAmount; $i++) {
        $post = [
            'post_title'   => fn() => "Test Book {$i}",
            'post_content' => "This is the content of test book {$i}.",
            'post_status'  => $i === 1 ? 'publish' : 'draft',
            'meta_input' => [
                'external_id' => "test-book-{$i}",
            ],
        ];

        $existingPostQuery = [
            'by'    => 'meta_value',
            'key'   => 'external_id',
            'value' => "test-book-{$i}",
        ];

        $syncer->addPost(
            $post,
            $existingPostQuery,
        );
    }

    $args = [
        'post_type'      => 'book',
        'posts_per_page' => -1,
        'post_status'    => get_post_stati(),
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];

    $postsIdsBeforeFirstSync = get_posts($args);
    $this->assertCount(1, $postsIdsBeforeFirstSync);

    $results = $syncer->execute();

    $postsIdsAfterFirstSync = get_posts($args);
    $this->assertCount($testPostAmount, $postsIdsAfterFirstSync);
});

test('Closures can be passed', function () {
    $postArgs = [
        'post_type'      => 'post',
        'posts_per_page' => -1,
        'post_status'    => get_post_stati(),
    ];
    $posts = get_posts($postArgs);
    $syncer = new Syncer('post');

    $testPostAmount = 9;

    for ($i = 1; $i <= $testPostAmount; $i++) {
        $post = [
            'post_title'   => fn() => "Test Post {$i}",
            'post_content' => fn() => "This is the content of test post {$i}.",
            'post_status' => fn($post) => $post->id() ? 'publish' : 'draft',
            'meta_input' => [
                'external_id' => "test-post-{$i}-closures",
            ],
        ];

        $existingPostQuery = [
            'by'    => 'meta_value',
            'key'   => 'external_id',
            'value' => "test-post-{$i}-closures",
        ];

        $syncer->addPost(
            $post,
            $existingPostQuery,
        );
    }

    $results = $syncer->execute();

    $posts = get_posts($postArgs);
    $this->assertCount($testPostAmount, $posts);

    $postsStati = collect(get_posts($postArgs))->pluck('post_status')
        ->unique()
        ->values()
        ->toArray();
    $this->assertEquals(['draft'], $postsStati);

    $syncer = new Syncer('post');
    for ($i = 1; $i <= $testPostAmount; $i++) {
        $post = [
            'post_title'   => "Test Post {$i}",
            'post_content' => "This is the content of test post {$i}.",
            'post_status' => fn($post) => $post->id() ? 'publish' : 'draft',
            'meta_input' => [
                'external_id' => "test-post-{$i}-closures",
            ],
        ];

        $existingPostQuery = [
            'by'    => 'meta_value',
            'key'   => 'external_id',
            'value' => "test-post-{$i}-closures",
        ];

        $syncer->addPost(
            $post,
            $existingPostQuery,
        );
    }

    $results = $syncer->execute();

    $posts = get_posts($postArgs);
    $this->assertCount($testPostAmount, $posts);

    $postsStati = collect(get_posts($postArgs))->pluck('post_status')
        ->unique()
        ->values()
        ->toArray();
    $this->assertEquals(['publish'], $postsStati);
});
