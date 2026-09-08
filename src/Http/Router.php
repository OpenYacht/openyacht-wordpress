<?php

declare(strict_types=1);

namespace OpenYacht\Http;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Federation\BlockedOutboundHost;
use OpenYacht\Federation\ErrorCode;
use OpenYacht\Federation\NodeConfig;
use OpenYacht\Federation\Partner;
use OpenYacht\Services;

/**
 * Serves the federation HTTP surface at the site root — the spec's exact
 * paths (/.well-known/openyacht, /openyacht/v1/*), not /wp-json/....
 *
 * Deliberately not the WP REST API: signature verification MUST see the
 * raw request-target (path + query) and raw body bytes, which
 * WP_REST_Request re-parses. Requests are matched on parse_request straight
 * from $_SERVER['REQUEST_URI'], needing no rewrite rules or flushes.
 *
 * // federation-protocol.md §Request Signing (raw signing-string inputs)
 */
final class Router
{
    /**
     * The discovery document is public, unsigned, and identical for every
     * caller, so it may be cached. Deliberately minutes rather than the 24
     * hours the spec recommends consumers hold it for: that 24 hours is an
     * application-layer cache the consumer controls and can bypass on a
     * verification failure to pick up rotated keys (FP-10), whereas an
     * intermediary honouring our max-age is one they cannot. Key-rotation
     * recovery is worth far more than the handful of origin hits this
     * low-volume endpoint saves.
     *
     * // federation-protocol.md §Discovery: the well-known endpoint
     */
    private const WELL_KNOWN_MAX_AGE = 300;

    /**
     * Capabilities carry no keys and nothing recovery-critical: the flags
     * change only on upgrade, and a consumer briefly not seeing a feature
     * degrades gracefully by design (API-6).
     */
    private const CAPABILITIES_MAX_AGE = 3600;

    public function register(): void
    {
        add_action('parse_request', [$this, 'maybeDispatch'], 0);
    }

    public function maybeDispatch(): void
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        if ($path === '/.well-known/openyacht') {
            $this->guardIdentityDomain();
            JsonResponder::send(Services::wellKnownDocument()->toArray(), cacheSeconds: self::WELL_KNOWN_MAX_AGE);
        }

        if (str_starts_with($path, '/openyacht/v1/')) {
            $this->guardIdentityDomain();
            $this->dispatchV1(substr($path, strlen('/openyacht/v1/')), RequestContext::fromGlobals());
        }
    }

    private function dispatchV1(string $route, RequestContext $request): never
    {
        if ($route === 'listings') {
            $this->requireMethod($request, ['GET']);
            $this->signed($request, static fn (Partner $partner): array => Services::listingsEndpoint()->index($partner, $_GET));
        }

        if (preg_match('#^listings/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$#', $route, $matches) === 1) {
            $this->requireMethod($request, ['GET']);
            $this->signed($request, static fn (Partner $partner): array => Services::listingsEndpoint()->show($partner, $matches[1]));
        }

        switch ($route) {
            case 'health':
                JsonResponder::send([
                    'status' => 'ok',
                    'time' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
                // JsonResponder::send() exits.
                // no break
            case 'capabilities':
                // Unsigned capability negotiation (API-6). `features` lists
                // optional protocol features only — the sale-listing schema
                // and updated_since sync are the mandatory baseline.
                // charter_listings governs whether the node implements the
                // charter block of the wire schema, not what inventory it
                // holds: this node authors, serves and consumes type:
                // "charter" listings with a charter object.
                JsonResponder::send([
                    'protocol_versions' => NodeConfig::PROTOCOL_VERSIONS,
                    'features' => [
                        'subscriptions' => Services::subscriptionService()->offersSubscriptions(),
                        'charter_listings' => true,
                        'media_hashes' => true,
                    ],
                    'limits' => [
                        'page_size_max' => NodeConfig::PAGE_SIZE_MAX,
                        'rate_per_hour' => NodeConfig::RATE_PER_HOUR,
                    ],
                ], cacheSeconds: self::CAPABILITIES_MAX_AGE);
                // JsonResponder::send() exits.
                // no break
            case 'partners/request':
                $this->requireMethod($request, ['POST']);
                $this->partnersRequest($request);
                // partnersRequest() exits.
                // no break
            case 'subscriptions':
                // A node not offering the feature has no such route (API-10):
                // answered before authentication, like any unknown path.
                if (! Services::subscriptionService()->offersSubscriptions()) {
                    JsonResponder::error(ErrorCode::NotFound, 'This node does not offer push subscriptions.');
                }

                $this->requireMethod($request, ['POST', 'DELETE']);
                $this->signed($request, fn (Partner $partner): array => $request->method === 'POST'
                    ? $this->registerSubscription($partner, $request)
                    : $this->removeSubscription($partner));
                // signed() exits.
                // no break
            case 'inbox':
                $this->requireMethod($request, ['POST']);
                $this->signed($request, fn (Partner $partner): array => $this->inbox($partner, $request));
                // signed() exits.
                // no break
            default:
                JsonResponder::error(ErrorCode::NotFound, 'Unknown federation endpoint.');
        }
    }

    /**
     * The signed surface: authenticate (verified partners only), enforce
     * the advertised rate limit (429 + Retry-After, API-9), run the
     * endpoint, emit.
     *
     * @param callable(Partner): array{payload?: array<string, mixed>, status?: int, error?: ErrorCode, message?: string} $handler
     */
    private function signed(RequestContext $request, callable $handler): never
    {
        $result = Services::inboundVerification()->authenticate($request);

        if (! $result->verified() || $result->partner === null) {
            JsonResponder::error($result->error ?? ErrorCode::SignatureInvalid, $result->message);
        }

        $retryAfter = (new RateLimiter())->hit($result->partner->id);

        if ($retryAfter !== null) {
            JsonResponder::error(
                ErrorCode::RateLimited,
                'Rate limit exceeded.',
                [],
                ['Retry-After' => (string) $retryAfter],
            );
        }

        $outcome = $handler($result->partner);

        if (isset($outcome['error'])) {
            JsonResponder::error($outcome['error'], $outcome['message'] ?? '');
        }

        if (($outcome['status'] ?? 200) === 204) {
            JsonResponder::noContent();
        }

        JsonResponder::send($outcome['payload'] ?? [], $outcome['status'] ?? 200);
    }

    /**
     * @param list<string> $allowed
     */
    private function requireMethod(RequestContext $request, array $allowed): void
    {
        if (in_array($request->method, $allowed, true)) {
            return;
        }

        // The status says it; the error envelope's code list has no
        // method-not-allowed entry (api-design.md §Errors).
        JsonResponder::error(
            ErrorCode::NotFound,
            'Method not allowed on this endpoint; see the Allow header.',
            [],
            ['Allow' => implode(', ', $allowed)],
            405,
        );
    }

    /**
     * Push subscription registration (API-10): one HTTPS callback per
     * partner, replacing any previous one. The callback is partner-
     * supplied and this node will POST to it from the server, so it
     * passes the outbound guard before it is stored (FP-14).
     *
     * @return array{payload?: array<string, mixed>, status?: int, error?: ErrorCode, message?: string}
     */
    private function registerSubscription(Partner $partner, RequestContext $request): array
    {
        $body = json_decode($request->rawBody, true);
        $callback = is_array($body) && is_string($body['callback'] ?? null) ? trim($body['callback']) : '';

        if ($callback === '' || strlen($callback) > 2000) {
            return ['error' => ErrorCode::ValidationError, 'message' => 'callback must be an HTTPS URL.'];
        }

        try {
            Services::subscriptionService()->register($partner, $callback);
        } catch (BlockedOutboundHost $exception) {
            return ['error' => ErrorCode::ValidationError, 'message' => 'callback must be a plain HTTPS URL on a public host: ' . $exception->getMessage()];
        }

        return ['status' => 204];
    }

    /**
     * @return array{status: int}
     */
    private function removeSubscription(Partner $partner): array
    {
        Services::subscriptionService()->unregister($partner);

        return ['status' => 204];
    }

    /**
     * The callback this node registers with partners it subscribes to
     * (API-11): an authority POSTs one changed listing or tombstone here,
     * signed like any federation request. Only partners this node actually
     * subscribed to are accepted — a push from anyone else is unsolicited
     * and answered NOT_FOUND. Deliveries are at-least-once, so a repeat is
     * acknowledged and ignored; everything else applies exactly like a
     * polled item. Any 2xx counts as delivered to the sender.
     *
     * @return array{payload?: array<string, mixed>, status?: int, error?: ErrorCode, message?: string}
     */
    private function inbox(Partner $partner, RequestContext $request): array
    {
        if (! $partner->isPushSubscribed()) {
            return ['error' => ErrorCode::NotFound, 'message' => 'This node holds no subscription with the sender.'];
        }

        $item = json_decode($request->rawBody, true);

        if (! is_array($item) || ! is_string($item['id'] ?? null) || ! is_string($item['updated_at'] ?? null)) {
            return ['error' => ErrorCode::ValidationError, 'message' => 'A delivery is one listing or tombstone object with id and updated_at.'];
        }

        $outcome = Services::syncService()->applyDelivery($partner, $item);
        Services::logger()->log('sync', "Push from {$partner->domain}: {$outcome} {$item['id']}", 'push_' . $outcome, $partner->id, $request->pathWithQuery);

        return ['payload' => ['status' => 'received', 'outcome' => $outcome], 'status' => 200];
    }

    /**
     * Inbound partnership requests (signed; provisional partners allowed —
     * the request is how a partnership starts). Stores the human-readable
     * request on the partner row for administrators to review (FP-13).
     *
     * Lenient on the body by design: a sender that omits a field has still
     * introduced itself. Refusing after authenticating would leave it
     * registered as a provisional partner (trust on first use ran first)
     * while telling it the request failed, and the operator with a
     * first-contact alert and no message.
     *
     * // federation-protocol.md §Partner Lifecycle
     */
    private function partnersRequest(RequestContext $request): never
    {
        // First contact is announced after the request is stored, so the
        // notification carries the sender's message and contact.
        $result = Services::inboundVerification()->authenticate($request, allowProvisional: true, deferFirstContact: true);

        if (! $result->verified() || $result->partner === null) {
            JsonResponder::error($result->error ?? ErrorCode::SignatureInvalid, $result->message);
        }

        $body = json_decode($request->rawBody, true);
        $body = is_array($body) ? $body : [];
        $message = is_string($body['message'] ?? null) ? mb_substr(trim($body['message']), 0, 1000) : null;
        $contactEmail = is_string($body['contact_email'] ?? null) ? mb_substr(trim($body['contact_email']), 0, 255) : null;

        $partner = Services::partnerService()->recordRequest($result->partner, $message, $contactEmail);

        // A sender registered by this very request rides the first-contact
        // notification, now carrying its message; a partner known before
        // it asked gets its own — otherwise the request would be invisible.
        do_action($result->firstContact ? 'openyacht_partner_first_contact' : 'openyacht_partnership_requested', $partner);

        JsonResponder::send([
            'status' => 'received',
            'trust_level' => $partner->trustLevel->value,
        ], 202);
    }

    /**
     * Refuses federation routes on any host other than the identity domain,
     * so the node's identity cannot fork.
     *
     * // federation-protocol.md §Choosing the identity domain
     */
    private function guardIdentityDomain(): void
    {
        $identityDomain = NodeConfig::identityDomain();
        $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $requestHost = (string) preg_replace('/:\d+$/', '', $requestHost);

        if ($identityDomain === '' || $requestHost !== $identityDomain) {
            nocache_headers();
            status_header(404);
            exit;
        }
    }
}
