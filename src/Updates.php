<?php

declare(strict_types=1);

namespace OpenYacht;

/**
 * Self-hosted plugin updates off public GitHub releases — the plugin is not
 * distributed through wordpress.org. The main file's Update URI header opts
 * out of wordpress.org update checks entirely and routes this plugin through
 * the update_plugins_github.com filter, where we feed core the latest
 * release; core compares versions and runs its normal update UX.
 *
 * The release contract: a published (non-draft, non-prerelease) GitHub
 * release whose tag is the plugin version (optionally v-prefixed) carrying
 * an asset named openyacht-{version}.zip — exactly what bin/build.sh emits.
 */
final class Updates
{
    public const REPO = 'OpenYacht/openyacht-wordpress';

    private const TRANSIENT = 'openyacht_latest_release';
    private const CACHE_TTL = 12 * HOUR_IN_SECONDS;
    private const FAILURE_TTL = HOUR_IN_SECONDS;

    public function register(): void
    {
        // Hostname-scoped, so every github.com-updated plugin on the site
        // shares this filter — the basename check keeps us to our own row.
        add_filter('update_plugins_github.com', function ($update, array $pluginData, string $pluginFile) {
            if ($pluginFile !== plugin_basename(OPENYACHT_FILE)) {
                return $update;
            }

            return $this->updateInfo() ?? $update;
        }, 10, 3);

        add_filter('plugins_api', function ($result, string $action, object $args) {
            if ($action !== 'plugin_information' || ($args->slug ?? null) !== 'openyacht') {
                return $result;
            }

            return $this->pluginInformation() ?? $result;
        }, 10, 3);
    }

    /**
     * The array shape core expects from update_plugins_{hostname}: it
     * version_compares 'version' against the installed plugin itself, so
     * returning the current release when no update is due is correct.
     *
     * @return array<string, mixed>|null
     */
    public function updateInfo(): ?array
    {
        $release = $this->latestRelease();

        if ($release === null) {
            return null;
        }

        return [
            'id'           => 'https://github.com/' . self::REPO,
            'slug'         => 'openyacht',
            'plugin'       => plugin_basename(OPENYACHT_FILE),
            'version'      => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'requires'     => '6.4',
            'requires_php' => '8.1',
        ];
    }

    /**
     * Backs the "View details" modal on the Plugins screen.
     */
    private function pluginInformation(): ?object
    {
        $release = $this->latestRelease();

        if ($release === null) {
            return null;
        }

        return (object) [
            'name'          => 'OpenYacht',
            'slug'          => 'openyacht',
            'version'       => $release['version'],
            'author'        => '<a href="https://openyacht.org">OpenYacht</a>',
            'homepage'      => 'https://openyacht.org',
            'requires'      => '6.4',
            'requires_php'  => '8.1',
            'download_link' => $release['package'],
            'last_updated'  => $release['published_at'],
            'sections'      => [
                'description' => '<p>' . esc_html__('Turns this WordPress site into an OpenYacht node — federated yacht-listing sharing between brokerages.', 'openyacht') . '</p>',
                'changelog'   => wp_kses_post(wpautop($release['notes'])),
            ],
        ];
    }

    /**
     * Latest published release, cached so update checks (twice-daily cron
     * plus every Plugins-screen load) stay far inside GitHub's 60/hour
     * unauthenticated API allowance. Failures cache shorter, as a miss.
     *
     * @return array{version: string, package: string, url: string, notes: string, published_at: string}|null
     */
    private function latestRelease(): ?array
    {
        $cached = get_transient(self::TRANSIENT);

        if ($cached === 'miss') {
            return null;
        }

        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get('https://api.github.com/repos/' . self::REPO . '/releases/latest', [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/vnd.github+json'],
        ]);

        $release = null;

        if (! is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $release = is_array($body) ? self::releaseToUpdate($body) : null;
        }

        if ($release === null) {
            set_transient(self::TRANSIENT, 'miss', self::FAILURE_TTL);

            return null;
        }

        set_transient(self::TRANSIENT, $release, self::CACHE_TTL);

        return $release;
    }

    /**
     * Pure release-payload parsing, unit-tested without WordPress.
     *
     * @param array<string, mixed> $release decoded GitHub release object
     * @return array{version: string, package: string, url: string, notes: string, published_at: string}|null
     */
    public static function releaseToUpdate(array $release): ?array
    {
        if (($release['draft'] ?? false) || ($release['prerelease'] ?? false)) {
            return null;
        }

        $tag = $release['tag_name'] ?? null;

        if (! is_string($tag) || $tag === '') {
            return null;
        }

        $version = ltrim($tag, 'v');

        if (preg_match('/^\d+\.\d+\.\d+/', $version) !== 1) {
            return null;
        }

        $assetName = "openyacht-{$version}.zip";
        $package = null;

        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (is_array($asset) && ($asset['name'] ?? null) === $assetName && is_string($asset['browser_download_url'] ?? null)) {
                $package = $asset['browser_download_url'];
                break;
            }
        }

        // A release without its build asset is a broken release — advertising
        // it would send core after a package that 404s. Treat as no release.
        if ($package === null) {
            return null;
        }

        return [
            'version'      => $version,
            'package'      => $package,
            'url'          => is_string($release['html_url'] ?? null) ? $release['html_url'] : 'https://github.com/' . self::REPO . '/releases',
            'notes'        => is_string($release['body'] ?? null) ? $release['body'] : '',
            'published_at' => is_string($release['published_at'] ?? null) ? $release['published_at'] : '',
        ];
    }
}
