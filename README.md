# Post SEO Optimizer

**AI-powered SEO rewriting for WooCommerce products – with full review before anything is saved.**

Products imported from dropshipping suppliers usually have copied, messy titles and descriptions ("2024 NEW TWS Earbuds Hot Sale!!! AliExpress…"). This WordPress plugin uses AI (Google Gemini, ChatGPT or Claude) to rewrite them into clean, unique, SEO-friendly content and fills in your **Yoast SEO** fields. You check and edit every suggestion before it is saved, and you can always restore the original.

Works in **English** and **Bangla (বাংলা)**.

---

## Contents

1. [What it does](#1-what-it-does)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [First-time setup (5 minutes)](#4-first-time-setup-5-minutes)
5. [How to optimize a product](#5-how-to-optimize-a-product)
6. [All settings explained](#6-all-settings-explained)
7. [Saving money (tokens)](#7-saving-money-tokens)
8. [Problems and solutions](#8-problems-and-solutions)
9. [Security and privacy](#9-security-and-privacy)
10. [Uninstalling](#10-uninstalling)
11. [For developers](#11-for-developers)

---

## 1. What it does

For **one product at a time**, the plugin can write:

| Field | Where it goes |
|---|---|
| Product title | WooCommerce product name |
| Short description | WooCommerce short description |
| Description | WooCommerce description (with headings, feature list and specification table) |
| URL slug | Product URL (old URL redirects automatically) |
| Image alt text | Main image and gallery images |
| Product tags | Added to the existing tags |
| Focus keyphrase | Yoast SEO |
| SEO title | Yoast SEO |
| Meta description | Yoast SEO |

**Main features**

- ✅ **Review first** – you see the current text next to the AI suggestion, can edit it, and choose which fields to apply.
- ✅ **One-click restore** – the original supplier content is backed up and can be restored at any time.
- ✅ **English and Bangla** – choose per product.
- ✅ **3 AI providers** – Google Gemini (default), OpenAI (ChatGPT), Anthropic (Claude).
- ✅ **Slow but accurate models are fine** – generation runs in the background and can take up to 10 minutes; your web server or Cloudflare time limits do not matter.
- ✅ **Busy-model protection** – if the AI is overloaded, the plugin retries and then switches to a backup (fallback) model automatically.
- ✅ **Low token cost** – generate only the fields you need, choose the description length and thinking level, and see the tokens used after each run.
- ✅ **Secure** – admin-only, encrypted API keys, AI output is cleaned before it can reach your site.
- ✅ **Fast** – nothing is loaded on your shop's front end.

---

## 2. Requirements

| | Minimum | Tested with |
|---|---|---|
| WordPress | 6.0 | 7.1.2 |
| PHP | 7.4 | 7.4 and 8.3 |
| WooCommerce | 7.0 | 11.1.2 |
| Yoast SEO | optional (recommended) | 28.6 |

You also need an **API key** from one AI provider (Google Gemini is the default and has a free tier).

---

## 3. Installation

1. On this GitHub page click **Code → Download ZIP**.
2. Unzip it on your computer. You get a folder like `Wordpress_Post_On_PageSEO_Optimizer-main`.
3. **Rename that folder to `post-seo-optimizer`** and zip it again (right-click → *Compress* / *Send to → Compressed (zipped) folder*).
   > Renaming keeps future updates working: WordPress will then replace the old version instead of installing a second copy.
4. In WordPress go to **Plugins → Add New Plugin → Upload Plugin**, choose your zip, click **Install Now**, then **Activate**.

**Updating later:** repeat the steps and choose **Replace current with uploaded** when WordPress asks. Your settings, API key and backups are kept.

---

## 4. First-time setup (5 minutes)

### Step 1 – Get an API key (Google Gemini)

1. Open <https://aistudio.google.com/apikey> and sign in with your Google account.
2. Click **Create API key** and copy it (it starts with `AIza…`).

> 💡 The free tier is fine for testing. For a live shop, turn on billing in Google AI Studio: you get higher limits, and Google does **not** use your product data to train its AI on the paid tier.

### Step 2 – Enter the key

1. In WordPress go to **WooCommerce → SEO Optimizer**.
2. **Active provider:** Google (Gemini).
3. Paste your key into the **Google (Gemini) → API key** box.
4. **Model:** for example `gemini-3.5-flash-lite` (cheapest) or `gemini-3.8-flash` (more capable).
5. Click **Save Changes**.

### Step 3 – Test

Click **Test connection**. You should see:
`Connected to Google Gemini using model …`

That's it – you're ready.

---

## 5. How to optimize a product

1. Go to **Products** and open a product (or click the **Optimize SEO** link under a product in the list).
2. Scroll down to the **AI SEO Optimizer** box.
3. Choose:
   - **Language** – *Same as the product*, *English* or *Bangla*.
   - **Focus keyphrase** (optional) – the search words you want to rank for. Leave empty and the AI chooses one.
   - **Extra instructions** (optional) – e.g. *"Mention it is suitable for kids"*.
   - **Generate** – tick only the fields you want written (fewer fields = lower cost).
4. Click **Generate SEO suggestions**.
   - You'll see *"Generating suggestions in the background… 25 s"*. Careful models can take a few minutes – keep the page open. If you reload, it continues automatically.
5. **Review the suggestions:**
   - Left: the current text. Right: the AI suggestion – **you can edit it**.
   - Character counters show if the length is good for SEO (red = outside the recommended range).
   - For descriptions, click **Show preview** to see how it will look.
   - Tick the **Apply** box for the fields you want to save.
6. Click **Apply selected** and confirm. The page reloads with the new content.
   - ⚠️ Save any manual changes in the product editor first – the page reloads.

**Undo:** in the same box click **Restore original content**. Everything goes back to how it was before the first optimization (title, descriptions, slug, Yoast fields, image alt texts, tags).

**See what's done:** the **Products** list has an **AI SEO** column showing *Optimized* for products you've already done.

---

## 6. All settings explained

Find them under **WooCommerce → SEO Optimizer**.

### AI provider

| Setting | What it does | Recommended |
|---|---|---|
| Active provider | Which AI company to use | Google (Gemini) |
| API key | Your secret key. Stored encrypted; never shown again after saving. Tick *Remove saved key* to delete it. | – |
| Model | The AI model name. If Google retires a model, just type the new name here. Current names: <https://ai.google.dev/gemini-api/docs/models> | `gemini-3.5-flash-lite` or `gemini-3.8-flash` |
| Fallback model | Used automatically when the main model is busy. Leave empty to turn off. | `gemini-3.8-flash` (or `gemini-3.7-flash`) |
| Test connection | Checks key + model (after saving) | – |

### Content

| Setting | What it does | Recommended |
|---|---|---|
| Default language | Pre-selected language on the product screen | Same as the product |
| Writing tone | Professional, Friendly, Persuasive, Premium or Simple | Professional |
| Description length | Short (150–250 words), Standard (250–400), Long (400–600) | Standard |
| Store name | Used in the instructions to the AI | your shop name |
| Target market / audience | e.g. *Online shoppers in Bangladesh* | fill it in |
| Extra instructions | Rules for every product, e.g. *"Mention cash on delivery is available"* | optional |
| SEO title | Adds your site name to the SEO title using Yoast variables | on |

### Fields

| Setting | What it does |
|---|---|
| Default fields to generate | Which fields are ticked by default on the product screen. URL slug and tags are off by default (changing the slug changes the product URL; the old URL redirects automatically). |

### Limits & data

| Setting | What it does | Recommended |
|---|---|---|
| Max output tokens | Upper limit for one answer. Raise it if you see *"response was cut off"*. | 8000 (16000 for High thinking) |
| Request timeout | How long to wait for the AI: 30–600 seconds (10 minutes) | 300–600 |
| Thinking level | How carefully Gemini 3+ thinks before writing: Low (cheapest), Medium, High (most careful, most expensive) | Low or Medium |
| Requests per user per hour | Protects your API budget. 0 = unlimited. | 30 |
| Uninstall | Also delete the original-content backups when the plugin is deleted | off |

> 🔒 **Even safer:** instead of saving the key in the settings, you can add it to your `wp-config.php` file:
> ```php
> define( 'BZPSO_GEMINI_API_KEY', 'AIza...your key...' );
> // or: BZPSO_OPENAI_API_KEY, BZPSO_ANTHROPIC_API_KEY
> ```

---

## 7. Saving money (tokens)

AI providers charge per **token** (about ¾ of an English word). Text the AI **writes** (including its "thinking") costs about **5× more** than text you send it. So:

1. **Untick fields you don't need** in the *Generate* row (biggest saving).
2. **Description length:** Short or Standard.
3. **Thinking level:** Low or Medium. Try a few products and compare quality.
4. Use a cheaper model such as `gemini-3.5-flash-lite`.

After every generation the box shows the real usage, for example:
`Tokens: 733 in, 850 out (incl. 210 thinking) · 512 input tokens cached (discounted)`

The plugin also saves tokens automatically: it removes repeated supplier lines, doesn't send unnecessary data (like the price), and sends the same rules every time so the provider can discount them (prompt caching).

---

## 8. Problems and solutions

| Message | What it means | What to do |
|---|---|---|
| *The model "…" was not found* | The model name is wrong or retired | Type the model name Google recommends in **Model** and save |
| *…rejected the API key* | Wrong or deleted key | Create a new key and paste it in the settings |
| *…rate limit or quota exceeded* | Free-tier limits reached | Wait a bit, or turn on billing in Google AI Studio |
| *…is very busy right now* / *high demand* | Google's servers are overloaded (temporary) | Try again in a few minutes, or set another **Fallback model** |
| *The AI response was cut off* | Answer was longer than allowed | Raise **Max output tokens** |
| *…did not answer within … seconds* | Model was too slow | Raise **Request timeout** (max 600) or lower the **Thinking level** |
| *The background task could not start* | Your server blocks requests to itself and WP-Cron is not running | Check **WooCommerce → Status → Scheduled Actions**; ask your host to allow "loopback requests" |
| *The background task stopped without an answer* | Your host stopped a long-running PHP process | Ask your host about PHP time limits for background requests |
| *You reached the limit of … AI requests per hour* | Your own budget protection | Wait, or raise **Requests per user per hour** |
| *Another AI request of yours is still running* | One job at a time per user | Wait for it to finish |

**Error details** are logged in **WooCommerce → Status → Logs** (source: `post-seo-optimizer`). The API key is never written to the logs.

---

## 9. Security and privacy

- Only logged-in users who may edit the product (Administrators, Shop Managers) can use it; settings need the *manage WooCommerce* permission.
- Every request is protected with WordPress security tokens (nonces).
- API keys are **encrypted** in the database and never shown on any page.
- AI output is treated as untrusted: only safe HTML (paragraphs, headings, lists, tables) is kept; scripts, links, styles and attributes are removed – both in the preview and when saving.
- Supplier text is sent to the AI as data only; the AI is told to ignore any instructions hidden in it.
- Nothing runs on your shop's front end.
- Product data is sent to the AI provider you choose. On Google's **free** tier it may be used to improve Google's products; on the **paid** tier it is not.

---

## 10. Uninstalling

Deactivate and delete the plugin in **Plugins**. It removes its settings, API keys, temporary data and scheduled tasks.

- Your optimized product content stays as it is.
- Original-content backups are only deleted if you ticked **Uninstall → Also delete the saved original-content backups** in the settings.

---

## 11. For developers

<details>
<summary>File structure, data stored and checks</summary>

```
post-seo-optimizer.php            Bootstrap: constants, autoloader, activation, HPOS compatibility
uninstall.php                     Cleanup on delete
includes/
  class-bzpso-plugin.php          Boots admin components (nothing on the front end)
  class-bzpso-settings.php        Settings page, options, encrypted API keys
  class-bzpso-crypto.php          Key encryption (libsodium, fallback AES-256-GCM)
  class-bzpso-metabox.php         "AI SEO Optimizer" box on the product screen
  class-bzpso-ajax.php            AJAX: generate, job_status, apply, restore, test_connection
  class-bzpso-jobs.php            Background jobs (loopback worker + Action Scheduler backup)
  class-bzpso-product-data.php    Source data, apply, backup, restore
  class-bzpso-prompt.php          Prompt (static rules + per-product request)
  class-bzpso-sanitizer.php       Parse and clean AI output and submitted values
  class-bzpso-seo-meta.php        Yoast SEO fields
  class-bzpso-rate-limiter.php    Per-user lock and hourly limit
  class-bzpso-admin-list.php      Products list column and row action
  providers/                      Gemini, OpenAI, Anthropic (+ shared base with retry/fallback)
assets/js/admin.js                Review screen (vanilla JS)
assets/js/settings.js             Test connection
assets/css/admin.css
readme.txt                        WordPress.org-style readme
CLAUDE.md                         Detailed technical notes for developers / AI assistants
```

**Data stored:** options `bzpso_settings`, `bzpso_api_keys` (both not autoloaded); post meta `_bzpso_original`, `_bzpso_optimized`, `_bzpso_optimized_by`; transients `bzpso_*`; Action Scheduler hook `bzpso_run_job`.

**Code checks used:**

```bash
php -l <file>                                    # syntax (PHP 7.4 and 8.x)
phpcs --standard=WordPress --extensions=php .    # WordPress Coding Standards
phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.4- --extensions=php .
```

See **[CLAUDE.md](CLAUDE.md)** for architecture decisions, security rules, gotchas and how the end-to-end tests were run.

</details>

---

## License

GPL-2.0-or-later – see <https://www.gnu.org/licenses/gpl-2.0.html>.
