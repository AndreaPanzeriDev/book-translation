# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Today the primary user is one person running the app locally: a reader or translator who imports EPUB books, produces machine translations, judges their quality against the original, and exports finished reading copies. The current no-login, local-only setup is an MVP constraint, not a permanent identity: the product is intended to grow into a public multi-user or hosted service later.

## Product Purpose

Book Translation Studio imports EPUB books, translates them with one or two independently configured AI accounts and models, and presents the original beside the translations for direct comparison. Success means the user can confidently judge translation quality with their own eyes and export a complete, clean reading copy in EPUB or PDF. The two outcomes that matter most are judging translation quality and exporting a reading copy; reliable background batches and cheap model comparison support those outcomes.

## Positioning

A comparison-first translation studio: the original and up to two LLM translations stay equally visible, sentence-aligned, and independently configured per column, with per-page caching keyed by source text, account, model, language, and prompt version. A neighboring single-translation tool could not truthfully copy this "read the original against competing outputs" workflow without redesigning its core surface around comparison.

## Operating Context

The app runs locally through Docker Compose and is used in a browser, with EPUB files as the input material. AI providers are OpenAI, OpenRouter, a local Ollama instance, or any OpenAI-compatible endpoint; API keys are encrypted at rest with the Laravel app key and never sent to the browser. Long books produce long-running translations, so batches run server-side on queue workers, survive refresh or tab closure, persist progress in the database, and allow different books to translate in parallel while a book has only one active batch. The interface is bilingual Italian/English, follows the browser language by default with Italian fallback, and stores an explicit IT/EN choice in a one-year cookie; each book stores its target language, initially Italian. Export produces an EPUB 3 with cover, table of contents, and one document per chapter, or an A5 PDF with title page, table of contents, and page numbers.

## Capabilities and Constraints

Confirmed capabilities:

- EPUB 2/3 import with metadata, cover, table of contents, chapters, and text blocks.
- Stable virtual pages of roughly 1,500 characters, because EPUB has no fixed pagination.
- Selection of one page, a Shift-click range, a whole chapter, or the whole book.
- Original plus one translation, or original plus two translations (maximum three columns); each translation column independently chooses its AI account and model.
- Multiple AI accounts with per-account serial or parallel request mode and a per-account parallel limit; queue workers bound actual concurrency.
- Server-side translation batches with one active batch per book, parallel batches across different books, persistent progress, a global progress panel, cancellation, and retry of failures.
- Translation cache keyed by page, source text hash, account, model, target language, prompt version, and parameters; cached pages are shown instantly and tagged as cached.
- Sentence-level alignment: selecting sentences in the original highlights the corresponding sentences in every visible translation, including cached ones.
- Export blocks when selected pages lack a translation matching the current account, model, target language, prompt version, and source hash, or when any block's translated text is empty; the 422 response lists the incomplete chapters.
- Backend validation, flash messages, and background batch errors follow the selected interface language.

Known limits and constraints:

- No OCR: image-only EPUBs yield no translatable text.
- No DRM: protected files cannot be imported.
- Inline formatting such as italics, bold, and links is flattened to plain text, while structural blocks such as headings, paragraphs, quotes, and lists are preserved.
- Only the cover image is extracted.
- Virtual pages are a practical division of text, not the pagination of the original edition.
- Comparison is limited to two translations at a time.
- The current build has no authentication and publishes its ports only on `127.0.0.1`; using it on a public server requires authentication, HTTPS, and secrets management that are not yet designed.
- The app currently targets a single local user even though multi-user hosting is the intended future direction.

## Brand Commitments

The product name is **Book Translation Studio**. The interface is product-bilingual: Italian and English, with Italian as the fallback language. The tone is practical and reader-oriented rather than promotional.

## Evidence on Hand

- `README.md` is the authoritative product and operations document, written in Italian, covering setup, providers, workflows, architecture, tests, privacy, and MVP limits.
- The Pest suite covers parsing, import, pagination, translation, caching, batches, accounts, export, and localization; the README reports 92 tests and 444 assertions.
- EPUB fixtures and real React/Inertia pages exist in the repository.
- No testimonials, customer references, benchmarks, pricing, or public deployment claims exist; future work must not invent them.

## Product Principles

1. Comparison is the product: keep the original and competing translations equally visible, aligned, and easy to move between.
2. The reader stays in control: provider, account, model, target language, and request mode are explicit choices, and credentials remain private.
3. Long work must survive interruption: translation state lives on the server, resumes from the database, and never depends on an open tab.
4. Export only complete, current translations: never silently ship mixed versions or empty blocks.
5. State limits honestly: OCR, DRM, inline formatting, and single-user constraints are disclosed rather than hidden or faked.
