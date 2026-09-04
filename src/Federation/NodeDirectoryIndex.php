<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * The consumer side of the node directory: the vendored copy of the
 * project's advisory node phonebook, refreshable out of band from the
 * canonical URL only. The URL is deliberately not configurable — a
 * paste-any-URL option would let an operator be talked into loading a
 * bad actor's list, and the directory's whole safety story is that
 * everything verifiable comes from each node's own domain anyway.
 *
 * // federation-protocol.md §Finding partners: the node directory
 */
final class NodeDirectoryIndex
{
    public const CANONICAL_URL = 'https://openyacht.org/registry/nodes.json';

    public const OPTION = 'openyacht_node_directory';

    public function __construct(
        private readonly string $vendoredPath = OPENYACHT_PATH . '/resources/registry/nodes.json',
    ) {
    }

    /**
     * @return list<array{domain: string, name: string, website: string, country: string, listed_at: string}>
     */
    public function entries(): array
    {
        $cached = get_option(self::OPTION, null);

        if (is_array($cached) && is_array($cached['nodes'] ?? null)) {
            return $cached['nodes'];
        }

        $contents = file_get_contents($this->vendoredPath);
        $document = $contents !== false ? json_decode($contents, true) : null;

        return is_array($document) ? $this->validNodes($document) : [];
    }

    public function fetchedAt(): ?string
    {
        $cached = get_option(self::OPTION, null);

        return is_array($cached) && is_string($cached['fetched_at'] ?? null) ? $cached['fetched_at'] : null;
    }

    /**
     * Refresh from the canonical URL. Throws on any transport, shape, or
     * validation failure — a bad fetch never replaces a good cache.
     *
     * @return int entries now cached
     */
    public function refresh(): int
    {
        $response = wp_remote_get(self::CANONICAL_URL, ['timeout' => 15]);

        if (is_wp_error($response)) {
            throw new RuntimeException('Could not reach the node directory: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status !== 200) {
            throw new RuntimeException("The node directory returned HTTP {$status}.");
        }

        $document = json_decode((string) wp_remote_retrieve_body($response), true);

        if (! is_array($document) || ($document['registry'] ?? null) !== 'openyacht-nodes' || ! is_array($document['nodes'] ?? null)) {
            throw new RuntimeException('The node directory response is not a valid openyacht-nodes registry document.');
        }

        $nodes = $this->validNodes($document);
        update_option(self::OPTION, [
            'nodes' => $nodes,
            'version' => is_string($document['version'] ?? null) ? $document['version'] : null,
            'fetched_at' => gmdate('Y-m-d H:i:s'),
        ], false);

        return count($nodes);
    }

    /**
     * Structural validation mirroring the registry's own lint: entries
     * that do not hold their shape are dropped, never displayed.
     *
     * @param array<string, mixed> $document
     * @return list<array{domain: string, name: string, website: string, country: string, listed_at: string}>
     */
    private function validNodes(array $document): array
    {
        $nodes = [];

        foreach (is_array($document['nodes'] ?? null) ? $document['nodes'] : [] as $node) {
            if (! is_array($node)) {
                continue;
            }

            $domain = is_string($node['domain'] ?? null) ? strtolower(trim($node['domain'])) : '';

            if (
                preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) !== 1
                || ! is_string($node['name'] ?? null) || trim($node['name']) === ''
                || ! is_string($node['website'] ?? null) || ! str_starts_with($node['website'], 'https://')
                || ! is_string($node['country'] ?? null) || preg_match('/^[A-Z]{2}$/', $node['country']) !== 1
                || ! is_string($node['listed_at'] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $node['listed_at']) !== 1
            ) {
                continue;
            }

            $nodes[] = [
                'domain' => $domain,
                'name' => sanitize_text_field($node['name']),
                'website' => esc_url_raw($node['website']),
                'country' => $node['country'],
                'listed_at' => $node['listed_at'],
            ];
        }

        return $nodes;
    }
}
