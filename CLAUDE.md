# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

A flat collection of **standalone, self-contained HTML widgets** embedded into the SAICE PDP SharePoint intranet (`allysaice.sharepoint.com/sites/SAICEPDP`) via iframe/embed web parts. There is no build system, package manager, bundler, test suite, or CI — every file is a complete `<!DOCTYPE html>` document with inline `<style>` and `<script>` and no external JS dependencies (one file loads Google Fonts via `<link>`).

There are two distinct sets of files:

- **`saice-*.html`** — a suite of intranet widgets sharing one design system and one auto-resize mechanism (see below). Content (names, stats, dates, project counts) is static/hardcoded mock data — editing a widget means editing its HTML directly.
- **`ers-paye-calculator.html`** — an unrelated, self-contained interactive PAYE (payroll tax) calculator for the Eswatini Revenue Service. It has its own design system and does **not** follow the SAICE conventions below (no SharePoint links, no auto-resize script).

## Workflow (no build step)

There's nothing to install, build, lint, or test. To preview a widget:

```bash
python3 -m http.server 8000   # then open http://localhost:8000/<file>.html
```

Or just open the `.html` file directly in a browser. When checking a widget, resize the browser window / use device emulation to verify the responsive breakpoints, since these files are always rendered inside a narrow SharePoint content column or mobile app iframe.

## Conventions for `saice-*.html` widgets

All of these files are meant to be visually and behaviorally interchangeable, so match the existing pattern rather than introducing a new one:

- **Shared palette**: navy `#0D2B55` (headings, dark UI), teal `#0A7EA4` (primary accent/links/CTAs), orange `#E8640A` (warnings/highlights), page background `#F4F6FA`, body text `#4A5568`, muted/secondary text `#718096`, hairline borders `#E2E8F0`. Card category accents use gradient pairs (e.g. `#7B2FF7→#A855F7`, `#047857→#059669`, `#BE185D→#EC4899`) — reuse the existing gradients when adding a new category rather than inventing new colors.
- **Font stack**: `'Segoe UI', system-ui, sans-serif` (matches the Microsoft 365/SharePoint chrome it's embedded in).
- **Reset + sizing**: every file starts with `*{box-sizing:border-box;margin:0;padding:0}` and ends its `<style>` with `html,body{width:100%;max-width:100%}img,svg{max-width:100%}` to prevent the embedded iframe from ever overflowing horizontally.
- **Layout**: CSS Grid with `repeat(auto-fit,minmax(min(Npx,100%),1fr))` for card/tile grids, plus a `@media(max-width:...)` block tightening padding/gaps/font-size for phone-width iframes (breakpoints vary per file: 480/520/560/600/640px — pick whatever the existing widget uses).
- **Auto-height script**: every `saice-*.html` file ends with an identical inline IIFE (look at any existing file, e.g. `saice-stats-bar.html`, and copy it verbatim into new widgets). It:
  1. Measures `document.documentElement.scrollHeight`.
  2. If same-origin, resizes `window.frameElement` directly (works when hosted from the same SharePoint origin; no-ops elsewhere, e.g. when previewed on GitHub Pages).
  3. Otherwise `postMessage({type:'saice-widget-resize', href, height}, '*')` to the parent — this only works if the SharePoint page has a listener (SPFx application customizer or script editor), so treat it as best-effort.
  4. Re-fits on `load`/`resize`, uses `ResizeObserver` when available, else polls every 800ms.
  Do not remove or "clean up" this script — without it, stacked mobile layouts get clipped inside the SharePoint iframe.
- **Internal navigation**: links to other SharePoint pages/lists use the full URL (`https://allysaice.sharepoint.com/sites/SAICEPDP/...`) and `target="_parent"`, so the link navigates the top-level SharePoint page instead of loading inside the widget's iframe. Keep this pattern for any new outbound link to internal SharePoint content.
- **Content is static HTML**, not data-driven — there's no JSON/CMS backing these widgets. "Updating" a widget (e.g. new events, new stats) means directly editing the markup in place.

## `ers-paye-calculator.html`

Self-contained tax calculator with real logic — treat it as its own mini design system, separate from the SAICE suite:

- Distinct navy/gold/crimson "paper" theme (`--navy`, `--gold`, `--crimson`, `--paper` CSS custom properties defined in `:root`), fonts loaded from Google Fonts (`Source Serif 4`, `Inter`, `Roboto Mono`).
- Tax logic lives in the `<script>` block: `BRACKETS` (bracket table with `min`/`max`/`base`/`rate`), `taxForAnnualIncome()` computes liability and the per-bracket breakdown, `calculate()` reads the form inputs and re-renders on every `oninput`, `renderLadder()` draws the bracket visualization. If bracket thresholds/rates or the senior rebate change, update the `BRACKETS` array and the `rebate` constant in `calculate()` — the ladder visualization and bracket list re-derive from these automatically.
- No auto-resize script and no SharePoint links — don't add the SAICE embed-resize IIFE here unless this calculator is also going to be embedded in the SharePoint iframe context.
