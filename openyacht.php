<?php

/**
 * Plugin Name: OpenYacht
 * Plugin URI: https://openyacht.org
 * Description: Turns this WordPress site into an OpenYacht node — federated yacht-listing sharing between brokerages.
 * Version: 0.4.4
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: OpenYacht
 * Author URI: https://openyacht.org
 * License: AGPL-3.0-only
 * License URI: https://www.gnu.org/licenses/agpl-3.0.html
 * Text Domain: openyacht
 * Update URI: https://github.com/OpenYacht/openyacht-wordpress
 */

declare(strict_types=1);

use OpenYacht\Activation;
use OpenYacht\Deactivation;
use OpenYacht\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

define('OPENYACHT_VERSION', '0.4.4');
define('OPENYACHT_FILE', __FILE__);
define('OPENYACHT_PATH', __DIR__);

$openyacht_autoload = __DIR__ . '/vendor/autoload.php';

if (! file_exists($openyacht_autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('OpenYacht is missing its vendor directory. Run "composer install" in the plugin directory.', 'openyacht');
        echo '</p></div>';
    });

    return;
}

require $openyacht_autoload;
require __DIR__ . '/src/functions.php';

register_activation_hook(__FILE__, [Activation::class, 'activate']);
register_deactivation_hook(__FILE__, [Deactivation::class, 'deactivate']);

add_action('plugins_loaded', [Plugin::class, 'boot']);
