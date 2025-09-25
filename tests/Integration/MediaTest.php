<?php

namespace Tests\Integration;

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

test('media can be imported', function () {
    $url = 'https://fastly.picsum.photos/id/1/100/100.jpg?hmac=ZFE9J9JWYx84uJzvjw4GTuagMzN4FAmaKE4XeJDMZTY';
    $modified = gmdate('Y-m-d H:i:s', time());
    $title = 'Example Image';
    $alt = 'An example image alt text';

    $media = new \Otomaties\WpSyncPosts\Media([
        'url' => $url,
        'title' => $title,
        'filename' => 'notebook.png',
        'date_modified' => $modified,
        'meta' => [
            'alt' => $alt,
        ],
    ]);

    $id = $media->importAndAttachToPost(1);

    $this->assertIsInt($id);
    $this->assertGreaterThan(0, $id);
    $this->assertEquals($title, get_the_title($id));
    $this->assertEquals($alt, get_post_meta($id, 'alt', true));
    $this->assertEquals($url, get_post_meta($id, 'original_url', true));
    $this->assertEquals($modified, get_post_meta($id, 'original_date_modified', true));

    $originalImage = file_get_contents($url);
    $importedImage = file_get_contents(get_attached_file($id));
    $this->assertEquals($originalImage, $importedImage);
});

test('duplicate media is not imported again', function () {
    $url = 'https://fastly.picsum.photos/id/1/100/100.jpg?hmac=ZFE9J9JWYx84uJzvjw4GTuagMzN4FAmaKE4XeJDMZTY';
    $modified = gmdate('Y-m-d H:i:s', time() - 3600);

    $media = new \Otomaties\WpSyncPosts\Media([
        'url' => $url,
        'date_modified' => $modified,
    ]);

    $id1 = $media->importAndAttachToPost(1);
    $id2 = $media->importAndAttachToPost(1);

    $this->assertEquals($id1, $id2);
});

test('media is re-imported when modified date changes', function () {
    $url = 'https://fastly.picsum.photos/id/1/100/100.jpg?hmac=ZFE9J9JWYx84uJzvjw4GTuagMzN4FAmaKE4XeJDMZTY';
    $modified1 = gmdate('Y-m-d H:i:s', time() - 3600);
    $modified2 = gmdate('Y-m-d H:i:s', time());

    $media1 = new \Otomaties\WpSyncPosts\Media([
        'url' => $url,
        'date_modified' => $modified1,
    ]);

    $media2 = new \Otomaties\WpSyncPosts\Media([
        'url' => $url,
        'date_modified' => $modified2,
    ]);

    $id1 = $media1->importAndAttachToPost(1);
    $id2 = $media2->importAndAttachToPost(1);

    $this->assertNotEquals($id1, $id2);
    $this->assertNull(get_post($id1));
    $this->assertNotNull(get_post($id2));
});
