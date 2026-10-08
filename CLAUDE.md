# Post SEO Optimizer – project guide

WordPress/WooCommerce plugin for **bazarpati.com** (Bangladesh). Products are imported from dropshippers by the owner's **custom import plugin**; their titles and descriptions are copied supplier text. This plugin rewrites one product at a time with AI into SEO-friendly content, writes Yoast SEO fields, and **never saves anything without the user reviewing it first**.

## Requirements (agreed with the owner – don't change without asking)

- **One product at a time**, from the product edit screen. No bulk mode, no auto-optimize on import.
- **Review before apply**: AI output is shown next to the current values, editable, with per-field checkboxes. Original content is backed up on the first apply and can be restored.
- **Languages**: English and Bangla (বাংলা), chosen per product (`auto` / `en` / `bn`). Slugs are always English.
- **Providers**: Gemini (default, `gemini-3.8-flash` – Google restricted 2.5 models to existing users; the owner's new key got "model not found" for `gemini-2.5-flash`), OpenAI (`gpt-4.1-mini`), Anthropic (`claude-sonnet-5-5`). Model names are editable in settings.
- **SEO plugin**: Yoast SEO (focus keyphrase, SEO title, meta description).
- **Accuracy over speed**: the owner rejected faster/smaller models ("can not answer accurately") and wants to wait up to **10 minutes**. Their web requests are cut at ~60 s, so generation runs **in the background** (see below). Setting `timeout` is 30–600 s (default 300); thinking level for Gemini 3+ is a setting (`low`/`medium`/`high`, default `medium`).
- Slug is **not** pre-ticked (it changes the URL). The page **reloads after apply/restore**.
- Must stay **secure and fast**: admin-only, zero storefront footprint.

## Layout

```
post-seo-optimizer.php          Bootstrap: constants, autoloader, activation, HPOS compat
uninstall.php                   Removes options/keys/transients; backups only if delete_data=1
includes/
  class-bzpso-plugin.php        Boots admin components (returns early on the front end)
  class-bzpso-settings.php      WooCommerce → SEO Optimizer page; options; API key storage
  class-bzpso-crypto.php        API key encryption (sodium secretbox, fallback AES-256-GCM)
  class-bzpso-metabox.php       "AI SEO Optimizer" meta box + JS config/i18n
  class-bzpso-ajax.php          wp_ajax_bzpso_{generate,job_status,apply,restore,test_connection}
  class-bzpso-jobs.php          Background generation jobs (loopback worker + Action Scheduler backup)
  class-bzpso-product-data.php  Source data for the AI, apply, backup, restore
  class-bzpso-prompt.php        System prompt (rules) + user message (supplier data)
  class-bzpso-sanitizer.php     Parse/sanitize AI JSON; sanitize submitted values
  class-bzpso-seo-meta.php      Yoast meta keys (_yoast_wpseo_focuskw/_title/_metadesc)
  class-bzpso-rate-limiter.php  Per-user lock + hourly limit (transients)
  class-bzpso-admin-list.php    Products list "AI SEO" column + "Optimize SEO" row action
  providers/class-bzpso-provider*.php  Base HTTP/error handling + Gemini/OpenAI/Anthropic
assets/js/admin.js              Review UI (vanilla JS, no dependencies)
assets/js/settings.js           "Test connection" button
assets/css/admin.css
```

The autoloader maps `BZPSO_Foo_Bar` → `includes/class-bzpso-foo-bar.php` (or `includes/providers/`).

## Conventions

- **Prefix `bzpso` / `BZPSO_`** for classes, functions, constants, options, meta, transients, hooks, CSS classes/IDs and JS globals (`bzpsoConfig`, `bzpsoSettings`). The old short `pso` prefix was deliberately replaced; don't reintroduce it.
- Text domain `post-seo-optimizer`. PHP **7.4+** (no `str_contains`, `match`, `?->` etc.), WP 6.0+, WC 7.0+.
- WordPress Coding Standards (tabs, Yoda conditions, docblocks on every function, comments end with a full stop).
- Write product fields through **WooCommerce CRUD** (`set_name()`, `set_description()`, `set_slug()` … `save()`), not `wp_update_post()`.
- Pass meta values through `wp_slash()` (`update_post_meta()` unslashes).
- New user-facing strings must be translatable; JS strings go through the `i18n` array in `BZPSO_Metabox::enqueue()`.

## Security rules (all covered by tests – keep them)

- Every product AJAX endpoint: per-product nonce `bzpso_product_{ID}` + `current_user_can( 'edit_post', $id )` via `product_from_request()`. Settings: `manage_woocommerce` + `check_admin_referer( 'bzpso_save_settings' )`. **No `wp_ajax_nopriv_` handlers.**
- API keys: never echo them back into a page, never log them, never put them in a URL (Gemini uses the `x-goog-api-key` header). Keys can come from `wp-config.php` constants `BZPSO_GEMINI_API_KEY`, `BZPSO_OPENAI_API_KEY`, `BZPSO_ANTHROPIC_API_KEY` (take priority over the DB).
- AI output is untrusted: `BZPSO_Sanitizer` keeps an allow-list of tags **without attributes**; values are sanitized again on save (`wp_kses_post` for descriptions). In JS, insert text with `textContent`; HTML previews go through `sanitizeHtml()` (DOMParser + allow-list). Never use `innerHTML` with server/AI data.
- Supplier data is untrusted too: it goes in the user message inside `<product_data>` as JSON with `JSON_HEX_TAG`, and the system prompt tells the model to ignore instructions in it.
- Outbound requests use `wp_safe_remote_post()` with `redirection => 0`.

## Background generation (`BZPSO_Jobs`)

1. `bzpso_generate` validates (nonce, capability, key, lock, hourly limit), stores a job (transient `bzpso_job_{32 chars}`) and returns `{job}` immediately.
2. The job is started by a **non-blocking loopback** POST to `admin-ajax.php?action=bzpso_worker` that forwards the user's cookies (same technique as WP core / WP Background Processing) with nonce `bzpso_worker_{id}`; the worker re-checks user + `edit_post`, calls `ignore_user_abort(true)` and `fastcgi_finish_request()`, then runs the job. **Backup**: an Action Scheduler action `bzpso_run_job` scheduled 30 s later (registered in `BZPSO_Plugin` *before* the `is_admin()` early return, because WP-Cron isn't an admin request).
3. `run()` claims the job atomically (`INSERT IGNORE` into options `bzpso_claim_{id}`) so it executes once, then calls `BZPSO_Provider::generate()` with the full timeout. On Linux, time spent waiting on HTTP doesn't count toward PHP's `max_execution_time`.
4. `admin.js` polls `bzpso_job_status` every 3 s, shows elapsed time, and remembers the job in `sessionStorage` so a page reload resumes it. Finished/failed jobs are deleted when reported. Jobs still queued after 180 s (`START_GRACE`) or running longer than timeout + 180 s are failed with a clear message.
5. One job per user (`bzpso_lock_{user}`, TTL timeout + 480 s; released by the worker). `test_connection` stays synchronous but is capped at 45 s (`BZPSO_Provider::create( 45 )`).

## Token optimization (owner wants low cost with the best result)

Output (incl. thinking) costs ~5× input, so output is where savings matter. Measured on a typical dropship product: input went from ~1,300 to ~730 tokens after these changes.
- **Only requested fields are generated**: "Generate" checkboxes on the product screen (no `name` attribute, defaults = `default_fields` setting) → `fields[]` in `bzpso_generate` (allow-listed) → job args → `BZPSO_Prompt::build()` asks for exactly those keys → `BZPSO_Sanitizer::suggestions( $data, $fields )` drops anything else.
- **Description length** setting `desc_length` (`short` 150–250 / `standard` 250–400 / `long` 400–600 words; `BZPSO_Prompt::DESCRIPTION_WORDS`).
- **Static system prompt**: `BZPSO_Prompt::system_prompt()` depends only on settings, so it is byte-identical for every product (prefix-caching friendly). Everything per-product (language, keyphrase, extra instructions, requested keys, data) goes in the user message. Keep it that way when editing the prompt.
- **Lean input** (`BZPSO_Product_Data::source_data( $product, $fields )`): compact JSON, `plain_text( ..., $unique = true )` drops repeated lines, short description omitted when contained in the description, `existing_tags` only when tags are requested, no price/product type.
- **Usage display**: providers call `set_usage()`; `usage()` → job result `usage` → shown under the review table. Gemini output = `candidatesTokenCount + thoughtsTokenCount`.

## Gotchas (learned the hard way)

- **Asset caching**: always enqueue CSS/JS with `BZPSO_Plugin::asset_version( 'assets/…' )` (version + file mtime), and bump `BZPSO_VERSION` + the header `Version:` + readme `Stable tag`/changelog for each release. With a fixed `?ver=` the owner's browser/cache kept an old `admin.js` against new PHP ("config.defaultFields is undefined", 2026-10-08).
- **Reload after apply is required**: Yoast's meta box on the open page holds the old values and would overwrite the new ones on the next "Update". `admin.js` unbinds `beforeunload.edit-post` and warns about unsaved editor changes first.
- **Don't use `sanitize_text_field()` for SEO titles**: it strips `%xx` sequences and breaks Yoast variables like `%%category%%`. Use `BZPSO_Sanitizer::line()`.
- **AJAX errors use HTTP 400/403/429, never 502/504**: Cloudflare and some hosts replace 5xx bodies, which hides our error message.
- Gemini thinking (`BZPSO_Provider_Gemini::thinking_config()`): `gemini-2.5-flash*` → `thinkingBudget = 0`; other `gemini-1.*`/`gemini-2.*` → nothing; **everything else** (Gemini 3.x, future versions, aliases like `gemini-flash-latest`) → `thinkingConfig.thinkingLevel` = the **Thinking level** setting (`minimal` is rejected by 3.7/3.8 Flash, so it isn't offered). If Gemini answers **HTTP 400** while a thinking setting was sent, the request is retried once without it; if the error text mentions "thinking" and the retry works, the model is remembered for a week in transient `bzpso_gemini_nothink_{md5(model)}` so later requests skip the failing attempt. (The exact REST casing for `thinkingLevel` wasn't confirmed in Google's docs – verify with a real key and remove this note.)
- When Google retires a model the owner only changes the **Model** field in settings; no code change is needed.
- **Busy / overloaded models** (HTTP 429, 500, 502, 503, 504, 529): AJAX calls `BZPSO_Provider::generate()` (not `complete()`), which retries the main model once after 2 s, then switches to the per-provider **fallback model** (`fallback_models` setting; Gemini default `gemini-3.7-flash`, others empty = off). Every attempt stays inside the time budget (`deadline` = start + timeout − 5; HTTP timeout = seconds left; retry needs ≥ 20 s left, fallback ≥ 15 s). `used_fallback()` / `primary_model()` let the UI say which model answered. The owner hit "gemini-3.8-flash is experiencing high demand" on 2026-10-08, which is why this exists.
- API errors carry the HTTP status in their error data (`array( 'status' => $code )`); read it with `BZPSO_Provider::error_status()`.
- OpenAI uses `max_completion_tokens` (works for older and newer models); temperature is never sent (newer models reject it).
- WooCommerce names untitled products "Product"; `generate` treats that as no title.
- Yoast only builds indexables when `WP_ENVIRONMENT_TYPE` is `production`; on local/staging sites the indexable table won't update (front-end output still does).
- The settings option is created with autoload **off** on activation; keep it that way (admin-only data).
- Tags are **added**, never replaced, on apply; restore sets them back exactly.

## Data stored

| Key | Where | Purpose |
|---|---|---|
| `bzpso_settings` | option (autoload off) | Settings |
| `bzpso_api_keys` | option (autoload off) | Encrypted keys; `bzpso-s1:` (sodium) / `bzpso-o1:` (OpenSSL) mark the cipher format – changing them makes saved keys undecryptable |
| `_bzpso_original` | post meta | Backup taken before the first apply |
| `_bzpso_optimized`, `_bzpso_optimized_by` | post meta | Last apply time and user |
| `bzpso_lock_{user}`, `bzpso_rate_{user}` | transients | Concurrency lock, hourly limit |
| `bzpso_job_{id}` | transient (timeout + 1 h) | Background job state and result |
| `bzpso_claim_{id}` | option (deleted when the job ends) | Atomic "this job is running" claim |
| Action Scheduler `bzpso_run_job` | group `post-seo-optimizer` | Backup starter for jobs |
| `bzpso_gemini_nothink_{md5(model)}` | transient (1 week) | Gemini model rejected `thinkingLevel` |

## Checks before handing over changes

```bash
# Syntax (also run with a PHP 7.4 binary if available)
for f in $(find . -name "*.php"); do php -l "$f"; done
node --check assets/js/admin.js && node --check assets/js/settings.js

# Coding standards + PHP 7.4 compatibility (composer require wp-coding-standards/wpcs phpcompatibility/phpcompatibility-wp)
phpcs --standard=WordPress --extensions=php --runtime-set text_domain post-seo-optimizer --runtime-set prefixes "bzpso,BZPSO" .
phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.4- --extensions=php .
```

Expected WPCS result: only 3 warnings about WooCommerce capabilities `assign_product_terms` / `manage_product_terms` (valid, registered by WooCommerce).

**End-to-end testing** (token optimization, 2026-10-08: 23/23 passing incl. field selection, usage display, Bangla apply/restore; background version, 2026-10-08: 76/76 passing incl. a 75 s answer, reload-resume, busy fallback, blocked loopback → Action Scheduler, job security; uninstall test passing; test site deleted afterwards): WordPress + SQLite drop-in on `php -S`, WooCommerce + Yoast, a must-use plugin hooking `pre_http_request` to fake provider responses (modes: ok, bn, evil HTML, garbage, fenced JSON, truncated, slow, busy_primary, 401/404/429/500/503, timeout; switch to block the worker loopback), and Playwright (`playwright-core`, Edge channel) driving the real UI, plus WP-CLI for database assertions. Gotchas:
- **Don't build the test site under `%TEMP%`** on the owner's PC: something deletes thousands of files there within minutes. Use a short path outside Temp (e.g. `E:\Web Work File\Personal\bazarpati.com\_t`, longest path ~207 chars) and delete it afterwards.
- **Copy the plugin into the test site, never link it** (a junction let a cleaner reach the real source).
- `php -S` is single-threaded: route worker loopbacks to a second `php -S` on another port (done in the mock) so polls are answered while a job runs; allow long navigation timeouts.
- WordPress's "Take over" edit-lock dialog appears when two test users open the same product.

## Status / next steps

- Not yet tested against the real Gemini API (speed and quality of English/Bangla copy) or on the live server/theme.
- Possible future work if the owner asks: auto-optimize hook for the custom importer, bulk mode, Rank Math support.
