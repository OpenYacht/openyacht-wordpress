---
name: OpenYacht Admin
description: Quiet nautical — the calm specification-sheet world of the plugin's owned admin surfaces
colors:
  ink: "#142b40"
  ink-soft: "#33506a"
  slate: "#5d7387"
  mist: "#8fa4b5"
  fog: "#eef2f5"
  foam: "#f7fafc"
  line: "#d7e0e8"
  line-strong: "#b9c9d6"
  card: "#ffffff"
  brass: "#a57b2a"
  brass-soft: "#f3ead6"
  tide: "#0f5132"
  tide-soft: "#e2f0e8"
  alert: "#8c2f39"
  alert-soft: "#f6e4e6"
typography:
  title:
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
    fontSize: "22px"
    fontWeight: 650
    lineHeight: 1.2
    letterSpacing: "-0.01em"
  headline:
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
    fontSize: "15px"
    fontWeight: 650
    letterSpacing: "-0.005em"
  body:
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
    fontSize: "13px"
    lineHeight: 1.5
  label:
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
    fontSize: "12.5px"
    fontWeight: 550
  help:
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
    fontSize: "12px"
rounded:
  sm: "5px"
  md: "6px"
  lg: "8px"
  pill: "999px"
spacing:
  xs: "5px"
  sm: "8px"
  md: "16px"
  lg: "24px"
components:
  button-primary:
    backgroundColor: "{colors.ink}"
    textColor: "#ffffff"
    rounded: "{rounded.md}"
    padding: "8px 16px"
  button-primary-hover:
    backgroundColor: "#0d1f30"
  button-ghost:
    backgroundColor: "{colors.card}"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
  input:
    backgroundColor: "{colors.card}"
    textColor: "{colors.ink}"
    rounded: "{rounded.sm}"
    padding: "7px 10px"
  sheet:
    backgroundColor: "{colors.card}"
    rounded: "{rounded.lg}"
---

# Design System: OpenYacht Admin

## Overview

**Creative North Star: "The Vessel's Specification Sheet"**

The listing editor is a vessel's specification sheet, not a settings page: one calm document the broker completes, with a rail that always knows where you are. It deliberately refuses the wp-admin form-table monolith — the plugin's owned surfaces scope everything under `#openyacht-editor` and keep their own world, while familiar chrome (partner lists, settings, synced-listings tables) stays native wp-admin so the site feels like WordPress everywhere WordPress idioms already work.

The world is "quiet nautical" (user-pinned, 2026-08-23): a fog ground the white sheet floats on, deep navy ink for what matters, slate-tinted secondaries for what supports, and one brass accent spent only where position or obligation must register — the rail's current-section dot and required marks. Nothing shouts; hierarchy is carried by weight, ink density, and hairline navy-tinted rules rather than boxes and background stripes. Density is working-tool density: 13px body, 34–36px controls, comfortable but not airy.

**Key Characteristics:**
- One calm white sheet on a fog ground; sections divided by hairline rules, never nested boxes
- Deep navy ink hierarchy with slate secondaries; color states (tide/alert/brass) appear only when they mean something
- A single brass accent, spent sparingly — its rarity is the point
- System font stack inside wp-admin — deliberately no webfonts in admin
- Everything scoped under `#openyacht-editor`; wp-admin keeps its own world outside it

## Colors

A navy-and-fog sea palette where the only warm note is brass, and status colors are muted, almost archival.

### Primary
- **Ink** (#142b40): the deep navy that carries every heading, control border on focus, primary button, selection background, and caret. It is the voice of the sheet.
- **Ink Soft** (#33506a): default body text inside the editor — softened so full-strength Ink reads as emphasis.

### Secondary
- **Brass** (#a57b2a): the one accent. Current-section dot in the rail, required marks, the vocabulary-link chip. Never used for buttons or large fills; **Brass Soft** (#f3ead6) is its quiet fill when a chip needs ground.

### Tertiary
- **Tide** (#0f5132) on **Tide Soft** (#e2f0e8): the "active/positive" state pair (status chips).
- **Alert** (#8c2f39) on **Alert Soft** (#f6e4e6): errors, destructive hovers, invalid fields. A muted oxblood, not a fire-engine red.

### Neutral
- **Slate** (#5d7387): secondary text — help lines, section subtitles, placeholders, idle rail links.
- **Mist** (#8fa4b5): hover-state borders; the step between resting and focused.
- **Fog** (#eef2f5): the page ground behind the sheet, and hover fills.
- **Foam** (#f7fafc): the lightest fill (ghost-button hover, selection text).
- **Line** (#d7e0e8) / **Line Strong** (#b9c9d6): hairline rules and control borders, both navy-tinted so even the grey belongs to the sea.
- **Card** (#ffffff): the sheet and every control surface.

### Named Rules
**The One Brass Rule.** Brass appears only where position or obligation must register — the rail dot, required marks, the slug chip. If a surface has brass in more than two places, something is misusing it.

**The Meaningful Color Rule.** Tide, Alert, and Brass are states, not decoration. A screen with no active status, no error, and no current-section simply has no color beyond navy and fog — and that is correct.

## Typography

**Display/Body Font:** system stack (`-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, …`) — deliberately no webfonts inside wp-admin.

**Character:** quietly confident sans at working-tool sizes; hierarchy comes from weight (550–650) and ink density, never from size jumps. Slight negative letter-spacing on headings keeps them set, not stretched.

### Hierarchy
- **Title** (650, 22px, 1.2, -0.01em): the vessel name in the rail and mobile bar. Balanced wrapping (`text-wrap: balance`).
- **Headline** (650, 15px, -0.005em): section headings on the sheet (`.oy-section-h`).
- **Body** (400, 13px, 1.5): everything the broker reads and types, in Ink Soft.
- **Label** (550, 12.5px): field labels (`.oy-label`), in full Ink.
- **Help** (400, 12–12.5px): help lines and section subtitles, in Slate, max-width 60ch.
- Numbers in spec fields use tabular figures (`.oy-num`).

### Named Rules
**The Two-Weight Rule.** Regular for reading, 550–650 for structure. No light weights, no black weights, no italics for hierarchy.

## Layout

A sticky rail + sheet split: a 224px rail (vessel name, status chip, section links, save) beside a single scrolling sheet of sections divided by hairline rules. Sections are anchor targets (`scroll-mt-16`) and the rail's scroll-spy moves the brass dot. Inside sections, a 12-column grid (`gap-x-6 gap-y-7`) with fields spanning 3/4/6/12 columns by content width. Below 900px the rail hides; a sticky top mobile bar (name + status) and a sticky bottom save bar take over, and multi-column fields collapse to full width. The base spacing rhythm is 4px-derived (5/8/16/24px in practice); control heights sit at 34–36px.

## Elevation & Depth

Flat-by-default with two ambient shadows. The sheet itself carries `--shadow-sheet` (0 1px 2px + 8px 24px navy-tinted, very low alpha) — presence, not lift. Popovers (combobox lists) use `--shadow-pop` for genuine float. Inputs use a 1px inset wash (rgb(20 43 64 / 0.04)) to read as engraved rather than raised. Depth is otherwise conveyed by border tone (Line → Line Strong → Mist → Ink) as interaction approaches.

### Shadow Vocabulary
- **Sheet** (`0 1px 2px rgb(20 43 64 / 0.06), 0 8px 24px -12px rgb(20 43 64 / 0.18)`): the document resting on the fog.
- **Pop** (`0 4px 10px -2px rgb(20 43 64 / 0.12), 0 12px 32px -8px rgb(20 43 64 / 0.22)`): floating listboxes and pickers only.

### Named Rules
**The Engraved-Not-Raised Rule.** Controls never cast shadows at rest; inputs recess (inset wash), the sheet rests, and only transient popovers float.

## Shapes

Soft-cornered pragmatism: 5px on inputs and rail links, 6px on buttons and media cards, 8px on the sheet, full-round (999px) only on chips and the map pin. Borders are always 1px hairlines in navy-tinted greys; nothing uses 2px+ borders except the dashed 1.5px empty-state boxes (`.oy-media-empty`), whose dash is the "nothing here yet" signature. No clipped corners, no asymmetric radii.

## Components

### Buttons
- **Shape:** softly rounded (6px), 36px min-height, 8px 16px padding, 570 weight at 13px.
- **Primary:** Ink fill, white text, faint drop shadow; hover deepens to #0d1f30; active presses with an inset shadow.
- **Ghost:** white fill, Line Strong border, Ink text; hover raises border to Mist over Foam. The workhorse — Add buttons, pickers, back links.
- All transitions 120ms ease-out.

### Inputs / Fields
- **Style:** white fill, 1px Line Strong border, 5px radius, inset wash, 34px min-height.
- **Hover:** border to Mist. **Focus:** border to Ink + 3px soft navy ring (`rgb(20 43 64 / 0.14)`), no default outline.
- **Invalid:** `aria-invalid` turns the border Alert.
- Global `:focus-visible` fallback: 2px Ink outline, 2px offset.

### Searchable Combobox (signature)
The registry-backed select used for builders, countries, feature slugs, and partner search: a text input that opens a Pop-shadowed listbox with label + slate hint per option, committed-option highlighting (never silently index 0), and full ARIA combobox wiring. Free text never survives blur — blur restores the committed label. Repeated instances share one options JSON tag by id.

### Chips
- **Style:** pill (999px), 11px at 550 weight, 1px border.
- **Status:** Fog/Ink-Soft resting; `is-active` flips to Tide on Tide Soft.
- **Gated:** Fog with a key glyph — "trusted partners only" affordance.
- **Slug chip:** Brass on brass-tinted fill, monospace — the vocabulary link.

### Cards / Containers
- **The sheet:** white, 1px Line border, 8px radius, Sheet shadow. There is exactly one per screen; sections divide it with `.oy-rule` hairlines, never nested cards.
- **Media item cards:** 6px radius, Line border, drag handle (six-dot glyph, grab cursor), 44×33 thumb, dashed drop-target empty states. Dragging dims to 45% with a dashed border.

### Navigation (the rail)
Sticky left rail: 13px links in Slate with a 5px dot; hover fills Fog and inks the text; the current section's dot turns Brass and scales 1.35× (160ms spring-ish cubic-bezier). Below 900px the rail is replaced by the sticky mobile bars.

### Repeatable Rows
Description blocks, features, link rows: flex rows with a trailing `.oy-row-x` remove button (30px square ghost, Alert on hover). Templates clone with `__INDEX__` substitution; DOM order is submission order everywhere ordering matters.

## Do's and Don'ts

### Do:
- **Do** scope every custom style under `#openyacht-editor` — wp-admin must keep its own world outside it.
- **Do** use native WP admin styles for lists, tables, and settings; this world is only for owned surfaces (the editor, future browsing).
- **Do** carry hierarchy with weight and ink density (Ink vs Ink Soft vs Slate) before reaching for size or color.
- **Do** keep `box-sizing: border-box` on any control that must match another's height — the back-link/button mismatch was a real shipped bug.
- **Do** give every interactive state a 120ms ease-out transition; the world is calm, not static.

### Don't:
- **Don't** introduce webfonts, icon fonts, or heavier shadows into admin — system stack and the two shadow tokens are the ceiling.
- **Don't** spend Brass on decoration, buttons, or fills; it marks position and obligation only.
- **Don't** nest cards inside the sheet or draw boxes around sections — hairline rules divide, boxes never do.
- **Don't** let a combobox commit free text or highlight index 0 by default — committed-option semantics are load-bearing (silent clears were a shipped bug).
- **Don't** use pure greys; every neutral is navy-tinted, and a `#ccc` would read as a foreign object.
