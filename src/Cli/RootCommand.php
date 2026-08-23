<?php

declare(strict_types=1);

namespace OpenYacht\Cli;

use OpenYacht\Federation\NodeConfig;
use OpenYacht\Federation\Partner;
use OpenYacht\Federation\SyncResult;
use OpenYacht\NodeIdentity;
use OpenYacht\Services;
use OpenYacht\SyncRunner;
use Throwable;
use WP_CLI;

/**
 * Node-level commands: `wp openyacht status`, `wp openyacht sync`.
 */
final class RootCommand
{
    /**
     * Show this node's federation identity and state.
     *
     * ## EXAMPLES
     *
     *     wp openyacht status
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     */
    public function status(array $args, array $assocArgs): void
    {
        $active = Services::keyManager()->activeKey();
        $partners = Services::partners()->all();

        WP_CLI::line('Identity domain: ' . NodeConfig::identityDomain());
        WP_CLI::line('Node UUID:       ' . ((string) NodeIdentity::uuid() ?: '(not set)'));
        WP_CLI::line('Active key:      ' . ($active->keyId ?? '(none)'));
        WP_CLI::line('Partners:        ' . count($partners));

        foreach ($partners as $partner) {
            $copies = Services::copies()->countForPartner($partner->id);
            $stale = $partner->isStale() ? ' STALE' : '';
            WP_CLI::line(sprintf(
                '  %-40s %-12s copies:%-5d last_ok:%s%s',
                $partner->domain,
                $partner->trustLevel->value,
                $copies,
                $partner->lastOkAt ?? 'never',
                $stale,
            ));
        }
    }

    /**
     * Sync listings from partners (cold full sync first, then incremental).
     *
     * ## OPTIONS
     *
     * [--partner=<domain>]
     * : Sync only this partner.
     *
     * [--force]
     * : Ignore the failure backoff and sync anyway.
     *
     * ## EXAMPLES
     *
     *     wp openyacht sync
     *     wp openyacht sync --partner=broker.example --force
     *
     * @param array<int, string> $args
     * @param array<string, string|bool> $assocArgs
     */
    public function sync(array $args, array $assocArgs): void
    {
        $only = isset($assocArgs['partner']) ? strtolower(trim((string) $assocArgs['partner'])) : null;
        $force = (bool) ($assocArgs['force'] ?? false);

        $ok = SyncRunner::run(
            static function (string $outcome, ?Partner $partner, ?SyncResult $result, ?Throwable $error): void {
                $domain = $partner->domain ?? '?';

                match ($outcome) {
                    'synced' => WP_CLI::success(sprintf(
                        '%s: %d created, %d updated, %d tombstoned',
                        $domain,
                        $result->created ?? 0,
                        $result->updated ?? 0,
                        $result->tombstoned ?? 0,
                    )),
                    'skipped (backoff)' => WP_CLI::line("{$domain}: skipped (failure backoff; use --force to override)"),
                    default => WP_CLI::warning("{$domain}: " . ($error?->getMessage() ?? 'failed')),
                };
            },
            $only,
            $force,
        );

        if (! $ok) {
            WP_CLI::error('One or more partners failed to sync.');
        }
    }
}
