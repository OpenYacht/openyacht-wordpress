<?php

declare(strict_types=1);

namespace OpenYacht\Cli;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Federation\IntroductionOutcome;
use OpenYacht\Federation\Partner;
use OpenYacht\Federation\PartnerIntroduction;
use OpenYacht\Federation\TrustLevel;
use OpenYacht\Services;
use Throwable;
use WP_CLI;

/**
 * Partner lifecycle: `wp openyacht partner <add|list|approve|block|remove|refresh-keys|introduce>`.
 */
final class PartnerCommand
{
    /**
     * Register a partner node by domain (TOFU: fetches its well-known
     * document over verified TLS; the partner starts provisional), then
     * introduce this node to it with a signed partnership request.
     *
     * ## OPTIONS
     *
     * <domain>
     * : The partner's identity domain.
     *
     * [--public-key=<base64>]
     * : Out-of-band registration: store this pinned Ed25519 public key
     * instead of fetching the well-known document (for nodes this site
     * cannot resolve, e.g. .test domains).
     *
     * [--node-uuid=<uuid>]
     * : With --public-key: the partner's node UUID, if known.
     *
     * [--message=<text>]
     * : Sent with the partnership request to the partner's administrators.
     * Defaults to a one-line introduction naming this node.
     *
     * [--contact-email=<email>]
     * : How their administrator can reply. Defaults to the site admin email.
     *
     * ## EXAMPLES
     *
     *     wp openyacht partner add broker.example --message="We list in Palm Beach and would like to share."
     *     wp openyacht partner add ref-app.test --public-key="QKcw...Ac="
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     */
    public function add(array $args, array $assocArgs): void
    {
        try {
            $partner = isset($assocArgs['public-key'])
                ? Services::partnerService()->addWithKey(
                    $args[0],
                    (string) $assocArgs['public-key'],
                    isset($assocArgs['node-uuid']) ? (string) $assocArgs['node-uuid'] : null,
                )
                : Services::partnerService()->add($args[0]);
        } catch (Throwable $exception) {
            WP_CLI::error($exception->getMessage());

            return;
        }

        WP_CLI::success("Partner {$partner->domain} added as {$partner->trustLevel->value}. Approve with: wp openyacht partner approve {$partner->domain}");

        // The row is saved whatever comes back: a failed introduction is a
        // warning about the other side, not a failed add.
        $this->reportIntroduction($partner, Services::partnerService()->introduce(
            $partner,
            isset($assocArgs['message']) ? (string) $assocArgs['message'] : null,
            isset($assocArgs['contact-email']) ? (string) $assocArgs['contact-email'] : null,
        ));
    }

    /**
     * Send (or resend) the signed partnership request that introduces this
     * node to a partner — federation-protocol.md §Partner Lifecycle step 1.
     * Their node stores this site as a provisional partner and notifies
     * its administrators, message and contact included.
     *
     * ## OPTIONS
     *
     * <domain>
     * : The partner's domain.
     *
     * [--message=<text>]
     * : Sent to the partner's administrators. Defaults to a one-line
     * introduction naming this node.
     *
     * [--contact-email=<email>]
     * : How their administrator can reply. Defaults to the site admin email.
     *
     * ## EXAMPLES
     *
     *     wp openyacht partner introduce broker.example
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     */
    public function introduce(array $args, array $assocArgs): void
    {
        $partner = $this->find($args[0]);

        if ($partner->trustLevel === TrustLevel::Blocked) {
            WP_CLI::error("Partner {$partner->domain} is blocked here; approve it first if the partnership should resume.");
        }

        $this->reportIntroduction($partner, Services::partnerService()->introduce(
            $partner,
            isset($assocArgs['message']) ? (string) $assocArgs['message'] : null,
            isset($assocArgs['contact-email']) ? (string) $assocArgs['contact-email'] : null,
        ));
    }

    private function reportIntroduction(Partner $partner, PartnerIntroduction $introduction): void
    {
        match ($introduction->outcome) {
            IntroductionOutcome::Delivered => WP_CLI::success("Partnership request delivered to {$partner->domain}: it appears there as a provisional partner until they approve it."),
            IntroductionOutcome::Accepted => WP_CLI::success("{$partner->domain} already lists this node as a verified partner."),
            IntroductionOutcome::Blocked => WP_CLI::warning("{$partner->domain} has blocked this node."),
            IntroductionOutcome::Failed => WP_CLI::warning("Partnership request to {$partner->domain} not delivered: " . rtrim($introduction->detail, '.') . ". Resend with: wp openyacht partner introduce {$partner->domain}"),
        };
    }

    /**
     * List registered partners.
     *
     * @subcommand list
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     */
    public function list_(array $args, array $assocArgs): void
    {
        $rows = array_map(static fn (Partner $partner): array => [
            'domain' => $partner->domain,
            'trust' => $partner->trustLevel->value,
            'node_uuid' => $partner->nodeUuid ?? '',
            'pinned_key' => $partner->pinnedKeyId ?? '',
            'copies' => Services::copies()->countForPartner($partner->id),
            'last_ok_at' => $partner->lastOkAt ?? '',
            'failures' => $partner->consecutiveFailures,
            'stale' => $partner->isStale() ? 'yes' : '',
        ], Services::partners()->all());

        \WP_CLI\Utils\format_items('table', $rows, ['domain', 'trust', 'node_uuid', 'pinned_key', 'copies', 'last_ok_at', 'failures', 'stale']);
    }

    /**
     * Approve a provisional partner (human trust decision, FP-13).
     *
     * ## OPTIONS
     *
     * <domain>
     * : The partner's domain.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     */
    public function approve(array $args, array $assocArgs): void
    {
        $partner = $this->find($args[0]);
        $userId = get_current_user_id();
        Services::partnerService()->approve($partner, $userId > 0 ? $userId : null);

        WP_CLI::success("Partner {$partner->domain} approved (verified).");
    }

    /**
     * Block a partner: all its requests are rejected (FP-9).
     *
     * ## OPTIONS
     *
     * <domain>
     * : The partner's domain.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     */
    public function block(array $args, array $assocArgs): void
    {
        $partner = $this->find($args[0]);
        Services::partnerService()->block($partner);

        WP_CLI::success("Partner {$partner->domain} blocked.");
    }

    /**
     * Remove a partner nothing has been received from — the undo for a
     * mistaken add. Once listings have been received the partner row is
     * their provenance anchor (ID-3): block it instead.
     *
     * ## OPTIONS
     *
     * <domain>
     * : The partner's domain.
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     */
    public function remove(array $args, array $assocArgs): void
    {
        $partner = $this->find($args[0]);

        try {
            Services::partnerService()->remove($partner);

            WP_CLI::success("Partner {$partner->domain} removed. If it polls again it reappears as a new provisional partner.");
        } catch (Throwable $exception) {
            WP_CLI::error($exception->getMessage());
        }
    }

    /**
     * Refetch a partner's well-known document and refresh its cached keys.
     * A changed node UUID downgrades the partner to provisional (FP-11).
     *
     * ## OPTIONS
     *
     * <domain>
     * : The partner's domain.
     *
     * @subcommand refresh-keys
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     */
    public function refreshKeys(array $args, array $assocArgs): void
    {
        $partner = $this->find($args[0]);

        try {
            $fresh = Services::partnerService()->refreshKeys($partner);

            WP_CLI::success("Keys refreshed for {$fresh->domain} (trust: {$fresh->trustLevel->value}).");
        } catch (Throwable $exception) {
            WP_CLI::error($exception->getMessage());
        }
    }

    private function find(string $domain): Partner
    {
        $partner = Services::partners()->findByDomain($domain);

        if ($partner === null) {
            WP_CLI::error("No partner registered for domain [{$domain}].");
        }

        return $partner;
    }
}
