<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Schema;

final class WpdbLogger implements Logger
{
    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    public function log(
        string $channel,
        string $message,
        ?string $outcome = null,
        ?int $partnerId = null,
        ?string $endpoint = null,
        array $context = [],
    ): void {
        $this->wpdb->insert((new Schema($this->wpdb))->tableName('log'), [
            'channel' => $channel,
            'partner_id' => $partnerId,
            'endpoint' => $endpoint,
            'outcome' => $outcome,
            'message' => $message,
            'context' => $context === [] ? null : wp_json_encode($context),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
