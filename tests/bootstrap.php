<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// The unit lane runs without WordPress; provide the bare wpdb shape that
// typed constructors need. Integration tests (Playground lane) use the real one.
if (! class_exists('wpdb')) {
    require_once __DIR__ . '/Support/wpdb-stub.php';
}

// Points Schema::install()'s upgrade.php require at the recording dbDelta stub.
if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/Support/');
}

if (! defined('OPENYACHT_VERSION')) {
    define('OPENYACHT_VERSION', '0.0.0-test');
}
