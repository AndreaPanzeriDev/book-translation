---
name: Book Translation Studio
description: A comparison-first translation desk in parchment, leather, and ink.
colors:
  parchment-50: "#fbf8f3"
  parchment-100: "#f5efe5"
  parchment-200: "#e9dfd0"
  parchment-300: "#d8c6ad"
  ink-900: "#2b2520"
  ink-700: "#4a4038"
  ink-500: "#746a60"
  leather-700: "#6f4e37"
  leather-600: "#8b5e3c"
  leather-100: "#f2e7dc"
  surface-white: "#ffffff"
  annotation-amber: "#fee685"
  success-green: "#4f7a5b"
  warning-gold: "#c28a2e"
  danger-red: "#a94442"
  danger-surface: "#fef2f2"
  danger-ink: "#b91c1c"
  placeholder-ink: "#746a60"
  scrollbar-thumb: "#cfc3b4"
typography:
  display:
    fontFamily: "Georgia, 'Iowan Old Style', 'Palatino Linotype', 'Book Antiqua', Palatino, serif"
    fontSize: "2.25rem"
    fontWeight: 400
    lineHeight: 1.25
    letterSpacing: "0em"
  headline:
    fontFamily: "Georgia, 'Iowan Old Style', 'Palatino Linotype', 'Book Antiqua', Palatino, serif"
    fontSize: "1.875rem"
    fontWeight: 400
    lineHeight: 1.2
  title:
    fontFamily: "Georgia, 'Iowan Old Style', 'Palatino Linotype', 'Book Antiqua', Palatino, serif"
    fontSize: "1.125rem"
    fontWeight: 400
    lineHeight: 1.375
  reading:
    fontFamily: "Georgia, 'Iowan Old Style', 'Palatino Linotype', 'Book Antiqua', Palatino, serif"
    fontSize: "1.075rem"
    fontWeight: 400
    lineHeight: 1.8
  reading-heading:
    fontFamily: "Georgia, 'Iowan Old Style', 'Palatino Linotype', 'Book Antiqua', Palatino, serif"
    fontSize: "1.5rem"
    fontWeight: 600
    lineHeight: 1.3
  reading-subheading:
    fontFamily: "Georgia, 'Iowan Old Style', 'Palatino Linotype', 'Book Antiqua', Palatino, serif"
    fontSize: "1.3rem"
    fontWeight: 600
    lineHeight: 1.3
  reading-spread:
    fontFamily: "Georgia, 'Iowan Old Style', 'Palatino Linotype', 'Book Antiqua', Palatino, serif"
    fontSize: "0.98rem"
    fontWeight: 400
    lineHeight: 1.72
  body:
    fontFamily: "'Instrument Sans', ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.43
  navigation:
    fontFamily: "'Instrument Sans', ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 500
    lineHeight: 1.43
  caption:
    fontFamily: "'Instrument Sans', ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 400
    lineHeight: 1.33
  label:
    fontFamily: "'Instrument Sans', ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 600
    lineHeight: 1.33
    letterSpacing: "0.025em"
  micro:
    fontFamily: "'Instrument Sans', ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.6875rem"
    fontWeight: 400
    lineHeight: 1.5
  micro-label:
    fontFamily: "'Instrument Sans', ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.6875rem"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "0.16em"
  mono:
    fontFamily: "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace"
    fontSize: "0.75rem"
    fontWeight: 400
    lineHeight: 1.5
rounded:
  sm: "4px"
  md: "6px"
  lg: "8px"
  xl: "12px"
  2xl: "16px"
  full: "9999px"
spacing:
  "1": "4px"
  "2": "8px"
  "3": "12px"
  "4": "16px"
  "5": "20px"
  "6": "24px"
  "8": "32px"
  "10": "40px"
  "12": "48px"
components:
  button-primary:
    backgroundColor: "{colors.leather-600}"
    textColor: "{colors.surface-white}"
    typography: "{typography.body}"
    rounded: "{rounded.lg}"
    padding: "0 15px"
    height: "32px"
  button-primary-large:
    backgroundColor: "{colors.leather-600}"
    textColor: "{colors.surface-white}"
    typography: "{typography.body}"
    rounded: "{rounded.lg}"
    padding: "0 16px"
    height: "40px"
  button-default:
    backgroundColor: "{colors.surface-white}"
    textColor: "{colors.ink-900}"
    typography: "{typography.body}"
    rounded: "{rounded.lg}"
    padding: "0 15px"
    height: "32px"
  input:
    backgroundColor: "{colors.surface-white}"
    textColor: "{colors.ink-900}"
    typography: "{typography.body}"
    rounded: "{rounded.lg}"
    padding: "0 11px"
    height: "32px"
  tag:
    backgroundColor: "{colors.parchment-100}"
    textColor: "{colors.ink-700}"
    typography: "{typography.caption}"
    rounded: "{rounded.sm}"
    padding: "0 7px"
    height: "22px"
  card:
    backgroundColor: "{colors.surface-white}"
    textColor: "{colors.ink-900}"
    typography: "{typography.body}"
    rounded: "{rounded.xl}"
    padding: "16px"
  nav-item-active:
    backgroundColor: "{colors.surface-white}"
    textColor: "{colors.leather-700}"
    typography: "{typography.navigation}"
    rounded: "{rounded.md}"
    padding: "0 12px"
    height: "36px"
  chapter-row-active:
    backgroundColor: "{colors.leather-100}"
    textColor: "{colors.leather-700}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "6px 8px"
  reader-edge-arrow:
    backgroundColor: "{colors.surface-white}"
    textColor: "{colors.leather-700}"
    rounded: "{rounded.full}"
    size: "44px"
  comparison-pane:
    backgroundColor: "{colors.parchment-50}"
    textColor: "{colors.ink-900}"
    typography: "{typography.reading}"
    rounded: "0px"
    padding: "16px"
---

# Design System: Book Translation Studio

## Overview

**Creative North Star: "The Translator's Desk"**

This is a working desk, not a dashboard: warm parchment underfoot, ink for the words that matter, and a single worn leather accent where the user acts. The product's core scene is two or three pages laid side by side — the original and its competing translations — so the interface behaves like a well-made reading surface that happens to have controls. Density is comfortable, never airy; every panel earns its place beside the text.

The mood is warm scholarly restraint. The reading serif carries books, chapters, and translated prose; Instrument Sans carries controls, metadata, and navigation. White is reserved for surfaces that lift off the parchment: cards, toolbars, and reading pages. Leather appears in small, deliberate doses — primary buttons, active navigation, progress fills, the brand tile — and never as a decorative wash. The result should feel quietly precise, like a translator's reference desk that has been used for years.

Depth is soft paper layering. Parchment is the flat ground; white cards sit on it with a faint shadow, and shadows grow only on hover or for floating work panels. Borders do most of the structural work. The system explicitly rejects the glossy SaaS dashboard: no purple gradients, glassmorphism, generic analytics cards, or ornamental startup chrome.

**Key Characteristics:**
- Parchment ground, white lifted surfaces, ink text.
- One leather accent for action and identity; amber only for sentence alignment.
- Literary serif for books, compact sans for the tool.
- 8px control radius, 12px cards, 16px hero panels.
- Border-first depth with restrained shadows.
- Comparison-first layouts; never more than three text columns.

## Colors

The palette is a physical one: unbleached paper, oxide ink, saddle leather, and small status accents borrowed from a proofreader's pencil case.

### Primary
- **Aged Saddle Leather** (#8b5e3c): The working accent. Fills primary buttons, progress bars, active icons, and the Ant Design primary token. Its deeper sibling **Dark Saddle Leather** (#6f4e37) carries the brand tile, active navigation text, and reader arrows.

### Secondary
- **Antique Gold** (#c28a2e): The warm status accent. Used for gold tags that mark language direction, saved API keys, and orientation metadata. It supports the leather accent without competing with it.

### Tertiary
- **Reading Amber** (#fee685): The annotation color. Appears at 80% opacity behind aligned sentences in the comparison and reader views. It is a highlighter, not a brand color.

### Neutral
- **Unbleached Parchment** (#fbf8f3): The page ground for the app shell, reader, and source pane.
- **Warm Parchment** (#f5efe5): Recessed panels, inactive navigation, sidebar wash, and tags.
- **Parchment Edge** (#e9dfd0): The default 1px border, divider, and progress track.
- **Deep Parchment** (#d8c6ad): Dashed empty-state borders, muted icons, and blockquote rules.
- **Oxide Ink** (#2b2520): Primary text and headings.
- **Soft Ink** (#4a4038): Secondary prose, labels, and form values.
- **Muted Ink** (#746a60): Metadata, hints, timestamps, and quiet labels.
- **Paper White** (#ffffff): Cards, toolbars, inputs, and any surface that must lift off the parchment.
- **Success Green** (#4f7a5b): Completed translation and complete export states.
- **Danger Red** (#a94442): Destructive actions and failed translation states. Antique Gold also serves the warning state.
- **Danger Surface** (#fef2f2) and **Danger Ink** (#b91c1c): Soft error panels and their readable text in batch failures.
- **Placeholder Ink** (#746a60): Empty translation placeholders and form placeholders. It mirrors Muted Ink so hints stay above 4.5:1 contrast.
- **Scrollbar Thumb** (#cfc3b4): The thin custom scrollbar thumb across the app.

### Named Rules
**The Parchment Ground Rule.** The app background is always Unbleached Parchment. Pure white is a lifted surface, never the page itself.

**The Ledger Accent Rule.** Saddle Leather is spent on action, active state, progress, and identity. If leather covers more than roughly a tenth of a screen, it has become decoration.

**The Amber Annotation Rule.** Reading Amber marks sentence alignment only. It never marks buttons, links, warnings, or general emphasis.

## Typography

**Display Font:** Georgia (with Iowan Old Style, Palatino Linotype, Book Antiqua, Palatino, serif)
**Body Font:** Instrument Sans (with system-ui, sans-serif)
**Label Font:** Instrument Sans for labels.
**Mono Font:** ui-monospace (with SFMono-Regular, Menlo, Monaco, Consolas) for model IDs, endpoints, and source hashes.

**Character:** A literary serif against a compact, slightly technical sans. The pairing reads as a translator's edition: the book is set in serif, the apparatus around it in sans.

### Hierarchy
- **Display** (400, 2.25rem/1.25, tight): Library hero headline. Drops to 1.875rem below the medium breakpoint.
- **Headline** (400, 1.875rem/1.2): Page titles such as the library, accounts, and book studio. 1.5rem at small widths.
- **Title** (400, 1.125rem/1.375): Book titles in cards, sidebar titles, and empty-state headings. Often paired with a sans subtitle.
- **Reading Heading** (600, 1.5rem/1.3): The h1 level inside reading panes.
- **Reading Subheading** (600, 1.3rem/1.3): The h2 level inside reading panes.
- **Reading** (400, 1.075rem/1.8): Canvas prose in the studio. The reader uses 1.05rem/1.8 single-column.
- **Reading Spread** (400, 0.98rem/1.72): Reader prose in two-column spread mode.
- **Body** (400, 0.875rem/1.43): Default UI text, form values, descriptions, and card copy.
- **Navigation** (500, 0.875rem/1.43): Header navigation labels and active tabs.
- **Caption** (400, 0.75rem/1.33): Tags, metadata, timestamps, and helper text.
- **Label** (600, 0.75rem/1.33, 0.025em, uppercase): Section labels and pane headers such as "Original" and "Translation 1".
- **Micro** (400, 0.6875rem/1.5): Dense metadata in export rows and batch details. 11px is the functional floor; nothing smaller ships.
- **Micro-label** (400, 0.6875rem/1.5, 0.16em, uppercase): Reader page headers and chapter position marks.

### Named Rules
**The Two Voices Rule.** Books, chapter titles, and translated prose use the reading serif. Buttons, forms, navigation, and metadata use Instrument Sans. Never set a control or a UI label in the reading serif.

**The Unjustified UI Rule.** Justified text and automatic hyphenation belong to reader body paragraphs only. UI copy, headings, labels, and short text stay left-aligned and unhyphenated.

## Layout

The app shell is a centered 1500px canvas with 16px gutters on mobile and 24px from the medium breakpoint up. Pages breathe with 24–32px vertical rhythm. The library uses a 1–4 column card grid (2 at small, 3 at large, 4 at 2xl); accounts use 2 columns at medium and 3 at xl.

The studio is a two-pane workspace: a 290px left rail for chapters, selection, and the translate action, and a flexible reading area. Each virtual page is a white card; inside it, the original and one or two translations form equal columns at xl (two columns for one translation, three for two). The reader is viewport-locked: a 56px header, a 48px footer, an optional 300–320px chapter sidebar, and a horizontally paged canvas. Single-column reading is capped at 46rem; spread reading at 1200px. Reader body paragraphs are justified with a 1.35em first-line indent after the first paragraph, and columns carry a 2.5rem gap (3.25rem at xl).

Breakpoints: 640px, 768px, 1024px, 1280px, 1536px. Density is comfortable rather than compact: cards use 16–20px padding, panes use 16px, and controls keep a 32px default height. On small screens, primary controls — back, chapter navigation, page checkboxes, read, translate — grow to at least 44×44px.

Comparison panes are flat sections, not cards inside a card. The page article owns the border and radius; inside it, the original text sits on a parchment tint and translations sit on the article ground, separated by spacing and a 1px rule under each pane label.

### Named Rules
**The Library First Rule.** The full pitch and inline import panel appear only when the library is empty. Once books exist, the page leads with the library grid, inline counts, and a compact import drawer.

**The Three-Column Ceiling.** A comparison page holds the original plus at most two translations. Never add a fourth reading column; start a new page or a new comparison instead.

**The Viewport Reader Rule.** The reader never scrolls the document vertically. Pages advance in horizontal screens; the header, footer, and sidebar stay fixed.

**The Touch Floor Rule.** Primary controls are at least 44×44px on small screens, and functional text never drops below 11px. Icon-only actions always carry an accessible name.

**The Sticky Selection Rule.** On mobile, the selected-page count and Translate action stay pinned to the viewport bottom while a selection exists; the same summary lives in the desktop rail.

**The One Outline Rule.** Every page owns exactly one h1. Book-content headings are demoted beneath it — h3 in the studio under the chapter h2, h2 in the reader under the book title — so EPUB markup never competes with the page outline.

**The Legible Coverage Rule.** Chapter coverage is shown per translation slot with a labelled progressbar outside the row button, so screen readers announce the fraction and the bar never flattens the button's name.

## Elevation & Depth

This is a soft paper-layer system. Parchment is flat at rest. White cards and toolbars sit one layer above it with a faint shadow, while the border supplies most of the edge definition. Shadows grow only in response to state or floating work: library cards lift 2px on hover, and the translation batch panel floats above everything at the bottom-right.

### Shadow Vocabulary
- **Card Rest** (`box-shadow: 0 1px 3px 0 #0000001a, 0 1px 2px -1px #0000001a`): Default white cards, toolbars, and the sticky app header.
- **Card Hover** (`box-shadow: 0 4px 6px -1px #0000001a, 0 2px 4px -2px #0000001a`): Library cards on hover, paired with a 2px lift.
- **Floating Work** (`box-shadow: 0 25px 50px -12px #00000040`): The global translation batch panel and other temporary floating surfaces.
- **Reader Arrow** (`box-shadow: 0 8px 24px rgb(74 54 38 / 14%)`): The circular page-turn controls, which also use a 6px backdrop blur.

### Named Rules
**The Border First Rule.** Reach for a 1px Parchment Edge border before adding a shadow. If the border already separates the surface, the shadow is optional.

**The Hover Lift Rule.** Only interactive cards lift. Resting cards, panes, and metadata panels stay at Card Rest; nothing bobs or floats without a state change.

## Shapes

The form language is soft, rectangular, and paper-like. Controls use an 8px radius, rows use 6px, cards use 12px, and the library hero uses 16px. Pills and perfect circles are reserved for progress bars, chapter number badges, and circular reader arrows. Borders are consistently 1px; dashed borders appear only on empty states and missing-translation placeholders. There is no clipping, notched corners, or ornamental framing; cover images use a simple 4–6px radius.

### Named Rules
**The Soft Rectangle Rule.** Every surface is a soft rectangle first. Circles are functional badges, not a decorative motif.

## Components

### Buttons
- **Shape:** Gently rounded (8px radius), 32px default height, 40px for large primary actions.
- **Primary:** Saddle Leather fill, Paper White text, Instrument Sans at 14px. Large primary buttons anchor the import and translate actions.
- **Hover / Focus:** Ant Design's derived primary hover warms the fill; focus uses the default visible focus ring. Secondary buttons shift their border and text toward saddle leather on hover.
- **Secondary / Ghost / Tertiary:** Default buttons are Paper White with a Parchment Edge border and Oxide Ink text. Text buttons are transparent and reserved for low-priority actions such as close, clear, or layout toggles. Destructive actions use Danger Red and a danger-styled confirmation.

### Chips (Tags)
- **Style:** 22px tall, 4px radius, Warm Parchment ground with a Parchment Edge border and Soft Ink text. Model identifiers use the mono stack at 10–11px.
- **State:** Default tags carry metadata. Gold marks language direction, saved keys, and incomplete exports; green marks cached, complete, or active; errors use the danger palette. Parallel request mode stays neutral rather than introducing blue.

### Cards / Containers
- **Corner Style:** 12px radius for cards and page articles; 16px for the library hero. Comparison panes and translation slots are flat sections with no radius, so cards never nest inside cards.
- **Background:** Paper White on the parchment ground. The original-text pane uses a translucent parchment tint to distinguish it from translations; translations stay on the article ground.
- **Shadow Strategy:** Card Rest at rest; Card Hover only on interactive library cards. See Elevation & Depth.
- **Interaction:** A library card is a single link to its studio. Destructive controls sit outside the link and remain individually focusable.
- **Border:** 1px Parchment Edge; dashed Deep Parchment for empty states.
- **Internal Padding:** 16px for cards and panes, 20px for account cards, 24–32px for empty states and hero sections.

### Inputs / Fields
- **Style:** Paper White ground, 1px Parchment Edge border, 8px radius, 32px control height, 44px minimum on small screens. Labels sit above fields in the Ant Design vertical form pattern. Placeholder text uses Muted Ink, never Ant Design's default pale gray.
- **Focus:** The border shifts to Saddle Leather with a soft leather-tinted focus ring; the field keeps its white ground.
- **Error / Disabled:** Ant Design error red and muted parchment fills. Disabled controls lose contrast but keep their shape.

### Navigation
- **App header:** Sticky, 85% white with a 12px backdrop blur and a Parchment Edge bottom border. The brand is a 40px saddle-leather tile with a book icon, followed by the product name and a hidden-on-mobile tagline. Navigation sits in a Warm Parchment segmented well; the active item is a Paper White pill with Dark Saddle Leather text and a faint shadow. The IT/EN switch and a local-only tag sit on the right.
- **Reader header:** A 56px fixed bar with a back action, a collapsible sidebar trigger, the book title in reading serif, and an edition-style version selector. On mobile the title yields its space to the selector.
- **Version selector:** Two lines in the trigger — account · language above the short model name — with the full model and translated-page count in the dropdown. This keeps competing models distinguishable without turning the header into a paragraph.
- **Mobile:** The reader sidebar becomes a left drawer that starts below the 56px header; the app navigation collapses to icons, and chapter lists gain search.

### Signature Components
**The Comparison Pane.** The product's defining surface: an original pane and one or two translation panes, equally sized at xl, each with a small uppercase label and a metadata row. The original uses a parchment tint; translations stay flat on the article ground. Selecting source sentences highlights the aligned sentences in every visible translation with Reading Amber.

**The Virtual Page Card.** Each page is a white article with a 12px radius, a Parchment Edge border, and a header strip holding the checkbox, page number, character count, and per-page actions. Inside, comparison panes sit on 16px padding with 20px gaps; the panes are flat sections, not nested cards.

**The Chapter Row.** A 44px row with a numbered badge, truncated title, and page count, backed by one thin coverage track per active translation slot. The tracks sit outside the row button so their progressbar semantics survive; the active row uses Leather Wash with dark leather text.

**The Batch Panel.** A fixed bottom-right stack of white cards with a Floating Work shadow, a progress bar, counts, and cancel/retry actions. It is the one place where elevation is intentionally loud, because it represents work happening outside the current page.

## Do's and Don'ts

### Do:
- **Do** keep Unbleached Parchment as the page ground and Paper White for lifted cards, toolbars, and reading pages.
- **Do** reserve Saddle Leather for primary actions, active states, progress fills, and the brand tile.
- **Do** set books, chapter titles, and reading prose in the reading serif, and everything operational in Instrument Sans.
- **Do** use 8px for controls, 6px for rows, 12px for cards, and 16px for hero panels.
- **Do** define edges with 1px Parchment Edge borders before adding shadows.
- **Do** use Reading Amber only for aligned sentences in comparison and reader views.
- **Do** use the mono stack only for model IDs, endpoints, and source hashes.
- **Do** keep the reader at a 46rem single-column measure or a 1200px spread, and the comparison at three columns maximum.
- **Do** use tabular numerals for counts, progress, and page positions.
- **Do** keep primary touch targets at least 44×44px on small screens and functional text at least 11px.
- **Do** keep one h1 per page and demote book-content headings beneath it.
- **Do** label every coverage bar, keep it outside its row button, and show one track per translation slot.
- **Do** search chapter lists by title or number once a book has more than a handful of chapters.

### Don't:
- **Don't** introduce glossy SaaS gradients, glassmorphism, purple or blue brand accents, or generic analytics cards.
- **Don't** use pure black or neutral gray text; use Oxide Ink, Soft Ink, or Muted Ink.
- **Don't** set buttons, labels, navigation, or metadata in the reading serif.
- **Don't** use leather as a large background wash, texture, or decorative panel fill.
- **Don't** put a drop shadow on every card or animate resting surfaces.
- **Don't** add a fourth reading column; the original plus two translations is the ceiling.
- **Don't** justify UI copy, headings, or short text; justification belongs to reader paragraphs.
- **Don't** use Reading Amber for links, warnings, buttons, or general emphasis.
- **Don't** invent new radii, shadow levels, or accent hues when an existing token fits.
- **Don't** nest cards inside cards; use flat panes, spacing, and rules.
- **Don't** put progressbars inside buttons or signal coverage by color alone.
- **Don't** introduce 10px functional text or icon-only actions without an accessible name.
