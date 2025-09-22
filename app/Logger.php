<?php

namespace Otomaties\WpSyncPosts;

class Logger
{
    public static function log(string $message): void
    {
        if (class_exists('WP_CLI')) {
            \WP_CLI::log($message);
        }
    }
}
