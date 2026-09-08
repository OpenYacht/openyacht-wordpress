<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * The consumer half of push subscriptions (API-11), wire side: register
 * this node's inbox as a partner's callback, or remove it. What arrives
 * at the inbox is handled by the Router and SyncService::applyDelivery().
 *
 * A subscription is an optional feature, so the partner's capabilities
 * document is checked before the request (API-7); a partner without it
 * is simply polled. It also never replaces polling: a subscribed partner
 * is still reconciled by a daily updated_since poll (SyncService::isDue),
 * because missed webhooks are a fact of life.
 *
 * // api-design.md §Subscriptions
 */
final class SubscriptionClient
{
    public const PATH = '/openyacht/v1/subscriptions';

    /**
     * @param string|null $ownDomain this node's identity domain; resolved
     *                               from NodeConfig when null (tests pass
     *                               it explicitly)
     */
    public function __construct(
        private readonly FederationClient $client,
        private readonly PartnerRepository $partners,
        private readonly CapabilitiesClient $capabilities,
        private readonly Logger $logger,
        private readonly ?string $ownDomain = null,
    ) {
    }

    /**
     * The inbox partners deliver to: fixed on the identity domain, like
     * every other federation path.
     */
    public function inboxUrl(): string
    {
        return 'https://' . ($this->ownDomain ?? NodeConfig::identityDomain()) . '/openyacht/v1/inbox';
    }

    /**
     * @throws SubscriptionFailed
     */
    public function subscribe(Partner $partner): void
    {
        // The inbox accepts pushes from verified partners only — the same
        // bar as the feed — so subscribing to anyone else would only
        // produce deliveries this node then rejects.
        if ($partner->trustLevel !== TrustLevel::Verified) {
            throw new SubscriptionFailed("{$partner->domain} is not a verified partner here, so its pushes would be refused at the inbox. Approve it first.");
        }

        if (! $this->capabilities->advertises($partner->domain, 'subscriptions')) {
            throw new SubscriptionFailed("{$partner->domain} does not offer push subscriptions; it stays on the polling schedule.");
        }

        $response = $this->attempt($partner, fn (): HttpResponse => $this->client->post($partner, self::PATH, ['callback' => $this->inboxUrl()]));

        if (! $response->ok()) {
            throw new SubscriptionFailed($this->unexpectedAnswer($partner, $response));
        }

        $this->partners->update($partner->id, ['push_subscribed_at' => gmdate('Y-m-d H:i:s')]);
        $this->logger->log('partner', "Subscribed to pushes from {$partner->domain}", 'push_subscribed', $partner->id, self::PATH, ['callback' => $this->inboxUrl()]);
    }

    /**
     * Stop accepting the partner's pushes and return it to the hourly
     * poll. The local record is cleared whatever the partner answers —
     * that is what the operator asked for — and the remote registration
     * is best-effort cleanup: a partner that no longer serves the
     * endpoint (404/405/410) has nothing left to remove. Returns a
     * warning when the partner did not acknowledge the removal.
     */
    public function unsubscribe(Partner $partner): ?string
    {
        $warning = null;

        try {
            $response = $this->attempt($partner, fn (): HttpResponse => $this->client->delete($partner, self::PATH));

            if (! $response->ok() && ! in_array($response->status, [404, 405, 410], true)) {
                $warning = $this->unexpectedAnswer($partner, $response);
            }
        } catch (SubscriptionFailed $exception) {
            $warning = $exception->getMessage();
        }

        $this->partners->update($partner->id, ['push_subscribed_at' => null]);
        $this->logger->log(
            'partner',
            "Unsubscribed from pushes from {$partner->domain}" . ($warning !== null ? " (not acknowledged: {$warning})" : ''),
            'push_unsubscribed',
            $partner->id,
            self::PATH,
        );

        return $warning;
    }

    /**
     * @param callable(): HttpResponse $request
     *
     * @throws SubscriptionFailed
     */
    private function attempt(Partner $partner, callable $request): HttpResponse
    {
        try {
            return $request();
        } catch (RuntimeException $exception) {
            // TransportException (unreachable, or the outbound guard
            // refused the host) or the Signer's "no active key".
            throw new SubscriptionFailed("{$partner->domain} could not be reached: {$exception->getMessage()}", 0, $exception);
        }
    }

    private function unexpectedAnswer(Partner $partner, HttpResponse $response): string
    {
        $code = $response->json()['error']['code'] ?? null;

        return sprintf('%s answered HTTP %d%s.', $partner->domain, $response->status, is_string($code) ? " {$code}" : '');
    }
}
