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

        $profileId = $this->profileAttachmentId($listing, $oldInput);
        $currentAudience = $oldInput !== null
            ? (string) ($oldInput['audience'] ?? 'everyone')
            : ($listing?->audience->value ?? 'everyone');
        $selectedIds = $oldInput !== null
            ? array_map('intval', (array) ($oldInput['audience_partners'] ?? []))
            : ($listing !== null ? Services::audience()->partnersForListing($listing->id) : []);
        $selectedGroupIds = $oldInput !== null
            ? array_map('intval', (array) ($oldInput['audience_groups'] ?? []))
            : ($listing !== null ? Services::partnerGroups()->groupIdsForListing($listing->id) : []);

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
                        <p class="oy-section-sub mt-1"><?php esc_html_e('Pick the closest vocabulary entry — it fills the name and category, and partners filter on it. The name and category stay yours to reword; keep names singular (Seabob, not 2x Seabob) and put counts in the quantity box, where empty means present, count unstated. Nothing fits? Set No link and type freely.', 'openyacht'); ?></p>
                        <script type="application/json" id="oy_feature_slug_options"><?php
                            $featureOptions = [['value' => '', 'label' => __('— No link —', 'openyacht'), 'hint' => '']];

        foreach ((new \OpenYacht\Federation\FeatureRegistry())->all() as $known) {
            $featureOptions[] = ['value' => $known['slug'], 'label' => $known['name'], 'hint' => (string) $known['category']];
        }

        echo wp_json_encode($featureOptions, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        ?></script>
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
                                <?php $this->featureRow((string) $i, (string) ($row['name'] ?? ''), (string) ($row['category'] ?? ''), isset($row['quantity']) && is_numeric($row['quantity']) ? (string) (int) $row['quantity'] : '', (string) ($row['slug'] ?? '')); ?>
                            <?php endforeach; ?>
                        </div>
                        <template id="oy_feature_template"><?php $this->featureRow('__INDEX__', '', '', '', ''); ?></template>
                        <button type="button" class="oy-btn oy-btn-ghost mt-3" id="oy_feature_add"><?php esc_html_e('Add feature', 'openyacht'); ?></button>
                    </section>
                    <hr class="oy-rule">

                    <section id="oy-media" class="scroll-mt-16 p-6">
                        <h2 class="oy-section-h"><?php esc_html_e('Media', 'openyacht'); ?></h2>
                        <p class="oy-section-sub mt-1"><?php esc_html_e('The profile image is the hero every partner must use. A listing with imagery needs one; with none, leave it empty — never a placeholder. Drag items by the handle to reorder; alt text is edited inline, captions in the media dialog.', 'openyacht'); ?></p>
                        <div class="mt-5 grid grid-cols-12 gap-x-6 gap-y-7">
                            <div class="col-span-12">
                                <span class="oy-label"><?php esc_html_e('Profile image', 'openyacht'); ?></span>
                                <input type="hidden" name="oy[profile_id]" id="oy_profile_id" value="<?php echo esc_attr($profileId); ?>">
                                <div id="oy_profile_preview" class="mb-2.5 flex flex-wrap gap-1.5"><span class="oy-media-empty" style="min-width:220px;"><?php esc_html_e('No profile image', 'openyacht'); ?></span></div>
                                <div class="flex gap-2">
                                    <button type="button" class="oy-btn oy-btn-ghost" id="oy_profile_pick"><?php esc_html_e('Choose', 'openyacht'); ?></button>
                                    <button type="button" class="oy-btn oy-btn-ghost" id="oy_profile_clear"><?php esc_html_e('Remove', 'openyacht'); ?></button>
                                </div>
                            </div>

                            <?php
                            $this->galleryBoxes($listing, $oldInput);
        ?>

                            <div class="col-span-12">
                                <?php $this->mediaListBox('layouts', 'layouts', null, __('Layouts & deck plans', 'openyacht'), __('Add layout images', 'openyacht'), 'image', $this->itemsForKind('layout', 'layouts', $listing, $oldInput), false, __('GA and deck plans as images; plan PDFs belong in documents.', 'openyacht')); ?>
                            </div>
                            <div class="col-span-12">
                                <?php $this->mediaListBox('documents', 'documents', null, __('Documents', 'openyacht'), __('Add documents', 'openyacht'), 'application/pdf', $this->itemsForKind('document', 'documents', $listing, $oldInput), true, __('Brochures and plan PDFs. Shared only with partners granted the documents field group.', 'openyacht')); ?>
                            </div>
        <?php ?>

                            <div class="col-span-6 max-[900px]:col-span-12">
                                <span class="oy-label"><?php esc_html_e('Video links', 'openyacht'); ?></span>
                                <?php $this->linkRows('videos', $listing, $oldInput); ?>
                                <button type="button" class="oy-btn oy-btn-ghost mt-2" data-oy-link-add="videos"><?php esc_html_e('Add video link', 'openyacht'); ?></button>
                            </div>
                            <div class="col-span-6 max-[900px]:col-span-12">
                                <span class="oy-label"><?php esc_html_e('Virtual tours & walkthroughs', 'openyacht'); ?></span>
                                <?php $this->linkRows('tours', $listing, $oldInput); ?>
                                <button type="button" class="oy-btn oy-btn-ghost mt-2" data-oy-link-add="tours"><?php esc_html_e('Add tour link', 'openyacht'); ?></button>
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
                                <?php $this->audienceSelection($selectedIds, $selectedGroupIds); ?>
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

    private const GALLERY_CATEGORIES = ['exterior', 'interior', 'lifestyle', 'crew'];

    /**
     * The gallery as one box per wire category (plus uncategorised): the
     * box an image sits in IS its category, ordering is local to each box,
     * and dragging between boxes recategorises. The wire's single gallery
     * array is the boxes concatenated in this fixed order.
     *
     * @param array<string, mixed>|null $oldInput
     */
    private function galleryBoxes(?Listing $listing, ?array $oldInput): void
    {
        $grouped = array_fill_keys([...self::GALLERY_CATEGORIES, ''], []);

        foreach ($this->itemsForKind('gallery', 'gallery', $listing, $oldInput) as $item) {
            $grouped[array_key_exists($item['category'], $grouped) ? $item['category'] : ''][] = $item;
        }

        $labels = [
            'exterior' => __('Exterior', 'openyacht'),
            'interior' => __('Interior', 'openyacht'),
            'lifestyle' => __('Lifestyle', 'openyacht'),
            'crew' => __('Crew', 'openyacht'),
            '' => __('Uncategorised', 'openyacht'),
        ];

        ?>
        <div class="col-span-12">
            <span class="oy-label"><?php esc_html_e('Gallery', 'openyacht'); ?></span>
            <p class="oy-help !mt-0 mb-2.5"><?php esc_html_e('Each box is a wire category — drag between boxes to recategorise; order within a box is the order partners receive.', 'openyacht'); ?></p>
            <div class="grid grid-cols-12 gap-x-6 gap-y-5">
                <?php foreach ($labels as $category => $label) : ?>
                    <div class="<?php echo $category === '' ? 'col-span-12' : 'col-span-6 max-[900px]:col-span-12'; ?>">
                        <?php $this->mediaListBox('gallery', 'gallery-' . ($category === '' ? 'none' : $category), (string) $category, $label, __('Add images', 'openyacht'), 'image', $grouped[$category], false); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * One orderable media box: existing items render server-side with
     * their attachment thumbs; the JS layer adds picker items,
     * drag-reorders (submission order = DOM order), and removes.
     *
     * @param list<array{id: int, category: string, alt: string}> $items
     */
    private function mediaListBox(string $inputName, string $listKey, ?string $category, string $label, string $addLabel, string $mediaType, array $items, bool $isDocument, ?string $help = null): void
    {
        ?>
        <span class="oy-label"><?php echo esc_html($label); ?></span>
        <?php if ($help !== null) : ?>
            <p class="oy-help !mt-0 mb-1"><?php echo esc_html($help); ?></p>
        <?php endif; ?>
        <div class="mt-1.5 flex flex-col gap-1.5" data-oy-media-list="<?php echo esc_attr($listKey); ?>" data-input-name="<?php echo esc_attr($inputName); ?>" data-oy-drag-group="<?php echo esc_attr($inputName); ?>" <?php echo $category !== null ? 'data-category="' . esc_attr($category) . '"' : ''; ?> <?php echo $isDocument ? '' : 'data-with-alt="1"'; ?>>
            <?php foreach ($items as $i => $item) : ?>
                <?php $this->mediaItemCard($inputName, $listKey . '-' . $i, $item['id'], $category !== null ? $item['category'] : null, $isDocument, $item['alt']); ?>
            <?php endforeach; ?>
            <span class="oy-media-empty" data-oy-media-placeholder <?php echo $items === [] ? '' : 'style="display:none"'; ?>><?php esc_html_e('Nothing added yet', 'openyacht'); ?></span>
        </div>
        <button type="button" class="oy-btn oy-btn-ghost mt-2" data-oy-media-add="<?php echo esc_attr($listKey); ?>" data-media-type="<?php echo esc_attr($mediaType); ?>"><?php echo esc_html($addLabel); ?></button>
        <?php
    }

    /**
     * @param array<string, mixed>|null $oldInput
     * @return list<array{id: int, category: string, alt: string}>
     */
    private function itemsForKind(string $kind, string $inputName, ?Listing $listing, ?array $oldInput): array
    {
        $items = [];

        if ($oldInput !== null) {
            foreach ((array) ($oldInput[$inputName] ?? []) as $row) {
                if (is_array($row) && is_numeric($row['id'] ?? null)) {
                    $items[] = ['id' => (int) $row['id'], 'category' => (string) ($row['category'] ?? ''), 'alt' => (string) ($row['alt'] ?? '')];
                }
            }
        } elseif ($listing !== null) {
            foreach (Services::listingMedia()->forListing($listing->id) as $media) {
                if ($media->kind === $kind && $media->attachmentId !== null) {
                    $items[] = [
                        'id' => $media->attachmentId,
                        'category' => (string) ($media->category ?? ''),
                        'alt' => (string) get_post_meta($media->attachmentId, '_wp_attachment_image_alt', true),
                    ];
                }
            }
        }

        return $items;
    }

    private function mediaItemCard(string $inputName, string $index, int $attachmentId, ?string $category, bool $isDocument, string $alt = ''): void
    {
        $thumb = $isDocument ? null : wp_get_attachment_image_url($attachmentId, 'thumbnail');
        $title = get_the_title($attachmentId);

        ?>
        <div class="oy-media-item" data-oy-media-item>
            <span class="oy-drag" data-oy-drag-handle title="<?php esc_attr_e('Drag to reorder', 'openyacht'); ?>" aria-hidden="true">
                <svg viewBox="0 0 8 14" width="8" height="14" fill="currentColor"><circle cx="2" cy="2" r="1.3"/><circle cx="6" cy="2" r="1.3"/><circle cx="2" cy="7" r="1.3"/><circle cx="6" cy="7" r="1.3"/><circle cx="2" cy="12" r="1.3"/><circle cx="6" cy="12" r="1.3"/></svg>
            </span>
            <?php if (is_string($thumb)) : ?>
                <img class="oy-thumb-sm" src="<?php echo esc_url($thumb); ?>" alt="">
            <?php else : ?>
                <span class="dashicons dashicons-media-document" style="font-size:26px;width:44px;height:33px;color:var(--color-slate);display:flex;align-items:center;justify-content:center;"></span>
            <?php endif; ?>
            <span class="oy-media-body">
                <span class="oy-media-name"><?php echo esc_html($title !== '' ? $title : '#' . $attachmentId); ?></span>
                <?php if (! $isDocument) : ?>
                    <input class="oy-media-alt" name="oy[<?php echo esc_attr($inputName); ?>][<?php echo esc_attr($index); ?>][alt]" value="<?php echo esc_attr($alt); ?>" placeholder="<?php esc_attr_e('Alt text', 'openyacht'); ?>" aria-label="<?php esc_attr_e('Image alt text', 'openyacht'); ?>">
                <?php endif; ?>
            </span>
            <?php if ($category !== null) : ?>
                <input type="hidden" data-oy-category-input name="oy[<?php echo esc_attr($inputName); ?>][<?php echo esc_attr($index); ?>][category]" value="<?php echo esc_attr($category); ?>">
            <?php endif; ?>
            <input type="hidden" name="oy[<?php echo esc_attr($inputName); ?>][<?php echo esc_attr($index); ?>][id]" value="<?php echo (int) $attachmentId; ?>">
            <button type="button" class="oy-row-x" data-oy-media-remove aria-label="<?php esc_attr_e('Remove', 'openyacht'); ?>">&times;</button>
        </div>
        <?php
    }

    /**
     * External link rows (videos, tours): https URL + caption.
     *
     * @param array<string, mixed>|null $oldInput
     */
    private function linkRows(string $key, ?Listing $listing, ?array $oldInput): void
    {
        $rowKind = $key === 'videos' ? 'video' : 'tour';
        $rows = [];

        if ($oldInput !== null) {
            foreach ((array) ($oldInput[$key] ?? []) as $row) {
                if (is_array($row)) {
                    $rows[] = ['url' => (string) ($row['url'] ?? ''), 'caption' => (string) ($row['caption'] ?? '')];
                }
            }
        } elseif ($listing !== null) {
            foreach (Services::listingMedia()->forListing($listing->id) as $media) {
                if ($media->kind === $rowKind) {
                    $rows[] = ['url' => (string) $media->url, 'caption' => (string) ($media->caption ?? '')];
                }
            }
        }

        if ($rows === []) {
            $rows = [['url' => '', 'caption' => '']];
        }

        echo '<div class="flex flex-col gap-2" data-oy-link-rows="' . esc_attr($key) . '">';

        foreach ($rows as $i => $row) {
            $this->linkRow($key, (string) $i, $row['url'], $row['caption']);
        }

        echo '</div>';
        echo '<template data-oy-link-template="' . esc_attr($key) . '">';
        $this->linkRow($key, '__INDEX__', '', '');
        echo '</template>';
    }

    private function linkRow(string $key, string $index, string $url, string $caption): void
    {
        ?>
        <div class="flex items-center gap-2" data-oy-block>
            <input class="oy-input" type="url" name="oy[<?php echo esc_attr($key); ?>][<?php echo esc_attr($index); ?>][url]" value="<?php echo esc_attr($url); ?>" placeholder="https://…" aria-label="<?php esc_attr_e('Link URL', 'openyacht'); ?>">
            <input class="oy-input max-w-44" name="oy[<?php echo esc_attr($key); ?>][<?php echo esc_attr($index); ?>][caption]" value="<?php echo esc_attr($caption); ?>" placeholder="<?php esc_attr_e('Caption (optional)', 'openyacht'); ?>" aria-label="<?php esc_attr_e('Link caption', 'openyacht'); ?>">
            <button type="button" class="oy-row-x" data-oy-remove-block aria-label="<?php esc_attr_e('Remove link', 'openyacht'); ?>">&times;</button>
        </div>
        <?php
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

    /**
     * The slug select drives the row: picking a vocabulary entry autofills
     * the name and category, which stay editable — the name is display
     * truth, the slug is the identity partners filter on. "No link" sends
     * the free text with slug null. The server accepts only registry-known
     * slugs either way.
     */
    private function featureRow(string $index, string $name, string $category, string $quantity = '', string $slug = ''): void
    {
        ?>
        <div class="flex items-center gap-2" data-oy-block>
            <div class="oy-combobox w-56 shrink-0 max-[900px]:w-40" data-oy-combobox data-oy-combobox-source="oy_feature_slug_options" data-oy-feature-slug>
                <input type="hidden" name="oy[features][<?php echo esc_attr($index); ?>][slug]" value="<?php echo esc_attr($slug); ?>">
                <input type="text" class="oy-input" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="oy_feature_list_<?php echo esc_attr($index); ?>" autocomplete="off" placeholder="<?php esc_attr_e('Vocabulary…', 'openyacht'); ?>" aria-label="<?php esc_attr_e('Shared vocabulary entry', 'openyacht'); ?>" data-oy-combobox-input>
                <ul class="oy-combobox-list" id="oy_feature_list_<?php echo esc_attr($index); ?>" role="listbox" aria-label="<?php echo esc_attr__('Feature vocabulary', 'openyacht'); ?>" data-empty-text="<?php esc_attr_e('No match — pick No link and use free text', 'openyacht'); ?>" hidden></ul>
            </div>
            <input class="oy-input flex-1 min-w-0" name="oy[features][<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($name); ?>" placeholder="<?php esc_attr_e('Display name — e.g. Air Conditioning', 'openyacht'); ?>" aria-label="<?php esc_attr_e('Feature name', 'openyacht'); ?>" data-oy-feature-name>
            <input class="oy-input max-w-44" name="oy[features][<?php echo esc_attr($index); ?>][category]" value="<?php echo esc_attr($category); ?>" placeholder="<?php esc_attr_e('Category (optional)', 'openyacht'); ?>" aria-label="<?php esc_attr_e('Feature category', 'openyacht'); ?>" data-oy-feature-category>
            <input class="oy-input max-w-16" type="number" min="1" step="1" name="oy[features][<?php echo esc_attr($index); ?>][quantity]" value="<?php echo esc_attr($quantity); ?>" placeholder="<?php esc_attr_e('Qty', 'openyacht'); ?>" aria-label="<?php esc_attr_e('Quantity — leave empty for present, count unstated', 'openyacht'); ?>">
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

    /**
     * The selected-audience picker: group checkboxes first (groups are the
     * shorthand — a listing selects "Offices", not five domains), then
     * individual partners — plain checkboxes while the partner list is
     * short, a typeahead picker with removable rows once it outgrows one
     * screenful.
     *
     * @param list<int> $selectedIds
     * @param list<int> $selectedGroupIds
     */
    private function audienceSelection(array $selectedIds, array $selectedGroupIds): void
    {
        $groups = Services::partnerGroups()->all();
        $partners = Services::partners()->all();

        if ($groups !== []) {
            ?>
            <span class="oy-label !mb-0"><?php esc_html_e('Groups', 'openyacht'); ?></span>
            <?php foreach ($groups as $group) : ?>
                <label class="oy-check"><input type="checkbox" name="oy[audience_groups][]" value="<?php echo (int) $group->id; ?>" <?php checked(in_array($group->id, $selectedGroupIds, true)); ?>><span><?php echo esc_html($group->name); ?></span></label>
            <?php endforeach; ?>
            <span class="oy-label !mb-0 mt-2"><?php esc_html_e('Individual partners', 'openyacht'); ?></span>
            <?php
        }

        if (count($partners) <= 10) {
            foreach ($partners as $partner) {
                ?>
                <label class="oy-check"><input type="checkbox" name="oy[audience_partners][]" value="<?php echo (int) $partner->id; ?>" <?php checked(in_array($partner->id, $selectedIds, true)); ?>><span><?php echo esc_html($partner->domain); ?></span></label>
                <?php
            }

            return;
        }

        // Typeahead path: search to add, rows to review and remove.
        $byId = [];

        foreach ($partners as $partner) {
            $byId[$partner->id] = $partner->domain;
        }

        ?>
        <div class="flex flex-col gap-1.5" data-oy-partner-picked>
            <?php foreach ($selectedIds as $partnerId) : ?>
                <?php if (isset($byId[$partnerId])) : ?>
                    <span class="flex items-center gap-2" data-oy-partner-row>
                        <input type="hidden" name="oy[audience_partners][]" value="<?php echo (int) $partnerId; ?>">
                        <span class="oy-media-name !flex-none"><?php echo esc_html($byId[$partnerId]); ?></span>
                        <button type="button" class="oy-row-x !w-6 !h-6" data-oy-partner-remove aria-label="<?php esc_attr_e('Remove partner', 'openyacht'); ?>">&times;</button>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div class="oy-combobox max-w-xs" data-oy-combobox data-oy-partner-picker>
            <input type="hidden" value="">
            <input type="text" class="oy-input" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="oy_partner_picker_list" autocomplete="off" placeholder="<?php esc_attr_e('Add a partner…', 'openyacht'); ?>" aria-label="<?php esc_attr_e('Search partners to add', 'openyacht'); ?>" data-oy-combobox-input>
            <ul class="oy-combobox-list" id="oy_partner_picker_list" role="listbox" aria-label="<?php esc_attr_e('Partners', 'openyacht'); ?>" data-empty-text="<?php esc_attr_e('No matching partner', 'openyacht'); ?>" hidden></ul>
            <script type="application/json" data-oy-combobox-options><?php
                $options = [];

        foreach ($partners as $partner) {
            $options[] = ['value' => (string) $partner->id, 'label' => $partner->domain, 'hint' => ''];
        }

        echo wp_json_encode($options, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        ?></script>
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
     */
    private function profileAttachmentId(?Listing $listing, ?array $oldInput): string
    {
        if ($oldInput !== null) {
            return (string) ($oldInput['profile_id'] ?? '');
        }

        foreach ($listing !== null ? Services::listingMedia()->forListing($listing->id) : [] as $item) {
            if ($item->kind === 'profile' && $item->attachmentId !== null) {
                return (string) $item->attachmentId;
            }
        }

        return '';
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
        $registry = new \OpenYacht\Federation\FeatureRegistry();

        foreach ((array) ($input['features'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim(sanitize_text_field((string) ($row['name'] ?? '')));

            if ($name === '') {
                continue;
            }

            $category = strtolower(trim(sanitize_text_field((string) ($row['category'] ?? ''))));
            // The slug select is the authority: a registry-known slug
            // links, an explicit "No link" stays unlinked even when the
            // name happens to match an entry. Name matching is only the
            // fallback for input with no slug field at all. LS-11's
            // never-invent rule holds either way: only registry entries
            // produce slugs.
            $match = array_key_exists('slug', $row)
                ? $registry->matchSlug(sanitize_key((string) $row['slug']))
                : $registry->matchName($name);
            $quantity = isset($row['quantity']) && is_numeric($row['quantity']) && (int) $row['quantity'] >= 1
                ? (int) $row['quantity']
                : null;
            $features[] = [
                'category' => $category !== '' ? $category : ($match['category'] ?? null),
                'name' => $name,
                'slug' => $match['slug'] ?? null,
                'quantity' => $quantity,
            ];
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
            $rows[] = $this->attachmentRow($profileId, 'profile', 0);
        }

        // POST preserves insertion order, and the JS keeps DOM order = drag
        // order, so the array position IS the curated sort.
        foreach (['gallery' => 'gallery', 'layouts' => 'layout', 'documents' => 'document'] as $inputName => $kind) {
            $sort = 1;

            foreach (is_array($input[$inputName] ?? null) ? $input[$inputName] : [] as $item) {
                if (! is_array($item) || ! is_numeric($item['id'] ?? null)) {
                    continue;
                }

                $category = in_array($item['category'] ?? '', self::GALLERY_CATEGORIES, true) ? (string) $item['category'] : null;
                $rows[] = $this->attachmentRow((int) $item['id'], $kind, $sort++, $kind === 'gallery' ? $category : null);
            }
        }

        foreach (['videos' => 'video', 'tours' => 'tour'] as $inputName => $kind) {
            $sort = 1;

            foreach (is_array($input[$inputName] ?? null) ? $input[$inputName] : [] as $item) {
                $url = is_array($item) ? trim((string) ($item['url'] ?? '')) : '';

                if (! str_starts_with($url, 'https://') || ! wp_http_validate_url($url)) {
                    continue;
                }

                $caption = sanitize_text_field((string) ($item['caption'] ?? ''));
                $rows[] = [
                    'kind' => $kind,
                    'attachment_id' => null,
                    'url' => esc_url_raw($url),
                    'thumbnail_url' => null,
                    'sha256' => null,
                    'width' => null,
                    'height' => null,
                    'caption' => $caption !== '' ? $caption : null,
                    'category' => null,
                    'sort' => $sort++,
                ];
            }
        }

        return array_values(array_filter($rows));
    }

    /**
     * Alt text is attachment metadata, not wire data (gallery_item carries
     * only a caption) — the inline fields write it back to the media
     * library so local display and future picks both see it.
     *
     * @param array<string, mixed> $input
     */
    public function updateAttachmentAlts(array $input): void
    {
        foreach (['gallery', 'layouts'] as $inputName) {
            foreach (is_array($input[$inputName] ?? null) ? $input[$inputName] : [] as $item) {
                if (! is_array($item) || ! is_numeric($item['id'] ?? null) || ! array_key_exists('alt', $item)) {
                    continue;
                }

                $attachmentId = (int) $item['id'];
                $alt = sanitize_text_field((string) $item['alt']);

                if ($alt !== (string) get_post_meta($attachmentId, '_wp_attachment_image_alt', true)) {
                    update_post_meta($attachmentId, '_wp_attachment_image_alt', $alt);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function attachmentRow(int $attachmentId, string $kind, int $sort, ?string $category = null): ?array
    {
        $url = wp_get_attachment_url($attachmentId);
        $file = get_attached_file($attachmentId);

        if (! is_string($url) || $url === '' || ! is_string($file) || ! file_exists($file)) {
            return null;
        }

        $size = $kind === 'document' ? false : wp_getimagesize($file);
        $thumbnail = wp_get_attachment_image_url($attachmentId, 'large');
        $caption = wp_get_attachment_caption($attachmentId);

        if (($caption === '' || $caption === false) && $kind === 'document') {
            $caption = get_the_title($attachmentId);
        }

        return [
            'kind' => $kind,
            'attachment_id' => $attachmentId,
            'url' => $url,
            'thumbnail_url' => $kind === 'profile' ? (is_string($thumbnail) ? $thumbnail : $url) : null,
            'sha256' => hash_file('sha256', $file) ?: null,
            'width' => is_array($size) ? (int) $size[0] : null,
            'height' => is_array($size) ? (int) $size[1] : null,
            'caption' => is_string($caption) && $caption !== '' ? $caption : null,
            'category' => $category,
            'sort' => $sort,
        ];
    }
}
