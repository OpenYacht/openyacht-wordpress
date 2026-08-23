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
 * The yacht listing editor — the plugin's first custom-styled admin
 * surface ("quiet nautical": calm fog ground, white sheet, deep navy ink,
 * one brass accent). A sticky rail navigates the grouped sections; long
 * registries (358 builders) are searchable comboboxes; gated fields carry
 * a key chip naming their field group.
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

        [$profileId, $galleryCsv] = $this->mediaState($listing, $oldInput);
        $currentAudience = $oldInput !== null
            ? (string) ($oldInput['audience'] ?? 'everyone')
            : ($listing?->audience->value ?? 'everyone');
        $selectedIds = $oldInput !== null
            ? array_map('intval', (array) ($oldInput['audience_partners'] ?? []))
            : ($listing !== null ? Services::audience()->partnersForListing($listing->id) : []);

        $sections = [
            'oy-identity' => __('Identity', 'openyacht'),
            'oy-vessel' => __('Vessel', 'openyacht'),
            'oy-price' => __('Price', 'openyacht'),
            'oy-location' => __('Location', 'openyacht'),
            'oy-specs' => __('Specifications', 'openyacht'),
            'oy-description' => __('Description', 'openyacht'),
            'oy-media' => __('Media', 'openyacht'),
            'oy-sharing' => __('Sharing', 'openyacht'),
        ];

        ?>
        <div id="openyacht-editor" class="mt-4">
        <!--
        THESIS: a vessel's specification sheet, not a settings page — one calm document the broker completes, with a rail that always knows where you are. Refuses the wp-admin form-table monolith.
        OWN-WORLD: fog ground #eef2f5, white sheet, deep navy ink #142b40, slate-tinted secondaries, one brass accent #a57b2a spent only on required marks and the rail's position dot; hairline navy-tinted rules; searchable registry comboboxes with muted country hints.
        STORY: the broker sees the whole listing at a glance, jumps by section, knows what is required and what partners will see, and saves from anywhere.
        FIRST VIEWPORT: sticky rail left (name, status chip, section list, Save), the sheet right opening on Identity; brass dot marks the live section.
        FORM: user-pinned direction (quiet nautical, sections + sticky rail); no seed roll.
        FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance.
        -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="openyacht_listing_save">
            <?php if ($listing !== null) : ?>
                <input type="hidden" name="id" value="<?php echo (int) $listing->id; ?>">
            <?php endif; ?>
            <?php wp_nonce_field('openyacht_listing_save'); ?>

            <div class="oy-mobilebar">
                <p class="oy-title"><?php echo esc_html($listing->name ?? __('New listing', 'openyacht')); ?></p>
                <span class="oy-chip oy-chip-status <?php echo $listing?->status->value === 'active' ? 'is-active' : ''; ?>">
                    <?php echo esc_html(str_replace('_', ' ', $listing?->status->value ?? 'draft')); ?>
                </span>
            </div>

            <div class="flex items-start gap-6">
                <aside class="oy-rail-sticky sticky top-12 w-56 shrink-0 max-[900px]:hidden">
                    <p class="oy-title"><?php echo esc_html($listing->name ?? __('New listing', 'openyacht')); ?></p>
                    <div class="mt-2 flex items-center gap-2">
                        <span class="oy-chip oy-chip-status <?php echo $listing?->status->value === 'active' ? 'is-active' : ''; ?>">
                            <?php echo esc_html(str_replace('_', ' ', $listing?->status->value ?? 'draft')); ?>
                        </span>
                    </div>

                    <nav class="mt-5 flex flex-col gap-0.5" aria-label="<?php esc_attr_e('Listing sections', 'openyacht'); ?>">
                        <?php foreach ($sections as $id => $label) : ?>
                            <a class="oy-rail-link" href="#<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></a>
                        <?php endforeach; ?>
                    </nav>

                    <div class="mt-6 flex flex-col gap-2">
                        <button type="submit" class="oy-btn oy-btn-primary w-full">
                            <?php echo esc_html($listing === null ? __('Create draft', 'openyacht') : __('Save changes', 'openyacht')); ?>
                        </button>
                        <a class="oy-btn oy-btn-ghost w-full" href="<?php echo esc_url(add_query_arg(['page' => ListingsPage::MENU_SLUG], admin_url('admin.php'))); ?>">
                            <?php esc_html_e('Back to listings', 'openyacht'); ?>
                        </a>
                    </div>
                    <?php if ($listing === null) : ?>
                        <p class="oy-help mt-3"><?php esc_html_e('New listings start as drafts. Drafts are never distributed — publish from the listings screen when ready.', 'openyacht'); ?></p>
                    <?php endif; ?>
                </aside>

                <div class="oy-sheet min-w-0 max-w-3xl flex-1">
                    <section id="oy-identity" class="scroll-mt-16 p-6">
                        <h2 class="oy-section-h"><?php esc_html_e('Identity', 'openyacht'); ?></h2>
                        <p class="oy-section-sub mt-1"><?php esc_html_e('How this listing introduces itself to every partner.', 'openyacht'); ?></p>
                        <div class="mt-5 grid grid-cols-12 gap-x-5 gap-y-5">
                            <div class="col-span-8 max-[900px]:col-span-12">
                                <label class="oy-label" for="oy_name"><?php esc_html_e('Listing name', 'openyacht'); ?><span class="oy-req" aria-hidden="true"></span><span class="screen-reader-text"> (<?php esc_html_e('required', 'openyacht'); ?>)</span></label>
                                <input class="oy-input" name="oy[name]" id="oy_name" type="text" value="<?php echo esc_attr($v('name', $listing?->name)); ?>" required>
                            </div>
                            <div class="col-span-4 max-[900px]:col-span-12">
                                <label class="oy-label" for="oy_condition"><?php esc_html_e('Condition', 'openyacht'); ?></label>
                                <select class="oy-select" name="oy[condition]" id="oy_condition">
                                    <?php foreach (['' => '—', 'new' => __('New', 'openyacht'), 'used' => __('Used', 'openyacht')] as $value => $label) : ?>
                                        <option value="<?php echo esc_attr((string) $value); ?>" <?php selected($v('condition', $listing?->condition), (string) $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-span-12">
                                <label class="oy-label" for="oy_summary"><?php esc_html_e('Summary', 'openyacht'); ?></label>
                                <textarea class="oy-textarea" name="oy[summary]" id="oy_summary" rows="3" placeholder="<?php esc_attr_e('One plain-text paragraph a partner can quote.', 'openyacht'); ?>"><?php echo esc_textarea($v('summary', $listing?->summary)); ?></textarea>
                            </div>
                        </div>
                    </section>
                    <hr class="oy-rule">

                    <section id="oy-vessel" class="scroll-mt-16 p-6">
                        <h2 class="oy-section-h"><?php esc_html_e('Vessel', 'openyacht'); ?></h2>
                        <div class="mt-5 grid grid-cols-12 gap-x-5 gap-y-5">
                            <div class="col-span-6 max-[900px]:col-span-12">
                                <label class="oy-label" for="oy_builder"><?php esc_html_e('Builder', 'openyacht'); ?></label>
                                <?php $this->builderCombobox($v('builder_slug', $listing?->builderSlug)); ?>
                                <p class="oy-help"><?php esc_html_e('Search the shared registry. Registry builders travel with their slug; anything else goes in the unlisted field.', 'openyacht'); ?></p>
                                <div class="mt-3" data-oy-unlisted-builder <?php echo $v('builder_slug', $listing?->builderSlug) !== '' ? 'hidden' : ''; ?>>
                                    <label class="oy-label" for="oy_builder_name"><?php esc_html_e('Unlisted builder', 'openyacht'); ?></label>
                                    <input class="oy-input" name="oy[builder_name]" id="oy_builder_name" type="text" placeholder="<?php esc_attr_e('Only for builders the registry does not list', 'openyacht'); ?>" value="<?php echo esc_attr($v('builder_name', $listing?->builderSlug !== null ? null : $listing?->builderName)); ?>">
                                </div>
                            </div>
                            <div class="col-span-6 max-[900px]:col-span-12">
                                <label class="oy-label" for="oy_model_name"><?php esc_html_e('Model', 'openyacht'); ?></label>
                                <input class="oy-input" name="oy[model_name]" id="oy_model_name" type="text" value="<?php echo esc_attr($v('model_name', $listing?->modelName)); ?>">
                            </div>
                            <div class="col-span-2 max-[900px]:col-span-4">
                                <label class="oy-label" for="oy_year_built"><?php esc_html_e('Built', 'openyacht'); ?></label>
                                <input class="oy-input oy-num" name="oy[year_built]" id="oy_year_built" type="number" min="1800" max="2100" value="<?php echo esc_attr($v('year_built', $listing?->yearBuilt)); ?>">
                            </div>
                            <div class="col-span-2 max-[900px]:col-span-4">
                                <label class="oy-label" for="oy_refit_year"><?php esc_html_e('Refit', 'openyacht'); ?></label>
                                <input class="oy-input oy-num" name="oy[refit_year]" id="oy_refit_year" type="number" min="1800" max="2100" value="<?php echo esc_attr($v('refit_year', $listing?->refitYear)); ?>">
                            </div>
                            <div class="col-span-2 max-[900px]:col-span-4">
                                <label class="oy-label" for="oy_loa_m"><?php esc_html_e('LOA (m)', 'openyacht'); ?></label>
                                <input class="oy-input oy-num" name="oy[loa_m]" id="oy_loa_m" type="number" step="0.01" min="0" value="<?php echo esc_attr($v('loa_m', $listing?->loaM)); ?>">
                            </div>
                            <div class="col-span-12">
                                <label class="oy-label" for="oy_previous_names"><?php esc_html_e('Previous names', 'openyacht'); ?></label>
                                <input class="oy-input" name="oy[previous_names]" id="oy_previous_names" type="text" placeholder="<?php esc_attr_e('Comma-separated', 'openyacht'); ?>" value="<?php echo esc_attr($v('previous_names', $listing !== null ? implode(', ', $listing->previousNames) : null)); ?>">
                            </div>

                            <div class="col-span-12 mt-1 flex items-center gap-2.5">
                                <h3 class="oy-label !mb-0"><?php esc_html_e('Registered identifiers', 'openyacht'); ?></h3>
                                <?php $this->gatedChip(__('Trusted partners only', 'openyacht')); ?>
                            </div>
                            <div class="col-span-4 max-[900px]:col-span-6">
                                <label class="oy-label" for="oy_hin">HIN</label>
                                <input class="oy-input oy-num" name="oy[hin]" id="oy_hin" type="text" value="<?php echo esc_attr($v('hin', $listing?->hin)); ?>">
                            </div>
                            <div class="col-span-2 max-[900px]:col-span-6">
                                <label class="oy-label" for="oy_imo">IMO</label>
                                <input class="oy-input oy-num" name="oy[imo]" id="oy_imo" type="text" value="<?php echo esc_attr($v('imo', $listing?->imo)); ?>">
                            </div>
                            <div class="col-span-3 max-[900px]:col-span-6">
                                <label class="oy-label" for="oy_mmsi">MMSI</label>
                                <input class="oy-input oy-num" name="oy[mmsi]" id="oy_mmsi" type="text" value="<?php echo esc_attr($v('mmsi', $listing?->mmsi)); ?>">
                            </div>
                            <div class="col-span-3 max-[900px]:col-span-6">
                                <label class="oy-label" for="oy_official_number"><?php esc_html_e('Official no.', 'openyacht'); ?></label>
                                <input class="oy-input oy-num" name="oy[official_number]" id="oy_official_number" type="text" value="<?php echo esc_attr($v('official_number', $listing?->officialNumber)); ?>">
                            </div>
                        </div>
                    </section>
                    <hr class="oy-rule">

                    <section id="oy-price" class="scroll-mt-16 p-6">
                        <h2 class="oy-section-h"><?php esc_html_e('Price', 'openyacht'); ?></h2>
                        <p class="oy-section-sub mt-1"><?php esc_html_e('Every change appends to the public price history — it never rewrites it.', 'openyacht'); ?></p>
                        <div class="mt-5 grid grid-cols-12 gap-x-5 gap-y-4">
                            <div class="col-span-2 max-[900px]:col-span-4">
                                <label class="oy-label" for="oy_price_currency"><?php esc_html_e('Currency', 'openyacht'); ?></label>
                                <select class="oy-select" name="oy[price_currency]" id="oy_price_currency">
                                    <option value=""></option>
                                    <?php foreach (self::CURRENCIES as $currency) : ?>
                                        <option value="<?php echo esc_attr($currency); ?>" <?php selected($v('price_currency', $listing?->priceCurrency), $currency); ?>><?php echo esc_html($currency); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-span-4 max-[900px]:col-span-8">
                                <label class="oy-label" for="oy_price_amount"><?php esc_html_e('Asking price', 'openyacht'); ?></label>
                                <input class="oy-input oy-num" name="oy[price_amount]" id="oy_price_amount" type="text" inputmode="numeric" pattern="[0-9]*" placeholder="12500000" value="<?php echo esc_attr($v('price_amount', $listing?->priceAmount)); ?>">
                            </div>
                            <div class="col-span-6 max-[900px]:col-span-12 flex flex-col justify-end gap-2">
                                <label class="oy-check"><input name="oy[price_on_application]" type="checkbox" value="1" <?php checked($checked('price_on_application', $listing->priceOnApplication ?? false)); ?>><span><?php esc_html_e('Price on application — the amount never leaves this site', 'openyacht'); ?></span></label>
                                <label class="oy-check"><input name="oy[starting_price]" type="checkbox" value="1" <?php checked($checked('starting_price', $listing->startingPrice ?? false)); ?>><span><?php esc_html_e('Starting price (new builds)', 'openyacht'); ?></span></label>
                            </div>
                        </div>
                    </section>
                    <hr class="oy-rule">

                    <section id="oy-location" class="scroll-mt-16 p-6">
                        <h2 class="oy-section-h"><?php esc_html_e('Location', 'openyacht'); ?></h2>
                        <div class="mt-5 grid grid-cols-12 gap-x-5 gap-y-5">
                            <div class="col-span-6 max-[900px]:col-span-12">
                                <label class="oy-label" for="oy_location_display"><?php esc_html_e('Display location', 'openyacht'); ?></label>
                                <input class="oy-input" name="oy[location_display]" id="oy_location_display" type="text" placeholder="<?php esc_attr_e('e.g. French Riviera', 'openyacht'); ?>" value="<?php echo esc_attr($v('location_display', $listing?->locationDisplay)); ?>">
                                <p class="oy-help"><?php esc_html_e('What every partner sees. Required whenever any location detail is set.', 'openyacht'); ?></p>
                            </div>
                            <div class="col-span-3 max-[900px]:col-span-5">
                                <label class="oy-label" for="oy_location_city"><?php esc_html_e('City', 'openyacht'); ?></label>
                                <input class="oy-input" name="oy[location_city]" id="oy_location_city" type="text" value="<?php echo esc_attr($v('location_city', $listing?->locationCity)); ?>">
                            </div>
                            <div class="col-span-3 max-[900px]:col-span-4">
                                <label class="oy-label" for="oy_location_state"><?php esc_html_e('State', 'openyacht'); ?></label>
                                <input class="oy-input" name="oy[location_state]" id="oy_location_state" type="text" value="<?php echo esc_attr($v('location_state', $listing?->locationState)); ?>">
                            </div>
                            <div class="col-span-4 max-[900px]:col-span-8">
                                <label class="oy-label" for="oy_location_country"><?php esc_html_e('Country', 'openyacht'); ?></label>
                                <?php $this->countryCombobox($v('location_country', $listing?->locationCountry)); ?>
                            </div>

                            <div class="col-span-12 mt-1 flex items-center gap-2.5">
                                <h3 class="oy-label !mb-0"><?php esc_html_e('Exact position', 'openyacht'); ?></h3>
                                <?php $this->gatedChip(__('Trusted partners only', 'openyacht')); ?>
                            </div>
                            <div class="col-span-6 max-[900px]:col-span-12">
                                <label class="oy-label" for="oy_location_marina"><?php esc_html_e('Marina', 'openyacht'); ?></label>
                                <input class="oy-input" name="oy[location_marina]" id="oy_location_marina" type="text" value="<?php echo esc_attr($v('location_marina', $listing?->locationMarina)); ?>">
                            </div>
                            <div class="col-span-3 max-[900px]:col-span-6">
                                <label class="oy-label" for="oy_location_lat"><?php esc_html_e('Latitude', 'openyacht'); ?></label>
                                <input class="oy-input oy-num" name="oy[location_lat]" id="oy_location_lat" type="number" step="any" value="<?php echo esc_attr($v('location_lat', $listing?->locationLat)); ?>">
                            </div>
                            <div class="col-span-3 max-[900px]:col-span-6">
                                <label class="oy-label" for="oy_location_lon"><?php esc_html_e('Longitude', 'openyacht'); ?></label>
                                <input class="oy-input oy-num" name="oy[location_lon]" id="oy_location_lon" type="number" step="any" value="<?php echo esc_attr($v('location_lon', $listing?->locationLon)); ?>">
                            </div>
                            <div class="col-span-12">
                                <div class="oy-combobox mb-2 flex gap-2" data-oy-map-search>
                                    <input type="text" class="oy-input" id="oy_map_search" placeholder="<?php esc_attr_e('Search a place — marina, town, bay…', 'openyacht'); ?>" aria-label="<?php esc_attr_e('Search the map', 'openyacht'); ?>" autocomplete="off">
                                    <button type="button" class="oy-btn oy-btn-ghost shrink-0" id="oy_map_search_go"><?php esc_html_e('Find', 'openyacht'); ?></button>
                                    <ul class="oy-combobox-list" role="listbox" aria-label="<?php esc_attr_e('Places', 'openyacht'); ?>" hidden></ul>
                                </div>
                                <div id="oy_map" class="oy-map" data-lat="<?php echo esc_attr($v('location_lat', $listing?->locationLat)); ?>" data-lon="<?php echo esc_attr($v('location_lon', $listing?->locationLon)); ?>"></div>
                                <p class="oy-help"><?php esc_html_e('Search to jump somewhere, then click the map to set the exact position; drag the marker to adjust. Clearing the coordinate fields removes it.', 'openyacht'); ?></p>
                            </div>
                        </div>
                    </section>
                    <hr class="oy-rule">

                    <section id="oy-specs" class="scroll-mt-16 p-6">
                        <h2 class="oy-section-h"><?php esc_html_e('Specifications', 'openyacht'); ?></h2>
                        <p class="oy-section-sub mt-1"><?php esc_html_e('Metric only — the wire carries no imperial units. Anything left blank travels as unknown.', 'openyacht'); ?></p>
                        <div class="mt-5 grid grid-cols-12 gap-x-5 gap-y-5">
                            <div class="col-span-3 max-[900px]:col-span-6">
                                <label class="oy-label" for="oy_power_or_sail"><?php esc_html_e('Power or sail', 'openyacht'); ?><span class="oy-req" aria-hidden="true"></span><span class="screen-reader-text"> (<?php esc_html_e('required', 'openyacht'); ?>)</span></label>
                                <select class="oy-select" name="oy[spec][power_or_sail]" id="oy_power_or_sail" required>
                                    <option value=""></option>
                                    <option value="power" <?php selected($spec('power_or_sail'), 'power'); ?>><?php esc_html_e('Power', 'openyacht'); ?></option>
                                    <option value="sail" <?php selected($spec('power_or_sail'), 'sail'); ?>><?php esc_html_e('Sail', 'openyacht'); ?></option>
                                </select>
                            </div>
                            <div class="col-span-5 max-[900px]:col-span-6">
                                <label class="oy-label" for="oy_category"><?php esc_html_e('Category', 'openyacht'); ?></label>
                                <?php $this->categoryCombobox($oldInput !== null ? (string) ($oldInput['spec']['category_slug'] ?? '') : (string) ($listing?->specifications['category']['slug'] ?? '')); ?>
                            </div>
                            <div class="col-span-4 max-[900px]:hidden"></div>

                            <?php
                            $numberFields = [
                                ['beam_m', __('Beam (m)', 'openyacht'), '0.01'],
                                ['draft_max_m', __('Max draft (m)', 'openyacht'), '0.01'],
                                ['gross_tonnage', __('Gross tonnage', 'openyacht'), '0.1'],
                                ['cruise_speed_kn', __('Cruise (kn)', 'openyacht'), '0.1'],
                                ['max_speed_kn', __('Max (kn)', 'openyacht'), '0.1'],
                                ['range_nmi', __('Range (nmi)', 'openyacht'), '1'],
                                ['cabins', __('Cabins', 'openyacht'), '1'],
                                ['sleeps', __('Sleeps', 'openyacht'), '1'],
                                ['heads', __('Heads', 'openyacht'), '1'],
                                ['guests_cruising', __('Guests cruising', 'openyacht'), '1'],
                            ];
        foreach ($numberFields as [$key, $label, $step]) :
            ?>
                                <div class="col-span-2 max-[900px]:col-span-4 flex flex-col justify-end">
                                    <label class="oy-label" for="oy_spec_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                                    <input class="oy-input oy-num" name="oy[spec][<?php echo esc_attr($key); ?>]" id="oy_spec_<?php echo esc_attr($key); ?>" type="number" step="<?php echo esc_attr($step); ?>" min="0" value="<?php echo esc_attr($spec($key)); ?>">
                                </div>
                            <?php endforeach; ?>

                            <div class="col-span-3 max-[900px]:col-span-6">
                                <label class="oy-label" for="oy_spec_flag"><?php esc_html_e('Flag', 'openyacht'); ?></label>
                                <input class="oy-input" name="oy[spec][flag]" id="oy_spec_flag" type="text" value="<?php echo esc_attr($spec('flag')); ?>">
                            </div>
                            <div class="col-span-3 max-[900px]:col-span-6">
                                <label class="oy-label" for="oy_spec_registry_port"><?php esc_html_e('Registry port', 'openyacht'); ?></label>
                                <input class="oy-input" name="oy[spec][registry_port]" id="oy_spec_registry_port" type="text" value="<?php echo esc_attr($spec('registry_port')); ?>">
                            </div>
                        </div>
                    </section>
                    <hr class="oy-rule">

                    <section id="oy-description" class="scroll-mt-16 p-6">
                        <h2 class="oy-section-h"><?php esc_html_e('Description', 'openyacht'); ?></h2>
                        <p class="oy-section-sub mt-1"><?php esc_html_e('Content blocks travel with a section label — overview and highlights are the well-known ones partners place predictably.', 'openyacht'); ?></p>
                        <datalist id="oy_desc_sections">
                            <option value="overview"></option>
                            <option value="highlights"></option>
                        </datalist>
                        <?php
                        $descBlocks = $oldInput !== null
                            ? array_values(array_filter((array) ($oldInput['descriptions'] ?? []), 'is_array'))
                            : ($listing->descriptions ?? []);

        if ($descBlocks === []) {
            $descBlocks = [['section' => 'overview', 'content' => '']];
        }
        ?>
                        <div class="mt-5 flex flex-col gap-6" data-oy-desc-blocks>
                            <?php foreach ($descBlocks as $i => $block) : ?>
                                <?php $this->descriptionBlock((string) $i, (string) ($block['section'] ?? ''), (string) ($block['content'] ?? '')); ?>
                            <?php endforeach; ?>
                        </div>
                        <template id="oy_desc_template"><?php $this->descriptionBlock('__INDEX__', '', '', true); ?></template>
                        <button type="button" class="oy-btn oy-btn-ghost mt-4" id="oy_desc_add"><?php esc_html_e('Add content block', 'openyacht'); ?></button>
                        <p class="oy-help"><?php esc_html_e('The wire allows: p, br, ul, ol, li, strong, em, h3, h4, https links. Anything else is stripped on save — the Text tab shows the exact source.', 'openyacht'); ?></p>

                        <h3 class="oy-section-h mt-8" style="font-size:13.5px;"><?php esc_html_e('Features', 'openyacht'); ?></h3>
                        <?php
        $featureRows = $oldInput !== null
            ? array_values(array_filter((array) ($oldInput['features'] ?? []), 'is_array'))
            : ($listing->features ?? []);

        if ($featureRows === []) {
            $featureRows = [['name' => '', 'category' => '']];
        }
        ?>
                        <div class="mt-3 flex flex-col gap-2" data-oy-feature-rows>
                            <?php foreach ($featureRows as $i => $row) : ?>
                                <?php $this->featureRow((string) $i, (string) ($row['name'] ?? ''), (string) ($row['category'] ?? '')); ?>
                            <?php endforeach; ?>
                        </div>
                        <template id="oy_feature_template"><?php $this->featureRow('__INDEX__', '', ''); ?></template>
                        <button type="button" class="oy-btn oy-btn-ghost mt-3" id="oy_feature_add"><?php esc_html_e('Add feature', 'openyacht'); ?></button>
                    </section>
                    <hr class="oy-rule">

                    <section id="oy-media" class="scroll-mt-16 p-6">
                        <h2 class="oy-section-h"><?php esc_html_e('Media', 'openyacht'); ?></h2>
                        <p class="oy-section-sub mt-1"><?php esc_html_e('The profile image is the hero every partner must use. A listing with imagery needs one; with none, leave it empty — never a placeholder.', 'openyacht'); ?></p>
                        <div class="mt-5 grid grid-cols-12 gap-x-6 gap-y-6">
                            <div class="col-span-4 max-[900px]:col-span-12">
                                <span class="oy-label"><?php esc_html_e('Profile image', 'openyacht'); ?></span>
                                <input type="hidden" name="oy[profile_id]" id="oy_profile_id" value="<?php echo esc_attr($profileId); ?>">
                                <div id="oy_profile_preview" class="mb-2.5 flex flex-wrap gap-1.5"><span class="oy-media-empty w-full"><?php esc_html_e('No profile image', 'openyacht'); ?></span></div>
                                <div class="flex gap-2">
                                    <button type="button" class="oy-btn oy-btn-ghost" id="oy_profile_pick"><?php esc_html_e('Choose', 'openyacht'); ?></button>
                                    <button type="button" class="oy-btn oy-btn-ghost" id="oy_profile_clear"><?php esc_html_e('Remove', 'openyacht'); ?></button>
                                </div>
                            </div>
                            <div class="col-span-8 max-[900px]:col-span-12">
                                <span class="oy-label"><?php esc_html_e('Gallery', 'openyacht'); ?></span>
                                <input type="hidden" name="oy[gallery_ids]" id="oy_gallery_ids" value="<?php echo esc_attr($galleryCsv); ?>">
                                <div id="oy_gallery_preview" class="mb-2.5 flex flex-wrap gap-1.5"><span class="oy-media-empty w-full"><?php esc_html_e('No gallery images', 'openyacht'); ?></span></div>
                                <div class="flex gap-2">
                                    <button type="button" class="oy-btn oy-btn-ghost" id="oy_gallery_pick"><?php esc_html_e('Choose images', 'openyacht'); ?></button>
                                    <button type="button" class="oy-btn oy-btn-ghost" id="oy_gallery_clear"><?php esc_html_e('Clear', 'openyacht'); ?></button>
                                </div>
                            </div>
                        </div>
                    </section>
                    <hr class="oy-rule">

                    <section id="oy-sharing" class="scroll-mt-16 p-6">
                        <h2 class="oy-section-h"><?php esc_html_e('Sharing', 'openyacht'); ?></h2>
                        <p class="oy-section-sub mt-1"><?php esc_html_e('Unsharing sends affected partners a tombstone indistinguishable from a withdrawal; re-sharing surfaces the listing again on their next poll.', 'openyacht'); ?></p>
                        <fieldset class="mt-5 flex flex-col gap-3">
                            <legend class="screen-reader-text"><?php esc_html_e('Audience', 'openyacht'); ?></legend>
                            <label class="oy-check"><input type="radio" name="oy[audience]" value="everyone" <?php checked($currentAudience, 'everyone'); ?>><span><?php esc_html_e('Everyone — all verified partners receive this listing', 'openyacht'); ?></span></label>
                            <label class="oy-check"><input type="radio" name="oy[audience]" value="selected" <?php checked($currentAudience, 'selected'); ?>><span><?php esc_html_e('Selected partners only', 'openyacht'); ?></span></label>
                            <div class="ml-6 flex flex-col gap-1.5 transition-opacity" data-oy-audience-partners>
                                <?php foreach (Services::partners()->all() as $partner) : ?>
                                    <label class="oy-check"><input type="checkbox" name="oy[audience_partners][]" value="<?php echo (int) $partner->id; ?>" <?php checked(in_array($partner->id, $selectedIds, true)); ?>><span><?php echo esc_html($partner->domain); ?></span></label>
                                <?php endforeach; ?>
                            </div>
                            <label class="oy-check"><input type="radio" name="oy[audience]" value="none" <?php checked($currentAudience, 'none'); ?>><span><?php esc_html_e('No one — displayed locally, never shared', 'openyacht'); ?></span></label>
                        </fieldset>
                    </section>

                    <div class="oy-savebar-mobile flex items-center justify-end gap-3 border-t border-(--color-line) p-4 rounded-b-lg min-[901px]:hidden">
                        <button type="submit" class="oy-btn oy-btn-primary w-full">
                            <?php echo esc_html($listing === null ? __('Create draft', 'openyacht') : __('Save changes', 'openyacht')); ?>
                        </button>
                    </div>
                </div>
            </div>
        </form>
        </div>
        <?php
    }

    private function builderCombobox(string $currentSlug): void
    {
        $options = [['value' => '', 'label' => __('— Unlisted builder —', 'openyacht'), 'hint' => '']];

        foreach ((new BuilderRegistry())->all() as $builder) {
            $options[] = [
                'value' => $builder['slug'],
                'label' => $builder['name'],
                'hint' => (string) ($builder['country'] ?? ''),
            ];
        }

        $this->combobox('oy[builder_slug]', 'oy_builder', $currentSlug, $options, __('Search builders…', 'openyacht'), __('Builders', 'openyacht'));
    }

    /**
     * One repeatable description block: a section label + restricted rich
     * text. Template rows carry the literal __INDEX__ placeholder and a
     * plain textarea; editor.js initialises TinyMCE on insertion.
     */
    private function descriptionBlock(string $index, string $section, string $content, bool $isTemplate = false): void
    {
        ?>
        <div class="rounded-md border border-(--color-line) p-4" data-oy-block>
            <div class="mb-3 flex items-center gap-2">
                <label class="oy-label !mb-0" for="oy_desc_section_<?php echo esc_attr($index); ?>"><?php esc_html_e('Section', 'openyacht'); ?></label>
                <input class="oy-input max-w-52" style="width:auto;" list="oy_desc_sections" name="oy[descriptions][<?php echo esc_attr($index); ?>][section]" id="oy_desc_section_<?php echo esc_attr($index); ?>" value="<?php echo esc_attr($section); ?>" placeholder="overview">
                <button type="button" class="oy-row-x ml-auto" data-oy-remove-block aria-label="<?php esc_attr_e('Remove this content block', 'openyacht'); ?>">&times;</button>
            </div>
            <?php
            if (! $isTemplate && function_exists('wp_editor')) {
                wp_editor($content, 'oy_desc_' . $index, [
                    'textarea_name' => 'oy[descriptions][' . $index . '][content]',
                    'textarea_rows' => 8,
                    'media_buttons' => false,
                    'quicktags' => ['buttons' => 'strong,em,ul,ol,li,link'],
                    'tinymce' => [
                        'toolbar1' => 'formatselect,bold,italic,bullist,numlist,link,unlink,removeformat,undo,redo',
                        'toolbar2' => '',
                        'block_formats' => 'Paragraph=p;Heading 3=h3;Heading 4=h4',
                        'valid_elements' => 'p,br,ul,ol,li,strong/b,em/i,h3,h4,a[href|rel|target]',
                    ],
                ]);
            } else {
                echo '<textarea class="oy-textarea" name="oy[descriptions][' . esc_attr($index) . '][content]" id="oy_desc_' . esc_attr($index) . '" rows="8">' . esc_textarea($content) . '</textarea>';
            }
        ?>
        </div>
        <?php
    }

    private function featureRow(string $index, string $name, string $category): void
    {
        ?>
        <div class="flex items-center gap-2" data-oy-block>
            <input class="oy-input" name="oy[features][<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($name); ?>" placeholder="<?php esc_attr_e('Feature — e.g. Air conditioning', 'openyacht'); ?>" aria-label="<?php esc_attr_e('Feature name', 'openyacht'); ?>">
            <input class="oy-input max-w-44" name="oy[features][<?php echo esc_attr($index); ?>][category]" value="<?php echo esc_attr($category); ?>" placeholder="<?php esc_attr_e('Category (optional)', 'openyacht'); ?>" aria-label="<?php esc_attr_e('Feature category', 'openyacht'); ?>">
            <button type="button" class="oy-row-x" data-oy-remove-block aria-label="<?php esc_attr_e('Remove this feature', 'openyacht'); ?>">&times;</button>
        </div>
        <?php
    }

    private function countryCombobox(string $currentCode): void
    {
        $options = [['value' => '', 'label' => __('— None —', 'openyacht'), 'hint' => '']];

        foreach ((new \OpenYacht\Federation\Countries())->all() as $code => $name) {
            $options[] = ['value' => $code, 'label' => $name, 'hint' => $code];
        }

        $this->combobox('oy[location_country]', 'oy_location_country', strtoupper($currentCode), $options, __('Search countries…', 'openyacht'), __('Countries', 'openyacht'));
    }

    private function categoryCombobox(string $currentSlug): void
    {
        $options = [['value' => '', 'label' => __('— No category —', 'openyacht'), 'hint' => '']];

        foreach ((new CategoryVocabulary())->all() as $category) {
            $options[] = ['value' => $category['slug'], 'label' => $category['name'], 'hint' => ''];
        }

        $this->combobox('oy[spec][category_slug]', 'oy_category', $currentSlug, $options, __('Search categories…', 'openyacht'), __('Categories', 'openyacht'));
    }

    /**
     * @param list<array{value: string, label: string, hint: string}> $options
     */
    private function combobox(string $name, string $id, string $current, array $options, string $placeholder, string $listLabel): void
    {
        ?>
        <div class="oy-combobox" data-oy-combobox>
            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($current); ?>">
            <input type="text" class="oy-input" id="<?php echo esc_attr($id); ?>" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="<?php echo esc_attr($id); ?>_list" autocomplete="off" placeholder="<?php echo esc_attr($placeholder); ?>" data-oy-combobox-input>
            <ul class="oy-combobox-list" id="<?php echo esc_attr($id); ?>_list" role="listbox" aria-label="<?php echo esc_attr($listLabel); ?>" data-empty-text="<?php esc_attr_e('No matches', 'openyacht'); ?>" hidden></ul>
            <script type="application/json" data-oy-combobox-options><?php echo wp_json_encode($options, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?></script>
        </div>
        <?php
    }

    private function gatedChip(string $label): void
    {
        ?>
        <span class="oy-chip oy-chip-gated" title="<?php esc_attr_e('Shared only with partners granted this field group; withheld values are nulled server-side.', 'openyacht'); ?>">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><circle cx="5.5" cy="10.5" r="2.75"/><path d="M7.6 8.4 13 3m-2.5 2.5 2 2"/></svg>
            <?php echo esc_html($label); ?>
        </span>
        <?php
    }

    /**
     * @param array<string, mixed>|null $oldInput
     * @return array{0: string, 1: string}
     */
    private function mediaState(?Listing $listing, ?array $oldInput): array
    {
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

        return [
            $oldInput !== null ? (string) ($oldInput['profile_id'] ?? '') : (string) ($profile->attachmentId ?? ''),
            $oldInput !== null ? (string) ($oldInput['gallery_ids'] ?? '') : implode(',', $galleryIds),
        ];
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

        $sanitizer = new RichTextSanitizer();
        $descriptions = [];

        foreach ((array) ($input['descriptions'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }

            $content = trim((string) ($block['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            $section = strtolower(trim(sanitize_text_field((string) ($block['section'] ?? ''))));
            $descriptions[] = [
                'section' => $section !== '' ? $section : null,
                'content' => $sanitizer->sanitize($content, [NodeConfig::identityDomain()]),
            ];
        }

        $features = [];

        foreach ((array) ($input['features'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim(sanitize_text_field((string) ($row['name'] ?? '')));

            if ($name === '') {
                continue;
            }

            $category = strtolower(trim(sanitize_text_field((string) ($row['category'] ?? ''))));
            $features[] = ['category' => $category !== '' ? $category : null, 'name' => $name, 'slug' => null];
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
