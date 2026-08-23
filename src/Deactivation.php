<?php

declare(strict_types=1);

namespace OpenYacht;

final class Deactivation
{
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(SyncRunner::HOOK);
    }
}
