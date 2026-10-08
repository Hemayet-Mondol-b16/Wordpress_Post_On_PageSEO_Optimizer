=== Post SEO Optimizer ===
Contributors: bazarpati
Tags: woocommerce, seo, yoast, ai, product description
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-assisted SEO rewriting for imported WooCommerce products, with review before apply and Yoast SEO integration.

== Description ==

Products imported from dropshipping suppliers usually have copied, keyword-stuffed or badly formatted titles and descriptions. Post SEO Optimizer rewrites them one product at a time with OpenAI (ChatGPT), Anthropic (Claude) or Google (Gemini). You review and edit every suggestion before anything is saved.

**What it optimizes**

* Product title
* Short description and full description (structured HTML: intro, features, specifications, benefits)
* URL slug (optional; WordPress redirects the old URL)
* Image alt text (main image and gallery)
* Product tags (added to the existing tags)
* Yoast SEO: focus keyphrase, SEO title and meta description

**Workflow**

1. Open a product, scroll to the **AI SEO Optimizer** box.
2. Choose the language (auto, English or Bangla), optionally enter a focus keyphrase or extra instructions, then click **Generate SEO suggestions**.
3. Compare current and suggested values, edit them, tick the fields you want, and click **Apply selected**.
4. The original content is backed up the first time. **Restore original content** brings it back at any time.

The Products list shows an **AI SEO** status column and an **Optimize SEO** row action.

**Security**

* Admin-only. Nothing runs on the storefront.
* Every request checks a per-product nonce and the user's permission to edit that product. Settings require `manage_woocommerce`.
* API keys are encrypted at rest (libsodium, or AES-256-GCM as a fallback) with a key derived from your wp-config.php salts, and are never printed back into the page. Defining them in wp-config.php is supported and recommended.
* AI output is treated as untrusted: it is parsed strictly, filtered to an allow-list of HTML tags without attributes, and sanitized again on save.
* Supplier content is passed to the AI as data with instructions to ignore any commands inside it.
* Per-user concurrency lock and hourly request limit.

**Performance**

* Zero front-end footprint; admin assets only load on the product edit screen, the product list and the settings page.
* Settings are not autoloaded.
* Source text is converted to plain text and truncated before it is sent, which keeps token usage low.
* Products are saved through the WooCommerce CRUD API, so caches and lookup tables stay correct.

== Installation ==

1. Upload the `post-seo-optimizer` folder to `/wp-content/plugins/` and activate it. WooCommerce must be active.
2. Go to **WooCommerce → SEO Optimizer**. Google Gemini (gemini-3.8-flash) is the default provider; add its API key from https://aistudio.google.com/apikey and save.
3. Click **Test connection**.

Optional, recommended: define API keys in `wp-config.php` instead of the database:

`define( 'BZPSO_OPENAI_API_KEY', 'sk-...' );`
`define( 'BZPSO_ANTHROPIC_API_KEY', 'sk-ant-...' );`
`define( 'BZPSO_GEMINI_API_KEY', '...' );`

== Frequently Asked Questions ==

= How long can the AI take? =

Generation runs **in the background**, so the AI can take up to the **Request timeout** (30 seconds to 10 minutes, default 5 minutes) without hitting web server, proxy or Cloudflare limits. The product page shows the elapsed time and picks the job up again if you reload. The background task is started by a request from your site to itself; if your host blocks that, WooCommerce's scheduler (WooCommerce → Status → Scheduled Actions) starts it instead, usually within a minute or two.

The **Thinking level** setting (Gemini 3 and newer) trades speed for accuracy: Low is fastest, Medium is balanced, High is most careful. With High, also raise **Max output tokens**.

= How do I keep token costs low? =

Output tokens (the text the AI writes, including its "thinking") cost several times more than input tokens, so the biggest savings come from asking for less output:

* **Generate** checkboxes on the product screen: only ticked fields are written. Untick what you don't need for that product.
* **Description length** (settings): Short, Standard or Long. The description is the largest part of every answer.
* **Thinking level** (settings, Gemini 3+): Low is cheapest; High can cost several times more.

The plugin also keeps input small automatically: supplier text is converted to plain text, repeated lines are removed, a short description already contained in the description is not sent twice, data that isn't needed (price, existing tags when tags aren't requested) is left out, and the rules are sent identically every time so providers can discount them with prompt caching. After each generation the review screen shows the tokens used, so you can see the effect of each setting.

= "The model is currently experiencing high demand" =

The AI provider is overloaded; this is temporary and not a problem with your key or settings. The plugin automatically retries once after 2 seconds and then switches to the **Fallback model** (for Gemini: gemini-3.7-flash by default), all within the request timeout. If both are busy, wait a few minutes and try again, or enter another model as fallback.

= "The AI response was cut off" =

Increase **Max output tokens** in the settings. Bangla text and reasoning models need more tokens.

= Which model should I use? =

Any chat model of the selected provider works. Smaller and faster models such as gpt-4.1-mini, gemini-3.8-flash or gemini-3.5-flash-lite are inexpensive. If Google reports that a model "is no longer available", enter the model it recommends in the Model field. Current model names: https://ai.google.dev/gemini-api/docs/models Larger models write better copy, especially in Bangla.

= What happens on uninstall? =

Settings and API keys are removed. Original-content backups are removed only if you enable that in the settings. Optimized product content is never touched.

== Changelog ==

= 1.0.0 =
* Initial release.
