<?php

declare(strict_types=1);

namespace OpenYacht\Admin;

use OpenYacht\Federation\BuilderRegistry;
use OpenYacht\Federation\CategoryVocabulary;
use OpenYacht\Federation\Listing;
use OpenYacht\Federation\NodeConfig;
use OpenYacht\Federation\RichTextSanitizer;
use OpenYacht\Services;

/**
 * The authoring form: real fields with input-time validation — required
 * fields, closed enums as selects, the vendored builder registry as a
 * fixed choice list with an explicit "unlisted" escape hatch. Native WP
 * admin styling throughout.
 *
 * Submissions parse into the same column map + media rows every other
 * entry path uses; IngestService validates the candidate wire view before
 * anything is stored.
 */
final class ListingForm
{
    private const CURRENCIES = ['EUR', 'USD', 'GBP', 'CHF', 'AUD', 'NZD', 'SGD', 'AED'];

    /**
     * @param array<string, mixed>|null $oldInput re-displayed after a validation failure
     */
    public function render(?Listing $listing, ?array $oldInput): void
    {
        $v = static function (string $key, mixed $fromListing) use ($oldInput): string {
            if ($oldInput !== null) {
                return (string) ($oldInput[$key] ?? '');
            }

            return $fromListing === null ? '' : (string) $fromListing;
        };
        $spec = static function (string $key) use ($oldInput, $listing): string {
            if ($oldInput !== null) {
                return (string) ($oldInput['spec'][$key] ?? '');
            }

            $value = $listing?->specifications[$key] ?? null;

            return $value === null || is_array($value) ? '' : (string) $value;
        };
        $checked = static function (string $key, bool $fromListing) use ($oldInput): bool {
            return $oldInput !== null ? ! empty($oldInput[$key]) : $fromListing;
        };

        $media = $listing !== null ? Services::listingMedia()->forListing($listing->id) : [];
        $profile = null;
        $galleryIds = [];

        foreach ($media as $item) {
            if ($item->kind === 'profile' && $profile === null) {
                $profile = $item;
            } elseif ($item->kind === 'gallery' && $item->attachmentId !== null) {
                $galleryIds[] = $item->attachmentId;
            }
        }

        $profileId = $oldInput !== null ? (string) ($oldInput['profile_id'] ?? '') : (string) ($profile->attachmentId ?? '');
        $galleryCsv = $oldInput !== null ? (string) ($oldInput['gallery_ids'] ?? '') : implode(',', $galleryIds);

        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="openyacht_listing_save">
            <?php if ($listing !== null) : ?>
                <input type="hidden" name="id" value="<?php echo (int) $listing->id; ?>">
            <?php endif; ?>
            <?php wp_nonce_field('openyacht_listing_save'); ?>

            <h2><?php esc_html_e('Listing', 'openyacht'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="oy_name"><?php esc_html_e('Name', 'openyacht'); ?> <span class="description">(<?php esc_html_e('required', 'openyacht'); ?>)</span></label></th>
                    <td><input name="oy[name]" id="oy_name" type="text" class="regular-text" value="<?php echo esc_attr($v('name', $listing?->name)); ?>" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="oy_summary"><?php esc_html_e('Summary', 'openyacht'); ?></label></th>
                    <td><textarea name="oy[summary]" id="oy_summary" class="large-text" rows="3"><?php echo esc_textarea($v('summary', $listing?->summary)); ?></textarea>
                    <p class="description"><?php esc_html_e('Plain-text one-paragraph summary.', 'openyacht'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="oy_condition"><?php esc_html_e('Condition', 'openyacht'); ?></label></th>
                    <td><select name="oy[condition]" id="oy_condition">
                        <?php foreach (['' => '—', 'new' => __('New', 'openyacht'), 'used' => __('Used', 'openyacht')] as $value => $label) : ?>
                            <option value="<?php echo esc_attr((string) $value); ?>" <?php selected($v('condition', $listing?->condition), (string) $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select></td>
                </tr>
            </table>

            <h2><?php esc_html_e('Vessel', 'openyacht'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="oy_builder_slug"><?php esc_html_e('Builder', 'openyacht'); ?></label></th>
                    <td>
                        <select name="oy[builder_slug]" id="oy_builder_slug">
                            <option value=""><?php esc_html_e('— unlisted builder —', 'openyacht'); ?></option>
                            <?php foreach ((new BuilderRegistry())->all() as $builder) : ?>
                                <option value="<?php echo esc_attr($builder['slug']); ?>" <?php selected($v('builder_slug', $listing?->builderSlug), $builder['slug']); ?>><?php echo esc_html($builder['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input name="oy[builder_name]" type="text" class="regular-text" placeholder="<?php esc_attr_e('Unlisted builder name', 'openyacht'); ?>" value="<?php echo esc_attr($v('builder_name', $listing?->builderName)); ?>">
                        <p class="description"><?php esc_html_e('Pick from the vendored registry; only use the free-text name for a builder the registry does not list.', 'openyacht'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="oy_model_name"><?php esc_html_e('Model', 'openyacht'); ?></label></th>
                    <td><input name="oy[model_name]" id="oy_model_name" type="text" class="regular-text" value="<?php echo esc_attr($v('model_name', $listing?->modelName)); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Years', 'openyacht'); ?></th>
                    <td>
                        <label><?php esc_html_e('Built', 'openyacht'); ?> <input name="oy[year_built]" type="number" min="1800" max="2100" class="small-text" value="<?php echo esc_attr($v('year_built', $listing?->yearBuilt)); ?>"></label>
                        <label><?php esc_html_e('Refit', 'openyacht'); ?> <input name="oy[refit_year]" type="number" min="1800" max="2100" class="small-text" value="<?php echo esc_attr($v('refit_year', $listing?->refitYear)); ?>"></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="oy_loa_m"><?php esc_html_e('LOA (m)', 'openyacht'); ?></label></th>
                    <td><input name="oy[loa_m]" id="oy_loa_m" type="number" step="0.01" min="0" class="small-text" value="<?php echo esc_attr($v('loa_m', $listing?->loaM)); ?>">
                    <p class="description"><?php esc_html_e('Metric only — the wire carries no imperial units.', 'openyacht'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Identifiers', 'openyacht'); ?></th>
                    <td>
                        <label>HIN <input name="oy[hin]" type="text" value="<?php echo esc_attr($v('hin', $listing?->hin)); ?>"></label>
                        <label>IMO <input name="oy[imo]" type="text" class="small-text" value="<?php echo esc_attr($v('imo', $listing?->imo)); ?>"></label>
                        <label>MMSI <input name="oy[mmsi]" type="text" class="small-text" value="<?php echo esc_attr($v('mmsi', $listing?->mmsi)); ?>"></label>
                        <p class="description"><?php esc_html_e('Shared only with partners granted the vessel_identifiers field group.', 'openyacht'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="oy_previous_names"><?php esc_html_e('Previous names', 'openyacht'); ?></label></th>
                    <td><input name="oy[previous_names]" id="oy_previous_names" type="text" class="regular-text" value="<?php echo esc_attr($v('previous_names', $listing !== null ? implode(', ', $listing->previousNames) : null)); ?>">
                    <p class="description"><?php esc_html_e('Comma-separated.', 'openyacht'); ?></p></td>
                </tr>
            </table>

            <h2><?php esc_html_e('Price', 'openyacht'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="oy_price_amount"><?php esc_html_e('Asking price', 'openyacht'); ?></label></th>
                    <td>
                        <select name="oy[price_currency]">
                            <option value=""></option>
                            <?php foreach (self::CURRENCIES as $currency) : ?>
                                <option value="<?php echo esc_attr($currency); ?>" <?php selected($v('price_currency', $listing?->priceCurrency), $currency); ?>><?php echo esc_html($currency); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input name="oy[price_amount]" id="oy_price_amount" type="text" inputmode="numeric" pattern="[0-9]*" class="regular-text" value="<?php echo esc_attr($v('price_amount', $listing?->priceAmount)); ?>">
                        <p class="description"><?php esc_html_e('Whole number, no separators. Changes append to the public price history.', 'openyacht'); ?></p>
                        <label><input name="oy[price_on_application]" type="checkbox" value="1" <?php checked($checked('price_on_application', $listing->priceOnApplication ?? false)); ?>> <?php esc_html_e('Price on application (amount never leaves this site)', 'openyacht'); ?></label><br>
                        <label><input name="oy[starting_price]" type="checkbox" value="1" <?php checked($checked('starting_price', $listing->startingPrice ?? false)); ?>> <?php esc_html_e('Starting price (new builds)', 'openyacht'); ?></label>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Location', 'openyacht'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="oy_location_display"><?php esc_html_e('Display location', 'openyacht'); ?></label></th>
                    <td><input name="oy[location_display]" id="oy_location_display" type="text" class="regular-text" value="<?php echo esc_attr($v('location_display', $listing?->locationDisplay)); ?>">
                    <p class="description"><?php esc_html_e('What every partner sees, e.g. "French Riviera".', 'openyacht'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Details', 'openyacht'); ?></th>
                    <td>
                        <label><?php esc_html_e('City', 'openyacht'); ?> <input name="oy[location_city]" type="text" value="<?php echo esc_attr($v('location_city', $listing?->locationCity)); ?>"></label>
                        <label><?php esc_html_e('State', 'openyacht'); ?> <input name="oy[location_state]" type="text" value="<?php echo esc_attr($v('location_state', $listing?->locationState)); ?>"></label>
                        <label><?php esc_html_e('Country', 'openyacht'); ?> <input name="oy[location_country]" type="text" maxlength="2" class="small-text" placeholder="FR" value="<?php echo esc_attr($v('location_country', $listing?->locationCountry)); ?>"></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Exact location', 'openyacht'); ?></th>
                    <td>
                        <label><?php esc_html_e('Marina', 'openyacht'); ?> <input name="oy[location_marina]" type="text" class="regular-text" value="<?php echo esc_attr($v('location_marina', $listing?->locationMarina)); ?>"></label>
                        <label><?php esc_html_e('Lat', 'openyacht'); ?> <input name="oy[location_lat]" type="number" step="any" class="small-text" value="<?php echo esc_attr($v('location_lat', $listing?->locationLat)); ?>"></label>
                        <label><?php esc_html_e('Lon', 'openyacht'); ?> <input name="oy[location_lon]" type="number" step="any" class="small-text" value="<?php echo esc_attr($v('location_lon', $listing?->locationLon)); ?>"></label>
                        <p class="description"><?php esc_html_e('Shared only with partners granted the location_exact field group.', 'openyacht'); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Specifications', 'openyacht'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="oy_power_or_sail"><?php esc_html_e('Power or sail', 'openyacht'); ?> <span class="description">(<?php esc_html_e('required', 'openyacht'); ?>)</span></label></th>
                    <td>
                        <select name="oy[spec][power_or_sail]" id="oy_power_or_sail" required>
                            <option value=""></option>
                            <option value="power" <?php selected($spec('power_or_sail'), 'power'); ?>><?php esc_html_e('Power', 'openyacht'); ?></option>
                            <option value="sail" <?php selected($spec('power_or_sail'), 'sail'); ?>><?php esc_html_e('Sail', 'openyacht'); ?></option>
                        </select>
                        <select name="oy[spec][category_slug]">
                            <option value=""><?php esc_html_e('— no category —', 'openyacht'); ?></option>
                            <?php $categorySlug = $oldInput !== null ? (string) ($oldInput['spec']['category_slug'] ?? '') : (string) ($listing?->specifications['category']['slug'] ?? ''); ?>
                            <?php foreach ((new CategoryVocabulary())->all() as $category) : ?>
                                <option value="<?php echo esc_attr($category['slug']); ?>" <?php selected($categorySlug, $category['slug']); ?>><?php echo esc_html($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Dimensions', 'openyacht'); ?></th>
                    <td>
                        <label><?php esc_html_e('Beam (m)', 'openyacht'); ?> <input name="oy[spec][beam_m]" type="number" step="0.01" class="small-text" value="<?php echo esc_attr($spec('beam_m')); ?>"></label>
                        <label><?php esc_html_e('Max draft (m)', 'openyacht'); ?> <input name="oy[spec][draft_max_m]" type="number" step="0.01" class="small-text" value="<?php echo esc_attr($spec('draft_max_m')); ?>"></label>
                        <label><?php esc_html_e('Gross tonnage', 'openyacht'); ?> <input name="oy[spec][gross_tonnage]" type="number" step="0.1" class="small-text" value="<?php echo esc_attr($spec('gross_tonnage')); ?>"></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Performance', 'openyacht'); ?></th>
                    <td>
                        <label><?php esc_html_e('Cruise (kn)', 'openyacht'); ?> <input name="oy[spec][cruise_speed_kn]" type="number" step="0.1" class="small-text" value="<?php echo esc_attr($spec('cruise_speed_kn')); ?>"></label>
                        <label><?php esc_html_e('Max (kn)', 'openyacht'); ?> <input name="oy[spec][max_speed_kn]" type="number" step="0.1" class="small-text" value="<?php echo esc_attr($spec('max_speed_kn')); ?>"></label>
                        <label><?php esc_html_e('Range (nmi)', 'openyacht'); ?> <input name="oy[spec][range_nmi]" type="number" step="1" class="small-text" value="<?php echo esc_attr($spec('range_nmi')); ?>"></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Accommodation', 'openyacht'); ?></th>
                    <td>
                        <label><?php esc_html_e('Cabins', 'openyacht'); ?> <input name="oy[spec][cabins]" type="number" class="small-text" value="<?php echo esc_attr($spec('cabins')); ?>"></label>
                        <label><?php esc_html_e('Sleeps', 'openyacht'); ?> <input name="oy[spec][sleeps]" type="number" class="small-text" value="<?php echo esc_attr($spec('sleeps')); ?>"></label>
                        <label><?php esc_html_e('Heads', 'openyacht'); ?> <input name="oy[spec][heads]" type="number" class="small-text" value="<?php echo esc_attr($spec('heads')); ?>"></label>
                        <label><?php esc_html_e('Guests cruising', 'openyacht'); ?> <input name="oy[spec][guests_cruising]" type="number" class="small-text" value="<?php echo esc_attr($spec('guests_cruising')); ?>"></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Registry', 'openyacht'); ?></th>
                    <td>
                        <label><?php esc_html_e('Flag', 'openyacht'); ?> <input name="oy[spec][flag]" type="text" value="<?php echo esc_attr($spec('flag')); ?>"></label>
                        <label><?php esc_html_e('Registry port', 'openyacht'); ?> <input name="oy[spec][registry_port]" type="text" value="<?php echo esc_attr($spec('registry_port')); ?>"></label>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Description', 'openyacht'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="oy_overview"><?php esc_html_e('Overview', 'openyacht'); ?></label></th>
                    <td>
                        <?php
                        $overview = $oldInput !== null
                            ? (string) ($oldInput['overview'] ?? '')
                            : (string) ($listing?->descriptions[0]['content'] ?? '');
        ?>
                        <textarea name="oy[overview]" id="oy_overview" class="large-text" rows="10"><?php echo esc_textarea($overview); ?></textarea>
                        <p class="description"><?php esc_html_e('Restricted HTML: p, br, ul, ol, li, strong, em, h3, h4, https links. Everything else is stripped on save.', 'openyacht'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="oy_features"><?php esc_html_e('Features', 'openyacht'); ?></label></th>
                    <td>
                        <?php
        $features = $oldInput !== null
            ? (string) ($oldInput['features'] ?? '')
            : implode("\n", array_column($listing->features ?? [], 'name'));
        ?>
                        <textarea name="oy[features]" id="oy_features" class="large-text" rows="5"><?php echo esc_textarea($features); ?></textarea>
                        <p class="description"><?php esc_html_e('One feature per line.', 'openyacht'); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Media', 'openyacht'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Profile image', 'openyacht'); ?></th>
                    <td>
                        <input type="hidden" name="oy[profile_id]" id="oy_profile_id" value="<?php echo esc_attr($profileId); ?>">
                        <div id="oy_profile_preview" style="margin-bottom:8px;"></div>
                        <button type="button" class="button" id="oy_profile_pick"><?php esc_html_e('Choose profile image', 'openyacht'); ?></button>
                        <button type="button" class="button" id="oy_profile_clear"><?php esc_html_e('Remove', 'openyacht'); ?></button>
                        <p class="description"><?php esc_html_e('The explicit hero image partners must use. A listing with imagery must have one; with no imagery, leave it empty — never a placeholder.', 'openyacht'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Gallery', 'openyacht'); ?></th>
                    <td>
                        <input type="hidden" name="oy[gallery_ids]" id="oy_gallery_ids" value="<?php echo esc_attr($galleryCsv); ?>">
                        <div id="oy_gallery_preview" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;"></div>
                        <button type="button" class="button" id="oy_gallery_pick"><?php esc_html_e('Choose gallery images', 'openyacht'); ?></button>
                        <button type="button" class="button" id="oy_gallery_clear"><?php esc_html_e('Clear', 'openyacht'); ?></button>
                    </td>
                </tr>
            </table>

            <?php submit_button($listing === null ? __('Create listing (as draft)', 'openyacht') : __('Save changes', 'openyacht')); ?>
        </form>
        <?php
    }

    /**
     * @param array<string, mixed> $input the unslashed oy[] array
     * @return array<string, mixed> column map
     */
    public function columnsFromInput(array $input): array
    {
        $text = static fn (string $key): ?string => isset($input[$key]) && trim((string) $input[$key]) !== ''
            ? sanitize_text_field((string) $input[$key])
            : null;
        $number = static fn (string $key): ?string => isset($input[$key]) && trim((string) $input[$key]) !== '' && is_numeric($input[$key])
            ? (string) $input[$key]
            : null;

        $builderSlug = $text('builder_slug');
        $builderName = $builderSlug !== null
            ? (new BuilderRegistry())->canonicalName($builderSlug)
            : $text('builder_name');

        $previousNames = array_values(array_filter(array_map(
            static fn (string $name): string => trim($name),
            explode(',', (string) ($input['previous_names'] ?? '')),
        ), static fn (string $name): bool => $name !== ''));

        $spec = is_array($input['spec'] ?? null) ? $input['spec'] : [];
        $specifications = [];

        foreach (['beam_m', 'draft_max_m', 'gross_tonnage', 'cruise_speed_kn', 'max_speed_kn', 'range_nmi'] as $key) {
            if (isset($spec[$key]) && trim((string) $spec[$key]) !== '' && is_numeric($spec[$key])) {
                $specifications[$key] = (float) $spec[$key];
            }
        }

        foreach (['cabins', 'sleeps', 'heads', 'guests_cruising'] as $key) {
            if (isset($spec[$key]) && trim((string) $spec[$key]) !== '' && is_numeric($spec[$key])) {
                $specifications[$key] = (int) $spec[$key];
            }
        }

        foreach (['flag', 'registry_port'] as $key) {
            if (isset($spec[$key]) && trim((string) $spec[$key]) !== '') {
                $specifications[$key] = sanitize_text_field((string) $spec[$key]);
            }
        }

        if (in_array($spec['power_or_sail'] ?? '', ['power', 'sail'], true)) {
            $specifications['power_or_sail'] = $spec['power_or_sail'];
        }

        $categorySlug = isset($spec['category_slug']) && $spec['category_slug'] !== '' ? sanitize_text_field((string) $spec['category_slug']) : null;

        if ($categorySlug !== null) {
            foreach ((new CategoryVocabulary())->all() as $category) {
                if ($category['slug'] === $categorySlug) {
                    $specifications['category'] = ['name' => $category['name'], 'slug' => $categorySlug];
                }
            }
        }

        $overview = trim((string) ($input['overview'] ?? ''));
        $descriptions = $overview === '' ? [] : [[
            'section' => 'overview',
            'content' => (new RichTextSanitizer())->sanitize($overview, [NodeConfig::identityDomain()]),
        ]];

        $features = [];

        foreach (explode("\n", (string) ($input['features'] ?? '')) as $line) {
            $line = trim(sanitize_text_field($line));

            if ($line !== '') {
                $features[] = ['category' => null, 'name' => $line, 'slug' => null];
            }
        }

        return [
            'name' => $text('name'),
            'summary' => isset($input['summary']) && trim((string) $input['summary']) !== '' ? sanitize_textarea_field((string) $input['summary']) : null,
            'yacht_condition' => in_array($input['condition'] ?? '', ['new', 'used'], true) ? $input['condition'] : null,
            'hin' => $text('hin'),
            'imo' => $text('imo'),
            'mmsi' => $text('mmsi'),
            'official_number' => $text('official_number'),
            'builder_name' => $builderName,
            'builder_slug' => $builderSlug,
            'model_name' => $text('model_name'),
            'year_built' => $number('year_built') !== null ? (int) $number('year_built') : null,
            'refit_year' => $number('refit_year') !== null ? (int) $number('refit_year') : null,
            'loa_m' => $number('loa_m') !== null ? (float) $number('loa_m') : null,
            'previous_names' => $previousNames,
            'price_amount' => preg_match('/^[0-9]+$/', (string) ($input['price_amount'] ?? '')) === 1 ? (string) $input['price_amount'] : null,
            'price_currency' => in_array($input['price_currency'] ?? '', self::CURRENCIES, true) ? $input['price_currency'] : null,
            'price_on_application' => ! empty($input['price_on_application']) ? 1 : 0,
            'starting_price' => ! empty($input['starting_price']) ? 1 : 0,
            'location_display' => $text('location_display'),
            'location_city' => $text('location_city'),
            'location_state' => $text('location_state'),
            'location_country' => preg_match('/^[A-Za-z]{2}$/', (string) ($input['location_country'] ?? '')) === 1 ? strtoupper((string) $input['location_country']) : null,
            'location_marina' => $text('location_marina'),
            'location_lat' => $number('location_lat') !== null ? (float) $number('location_lat') : null,
            'location_lon' => $number('location_lon') !== null ? (float) $number('location_lon') : null,
            'specifications' => $specifications,
            'descriptions' => $descriptions,
            'features' => $features,
            'compliance' => [],
        ];
    }

    /**
     * Attachment picks become media rows: the wire truth (URL, thumbnail,
     * hash, dimensions) is computed from the attachment files here, at
     * save time.
     *
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    public function mediaRowsFromInput(array $input): array
    {
        $rows = [];
        $profileId = isset($input['profile_id']) && is_numeric($input['profile_id']) ? (int) $input['profile_id'] : 0;

        if ($profileId > 0) {
            $row = $this->attachmentRow($profileId, 'profile', 0);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $galleryIds = array_values(array_filter(array_map('intval', explode(',', (string) ($input['gallery_ids'] ?? '')))));

        foreach ($galleryIds as $index => $attachmentId) {
            $row = $this->attachmentRow($attachmentId, 'gallery', $index + 1);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function attachmentRow(int $attachmentId, string $kind, int $sort): ?array
    {
        $url = wp_get_attachment_url($attachmentId);
        $file = get_attached_file($attachmentId);

        if (! is_string($url) || $url === '' || ! is_string($file) || ! file_exists($file)) {
            return null;
        }

        $size = wp_getimagesize($file);
        $thumbnail = wp_get_attachment_image_url($attachmentId, 'large');

        return [
            'kind' => $kind,
            'attachment_id' => $attachmentId,
            'url' => $url,
            'thumbnail_url' => $kind === 'profile' ? (is_string($thumbnail) ? $thumbnail : $url) : null,
            'sha256' => hash_file('sha256', $file) ?: null,
            'width' => is_array($size) ? (int) $size[0] : null,
            'height' => is_array($size) ? (int) $size[1] : null,
            'caption' => wp_get_attachment_caption($attachmentId) ?: null,
            'category' => null,
            'sort' => $sort,
        ];
    }
}
