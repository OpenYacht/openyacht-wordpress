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
    }

    /**
     * @return array<string, array{label: string, type: 'text'|'checkbox', section: string, default: string|bool, description?: string}>
     */
    public function fields(): array
    {
        return [
            'node_name' => [
                'label' => __('Node name', 'openyacht'),
                'type' => 'text',
                'section' => 'identity',
                'default' => (string) get_bloginfo('name'),
                'description' => __('Published in this node\'s /.well-known/openyacht discovery document.', 'openyacht'),
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
        echo '</form></div>';
    }
}
