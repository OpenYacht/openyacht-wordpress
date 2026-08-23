<?php

declare(strict_types=1);

namespace OpenYacht\Admin;

/**
 * One option, one declarative field schema. fields() is the single source
 * of truth for registration, rendering, defaults, and sanitisation — never
 * two hand-synced lists.
 */
final class Settings
{
    public const OPTION = 'openyacht_settings';

    private const PAGE = 'openyacht';

    public function register(): void
    {
        // After PartnersPage's default-priority admin_menu callback: the
        // parent menu must exist before this submenu attaches to it, or WP
        // cannot map the page to a capability ("not allowed to access").
        add_action('admin_menu', [$this, 'addPage'], 20);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_post_openyacht_node_listing', [$this, 'handleNodeListing']);
    }

    /**
     * @return array<string, array{label: string, type: 'text'|'checkbox'|'select', section: string, default: string|bool, description?: string, options?: array<string, string>}>
     */
    public function fields(): array
    {
        $driverOptions = [];

        foreach (\OpenYacht\Media\StorageFactory::drivers() as $name => $driver) {
            $driverOptions[$name] = (string) $driver['label'];
        }

        return [
            'node_name' => [
                'label' => __('Node name', 'openyacht'),
                'type' => 'text',
                'section' => 'identity',
                'default' => (string) get_bloginfo('name'),
                'description' => __('Published in this node\'s /.well-known/openyacht discovery document.', 'openyacht'),
            ],
            'media_policy' => [
                'label' => __('Cache partner media for', 'openyacht'),
                'type' => 'select',
                'section' => 'media',
                'default' => 'selected',
                'options' => [
                    'selected' => __('Listings selected for import only (recommended)', 'openyacht'),
                    'all' => __('Every synced listing (can use a lot of disk)', 'openyacht'),
                ],
                'description' => __('Listing data always syncs in full; this controls image caching. Unselected listings preview via the partner\'s own thumbnails.', 'openyacht'),
            ],
            'storage_driver' => [
                'label' => __('Media storage', 'openyacht'),
                'type' => 'select',
                'section' => 'media',
                'default' => 'local',
                'options' => $driverOptions,
                'description' => __('Where cached partner images are stored. Currently this site\'s uploads folder; cloud options (S3, R2) will appear here once a storage add-on plugin is installed.', 'openyacht'),
            ],
            'delete_data_on_uninstall' => [
                'label' => __('Delete all data on uninstall', 'openyacht'),
                'type' => 'checkbox',
                'section' => 'uninstall',
                'default' => false,
                'description' => __('When enabled, deleting the plugin removes every OpenYacht table, setting, and signing key. Keys and listing provenance cannot be recovered afterwards.', 'openyacht'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function sections(): array
    {
        return [
            'identity' => __('Node identity', 'openyacht'),
            'media' => __('Partner media', 'openyacht'),
            'uninstall' => __('Uninstall', 'openyacht'),
        ];
    }

    /**
     * Stored settings merged over declared defaults.
     *
     * @return array<string, string|bool>
     */
    public function all(): array
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $values = [];

        foreach ($this->fields() as $key => $field) {
            $values[$key] = array_key_exists($key, $stored) ? $stored[$key] : $field['default'];
        }

        return $values;
    }

    public static function get(string $key): string|bool|null
    {
        return (new self())->all()[$key] ?? null;
    }

    public function addPage(): void
    {
        add_submenu_page(
            PartnersPage::MENU_SLUG,
            __('OpenYacht Settings', 'openyacht'),
            __('Settings', 'openyacht'),
            'manage_options',
            self::PAGE,
            [$this, 'renderPage'],
        );
    }

    public function registerSettings(): void
    {
        register_setting(self::PAGE, self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
        ]);

        foreach ($this->sections() as $id => $title) {
            add_settings_section(self::PAGE . '_' . $id, $title, '__return_null', self::PAGE);
        }

        foreach ($this->fields() as $key => $field) {
            add_settings_field(
                $key,
                $field['label'],
                function () use ($key, $field): void {
                    $this->renderField($key, $field);
                },
                self::PAGE,
                self::PAGE . '_' . $field['section'],
                ['label_for' => self::OPTION . '_' . $key],
            );
        }
    }

    /**
     * @param mixed $input
     * @return array<string, string|bool>
     */
    public function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $clean = [];

        foreach ($this->fields() as $key => $field) {
            $clean[$key] = match ($field['type']) {
                'checkbox' => ! empty($input[$key]),
                'select' => isset($input[$key]) && isset($field['options'][(string) $input[$key]])
                    ? (string) $input[$key]
                    : (string) $field['default'],
                'text' => isset($input[$key]) && is_scalar($input[$key])
                    ? sanitize_text_field((string) $input[$key])
                    : (string) $field['default'],
            };
        }

        return $clean;
    }

    /**
     * @param array{label: string, type: 'text'|'checkbox', section: string, default: string|bool, description?: string} $field
     */
    private function renderField(string $key, array $field): void
    {
        $id = self::OPTION . '_' . $key;
        $name = self::OPTION . '[' . $key . ']';
        $value = $this->all()[$key];

        switch ($field['type']) {
            case 'select':
                printf('<select id="%s" name="%s">', esc_attr($id), esc_attr($name));

                foreach ($field['options'] ?? [] as $optionValue => $optionLabel) {
                    printf(
                        '<option value="%s" %s>%s</option>',
                        esc_attr((string) $optionValue),
                        selected((string) $value, (string) $optionValue, false),
                        esc_html((string) $optionLabel),
                    );
                }

                echo '</select>';
                break;
            case 'checkbox':
                printf(
                    '<input type="checkbox" id="%s" name="%s" value="1" %s>',
                    esc_attr($id),
                    esc_attr($name),
                    checked((bool) $value, true, false),
                );
                break;
            case 'text':
                printf(
                    '<input type="text" class="regular-text" id="%s" name="%s" value="%s">',
                    esc_attr($id),
                    esc_attr($name),
                    esc_attr((string) $value),
                );
                break;
        }

        if (isset($field['description'])) {
            printf('<p class="description">%s</p>', esc_html($field['description']));
        }
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap"><h1>' . esc_html__('OpenYacht', 'openyacht') . '</h1>';
        echo '<form action="options.php" method="post">';
        settings_fields(self::PAGE);
        do_settings_sections(self::PAGE);
        submit_button();
        echo '</form>';
        $this->renderNodeDirectory();
        echo '</div>';
    }

    /**
     * The node-directory consent surface: one screen producing the signed
     * two-line listing request the operator pastes into the project's
     * issue form. The directory is an advisory phonebook — being in it
     * grants nothing, being absent costs nothing.
     */
    private function renderNodeDirectory(): void
    {
        $domain = \OpenYacht\Federation\NodeConfig::identityDomain();

        echo '<hr style="margin:2em 0;">';
        echo '<h2>' . esc_html__('Node directory', 'openyacht') . '</h2>';
        echo '<p class="description" style="max-width:640px;">' . esc_html__('The OpenYacht project publishes an optional phonebook of nodes that asked to be listed — being in it grants nothing and being absent costs nothing; partners always verify this node from its own domain. Listing, delisting, and amending require a request signed with this node\'s federation key. Generate one below and paste both lines into the listing issue form.', 'openyacht') . '</p>';

        $result = get_transient($this->nodeListingTransient());

        if (is_array($result) && isset($result['error'])) {
            delete_transient($this->nodeListingTransient());
            echo '<div class="notice notice-error inline"><p>' . esc_html((string) $result['error']) . '</p></div>';
            $result = null;
        }

        if (is_array($result)) {
            delete_transient($this->nodeListingTransient());
            echo '<table class="form-table" role="presentation"><tbody>';
            echo '<tr><th scope="row">' . esc_html__('Token', 'openyacht') . '</th><td><code style="user-select:all;word-break:break-all;">' . esc_html((string) $result['token']) . '</code></td></tr>';
            echo '<tr><th scope="row">' . esc_html__('Signature', 'openyacht') . '</th><td><code style="user-select:all;word-break:break-all;">' . esc_html((string) $result['signature']) . '</code></td></tr>';
            echo '</tbody></table>';
            echo '<p class="description">' . wp_kses(
                sprintf(
                    /* translators: 1: issue form URL. */
                    __('Valid for ±30 days. Submit it via the <a href="%1$s" target="_blank" rel="noopener">node-listing issue form</a>; for a new listing the form also asks for the display name, website, and country of the principal office.', 'openyacht'),
                    'https://github.com/OpenYacht/protocol/issues/new?template=node-listing.yml',
                ),
                ['a' => ['href' => true, 'target' => true, 'rel' => true]],
            ) . '</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:8px;align-items:center;margin-top:8px;">';
        echo '<input type="hidden" name="action" value="openyacht_node_listing">';
        wp_nonce_field('openyacht_node_listing');
        echo '<code>' . esc_html($domain) . '</code>';
        echo '<select name="directory_action">';

        foreach (\OpenYacht\Federation\NodeDirectory::ACTIONS as $action) {
            $labels = ['list' => __('List this node', 'openyacht'), 'delist' => __('Delist this node', 'openyacht'), 'amend' => __('Amend the entry', 'openyacht')];
            printf('<option value="%s">%s</option>', esc_attr($action), esc_html($labels[$action] ?? $action));
        }

        echo '</select>';
        submit_button(__('Generate signed request', 'openyacht'), 'secondary', '', false);
        echo '</form>';
    }

    public function handleNodeListing(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage OpenYacht settings.', 'openyacht'));
        }

        check_admin_referer('openyacht_node_listing');

        $action = isset($_POST['directory_action']) ? sanitize_key(wp_unslash($_POST['directory_action'])) : '';

        try {
            $request = (new \OpenYacht\Federation\NodeDirectory(\OpenYacht\Services::keyManager()))->listingRequest($action);
            set_transient($this->nodeListingTransient(), $request, 300);
            \OpenYacht\Services::logger()->log('node', "Node-directory {$action} request generated for " . \OpenYacht\Federation\NodeConfig::identityDomain(), 'directory_request');
        } catch (\Throwable $exception) {
            set_transient($this->nodeListingTransient(), ['error' => $exception->getMessage()], 60);
        }

        wp_safe_redirect(add_query_arg(['page' => self::PAGE], admin_url('admin.php')));
        exit;
    }

    private function nodeListingTransient(): string
    {
        return 'openyacht_node_listing_' . get_current_user_id();
    }
}
