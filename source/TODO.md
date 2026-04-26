# CapstoneNMS — TODO

Living checklist of work identified after the security audit + news-CMS feature
gap analysis. Items are listed in roughly the order they should be tackled.

## Security — do these first

### CRITICAL
- [ ] **2FA bypass via `livewire/update`** — `app/Http/Middleware/VerifyTwoFactor.php`
      currently exempts the entire `/livewire/update` path so the challenge
      form can submit, but every Filament resource (EditUser, SiteSettings,
      etc.) also POSTs through that path. A session-hijacked user who has not
      passed the challenge can drive any Livewire component directly. Fix by
      inspecting the snapshot's `memo.path` and only allowing components that
      belong to the challenge page itself.
- [ ] **Self-enrollment hijack** — `app/Filament/Pages/TwoFactorSetup.php`
      `mount()` auto-starts enrollment and `confirm()` saves the secret with
      no password re-auth. Anyone with a hijacked session of an unenrolled
      admin can bind their own device. Require a password field in the
      confirm form.

### HIGH
- [ ] **`disable()` requires neither password nor TOTP** — only a JS confirm
      dialog. Add password + current TOTP code requirement before disabling.
- [ ] **Token abilities self-mintable beyond role** — `app/Filament/Pages/ApiTokens.php`
      lets `author` mint `delete` tokens. Validate abilities server-side
      against a role→allowed-abilities map; reject `*`.
- [ ] **`is_featured` writable by authors via API** —
      `app/Http/Controllers/Api/ArticleController.php:88, 134`. Mirror the
      `status` strip for authors on `is_featured` in both `store()` and
      `update()`.
- [ ] **Stored XSS / SSRF via `image_url`** — same controller, lines 64/84.
      Replace `nullable|string|max:500` with `nullable|url:http,https|max:500`
      and reject path separators.

### MEDIUM
- [ ] **Hash recovery codes** at rest — currently stored as plaintext JSON
      protected only by Laravel's `encrypted` cast. `Hash::make` each on
      enrollment and `Hash::check` in challenge.
- [ ] **TOTP replay protection** — drop window from 2 to 1 (±30s) and persist
      `two_factor_last_used_ts` on the user; reject codes whose timestamp
      isn't strictly newer.
- [ ] **`/api/tags` unbounded scan** — currently flatmaps every published
      article's CSV tags on every request. Either denormalize tags into a
      table or add a 5-minute cache. Will be subsumed by the "tags as a real
      model" feature work below.
- [ ] **`SiteSettings.normalizeSettingValue` MIME re-check** — re-validate
      uploaded files server-side before storing; SVG with inline JS is the
      realistic exploit vector since favicon allows SVG.
- [ ] **Filament resource role gates** — `Comments`, `Pages`, `Categories`
      have no role checks. Add `canViewAny`/`canEdit`/`canDelete`
      (Categories/Pages → admin/editor only; Comments → editor+).
- [ ] **Role string casing** — `IndexController@postSignup` writes `'User'`,
      `GoogleController@handleGoogleCallback` writes `'user'`. Normalize to
      lowercase everywhere; add a model mutator that lowercases on set.

### LOW
- [ ] **CSP header** — add `base-uri 'self'`; consider `frame-ancestors 'self'`
      already covered by X-Frame-Options.
- [ ] **`maintenance_secret` field is dead** — remove from `SiteSettings.php`
      until the artisan-down toggle is rewired to it (or rebuild the toggle).
- [ ] **`.env.example`** has `APP_DEBUG=true`. Flip to `false`.

---

## P0 features — table stakes for any news site

- [x] **Editorial workflow states** — `editorial_status` column with values
      draft / in_review / scheduled / published / unpublished / archived.
      `published_at` and `unpublished_at` timestamps. `News::published()`
      scope. Legacy `status` int auto-mirrored on save for back-compat.
- [x] **Scheduled publishing & un-publishing** — `php artisan news:publish-due`
      runs every minute via the Laravel scheduler. Flips
      `scheduled` → `published` once `published_at` arrives, and
      `published` → `unpublished` once `unpublished_at` arrives.
- [x] **JSON-LD `NewsArticle`** structured data emitted in
      `details.blade.php` head, with publisher/author/date/section/image.
- [x] **OpenGraph + Twitter Card meta** in `layouts/app.blade.php`:
      `og:type`, `article:published_time`, `article:modified_time`,
      `article:section`, `article:author`, `twitter:card=summary_large_image`.
- [x] **RSS / Atom / JSON feeds** at `/feed.xml`, `/feed`, `/feed.json` via
      spatie/laravel-feed. News implements Feedable.
- [x] **SEO meta fields** — `meta_title`, `meta_description`,
      `canonical_url` on the news table; new "SEO" tab in the Filament
      form with helper text on character counts.
- [x] **Hierarchical categories** — `categories.parent_id` + Category
      `parent()/children()` relations + `descendantIds()` helper.
      `category_news` route now descends the tree so a section landing
      page includes articles in its sub-sections. CategoryForm has a
      parent select that filters out the current node and its descendants.
- [x] **Tags as a real model** — new `tags` table + `news_tag` pivot.
      Tag model with `findOrCreateByName()`. NewsForm uses Filament's
      relationship-aware multi-select with create-on-the-fly. Legacy CSV
      `news.tags` column kept for back-compat. `php artisan news:split-tags`
      backfills the pivot from the CSV column (idempotent, dry-run flag).
- [x] **Article view counter** — `NewsController@details` increments
      `views` once per session, with a UA-based bot filter that skips
      crawlers.
- [x] **Comment moderation states** — `comments.status` enum
      (pending/approved/spam/trash). Public submissions land in `pending`.
      Filament `CommentsResource` defaults to the pending filter and has
      per-row + bulk Approve / Spam / Delete actions. Frontend shows
      only `approved` comments.
- [x] **Responsive images foundation** — spatie/laravel-medialibrary
      installed and wired (News implements `HasMedia`, registers
      thumb/medium/large/hero WebP conversions on the `lead` collection).
      `partials/article-image.blade.php` renders `<picture>` + `srcset`
      from the media collection when present, otherwise falls back to the
      legacy `image` column.
      *Follow-up:* swap NewsForm's lead `FileUpload` to the
      `SpatieMediaLibraryFileUpload` component so editors upload directly
      into the `lead` collection (currently uploads still target the
      legacy column).

---

## P1 — standard for serious news sites

- [ ] Co-authors / multi-byline — `news_authors` pivot with role enum.
- [ ] Dek/subtitle, kicker, dateline columns on `news`.
- [ ] Related articles (manual + auto by category/tag overlap).
- [ ] Corrections / updates log per article (`news_revisions` table with
      `is_correction` flag, rendered in footer of article).
- [ ] Newsletter signup + send (Mailcoach or Mailgun + Laravel Notifications).
- [ ] Live blogs / rolling coverage — new `LiveBlog` model with `entries`.
- [ ] Breaking-news banner — `is_breaking` + `breaking_until` on `news`.
- [ ] Article image galleries (verify the existing `news_galleries` table is
      first-class on the form, with caption + credit per image).
- [ ] Image credit / caption / alt columns + `<figcaption>` rendering.
- [ ] Full-page caching for guest traffic (`spatie/laravel-responsecache`).
- [ ] Author profile pages with bio (`/author/{slug}`), public users columns.
- [ ] Search beyond `LIKE` — Laravel Scout + Meilisearch / Algolia.
- [ ] Push notifications (OneSignal) for breaking stories.
- [ ] Read time + scroll-depth tracking.
- [ ] Article revision history (`spatie/laravel-activitylog`).
- [ ] Audit `IndexController@sitemap_news` for proper Google News
      `<news:news>` namespace — 48-hour window, `<news:keywords>`, etc.

---

## P2 — differentiators

- [ ] Paywall / metered access (Cashier-Stripe + middleware).
- [ ] Ad slot manager with rotation/targeting (replaces raw HTML banners in
      settings).
- [ ] Multilingual content (Spatie translatable + hreflang).
- [ ] Fact-check / source citation blocks (`news_sources`,
      `fact_check_status`).
- [ ] Podcasts as first-class content type (polymorphic Episode model).
- [ ] Curated topic pages (Topic model with hero + manual pinning + auto-include rules).
- [ ] A/B headline testing with weighted variants.
- [ ] Editorial calendar view (Filament FullCalendar plugin).
- [ ] Geo-targeted variants (`news_geo_variants` + MaxMind GeoLite).

---

## Operational / infra

- [ ] **MANUAL: rotate MySQL password** in DreamHost panel + update prod
      `DB_PASSWORD` (still pending from the original audit).
- [ ] **MANUAL: harden prod `.env`** — set `SESSION_ENCRYPT=true`,
      `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`. The deploy
      excludes `.env` so this is a one-time SSH edit.
- [ ] Consider installing `mews/purifier` on prod for stricter HTML
      sanitization than the current inline `sanitize_rich_html` helper.
