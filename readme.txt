=== Yablonsky SEO — GEO & AEO ===
Tags: seo, aeo, geo, schema, woocommerce
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 5.86.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SEO, GEO & AEO for search and AI discovery. Prepare your site for Google Search, AI Overviews, AI Mode and answer engines.

== Description ==

**Be found. Be understood. Be the answer.**

Yablonsky SEO — GEO & AEO unifies three complementary visibility layers for WordPress:

* **SEO — Search Engine Optimization:** Search metadata, crawl controls, sitemaps, canonical URLs, internal links and structured data.
* **GEO — Generative Engine Optimization:** Clear machine-readable content and context for generative AI discovery. GEO means *generative*, not geographical restriction.
* **AEO — Answer Engine Optimization:** Structured information and useful answers for answer-oriented search experiences.

Google AI Overviews and AI Mode are search experiences operated by Google. YSEO supports foundational SEO practices; **no plugin can guarantee indexing, ranking, inclusion, citation or placement** in Google's or other AI systems' results. YSEO does not claim privileged Google access.

**Selected capabilities**

* Metadata and social previews, JSON-LD Schema, breadcrumb controls, sitemaps for public content, images, video and news, and XML/commerce feeds.
* Indexability controls, redirects and 404 diagnostics, internal-link analysis, technical site checks and a resource-aware SEO editor.
* Optional on-site Markdown resources and `llms.txt` orientation for AI crawlers, with published-content and password-access protections.
* WooCommerce product truth and feed workflows; international URLs and `hreflang` from an explicit language provider such as Yablonsky Multilingual.
* Search Performance: local, aggregated organic landing signals with responsive charts; optional Google Site Kit bridge for authorized Search Console evidence.
* Preservation-first SEO migration from supported providers, separate review drafts for AI assistance and a configurable public SEO authority. A competing supported SEO provider triggers safe Shadow mode.
* 36 interface languages: the English original and 35 bundled, structurally complete PO/MO packs. Native-language editorial review is separate from technical coverage.
* Optional Yablonsky AI actions for substantial remote AI compute. All core local functions are free and work without an AI key.

Local processing means work performed in your own WordPress installation; it **does not limit visibility to the country where the business is registered**. Geographic targets and location Schema are configured only where they reflect the site's real audiences and business facts.

== Installation ==

1. In WordPress, open Plugins > Add New > Upload Plugin and upload the Yablonsky SEO ZIP.
2. Activate the plugin. Stored YPS-prefixed SEO metadata remains compatible with the canonical `yablonsky-seo/` plugin identity.
3. Review Yablonsky SEO > Dashboard and Settings before enabling public SEO authority or optional external services.
4. If another supported SEO provider is active, keep YSEO in Shadow mode until you have reviewed its migration evidence and backup.
5. Configure sitemaps, metadata, language ownership and optional services for your site's needs.

== External Services and Privacy ==

**Core SEO operation** does not require an account or phone-home traffic. Optional services and data transfers are described below. The site owner decides which features to enable; visitor-facing map content requires an explicitly placed shortcode.

= Yablonsky AI (optional remote AI compute) =

When an administrator supplies an AI User API key and requests an AI action or configures a bounded workflow, YSEO contacts `https://ai.yablonsky.io/api`. Requests can include the selected operation/model, bounded content and relevant SEO fields from the current WordPress workspace, an image brief/aspect ratio for requested image generation, and the account API key in an `X-Api-Key` header. Results are reviewed before saving; generated images are copied to the local WordPress Media Library. The API credential is stored server-side and not exposed in public HTML. No AI account is needed for local SEO features.

Service: https://ai.yablonsky.io/
Privacy: https://yablonsky.io/privacy-policy/

= IndexNow (optional search indexing notifications) =

Disabled by default. When an administrator enables IndexNow while YSEO owns public SEO authority, YSEO can queue public canonical URL changes and submit bounded batches asynchronously via WP-Cron to `https://api.indexnow.org/indexnow`. Data includes the public site host, public URLs and the IndexNow protocol key/key-file URL. Page bodies, private content, AI credentials and visitor profiles are not submitted. The protocol may distribute URL notifications to participating search engines.

Service: https://www.indexnow.org/
Terms/privacy: https://www.indexnow.org/terms

= OpenStreetMap (optional map embed) =

The `[yseo_location_map]` shortcode embeds an OpenStreetMap iframe only when a content author explicitly places it with valid coordinates. The visitor browser then contacts OpenStreetMap and shares normal network request information such as IP address and user agent. No map request occurs merely from activating YSEO or rendering ordinary SEO metadata.

Service: https://www.openstreetmap.org/
Privacy: https://osmfoundation.org/wiki/Privacy_Policy
Terms: https://osmfoundation.org/wiki/Terms_of_Use

= Google Preferred Sources (user-clicked link) =

The optional `[yseo_preferred_source]` shortcode renders a normal link to Google's Preferred Sources selector. A reader's click navigates to Google with the current public site hostname in the `q` parameter. The shortcode itself makes no Google server-side request and loads no Google script.

Information: https://developers.google.com/search/docs/appearance/preferred-sources
Privacy: https://policies.google.com/privacy
Terms: https://policies.google.com/terms

= Google Site Kit (optional authorized reporting bridge) =

YSEO's native Search Performance works without Site Kit. If Google Site Kit is installed and authorized, an administrator may manually refresh a bounded Search Console report (and supporting Analytics metrics where available) through Site Kit's permission-gated WordPress interface. YSEO does not obtain or store Google OAuth access/refresh tokens. Site Kit supplies the Google-service connection; Google's own service terms and policies apply.

Site Kit: https://sitekit.withgoogle.com/
Privacy: https://policies.google.com/privacy
Terms: https://policies.google.com/terms

== Frequently Asked Questions ==

= Do I need an AI key or an account? =

No. Local SEO, sitemaps, metadata, Schema, redirects and diagnostics work without a remote account. A Yablonsky AI User API key is optional for requested AI compute; it does not license or unlock local plugin features.

= Does local processing mean local SEO only? =

No. YSEO supports international search audiences. On-site processing describes where computations occur, not where a business can compete or be discovered. Local SEO and region-specific landing pages are optional.

= Can I use YSEO alongside Yoast, Rank Math or another SEO provider? =

Yes, in safe Shadow mode for supported providers. Review migrated metadata and the independent backup/rollback evidence before changing which plugin controls public SEO output. Migration does not rewrite source-provider records.

= Are Markdown/llms.txt pages generated by a remote AI service? =

No. They are generated locally from saved public WordPress content, and protected content is excluded from public machine resources. Optional AI content operations are separate and explicitly requested.

= Do 36 languages include user interface translations? =

Yes: the English source plus 35 bundled PO/MO translation packs. The current catalog has 2,385 gettext entries; structural coverage is complete. Native-speaker wording and live-layout reviews are separate validation layers.

= Does YSEO guarantee inclusion in Google AI Overviews or AI Mode? =

No. Search engines control inclusion and presentation. YSEO provides technical foundations, structured context and workflows that help make content discoverable and understandable.

== Changelog ==

= 5.86.0 =
* Add separate OAI-AdsBot policy with safe inheritance, multilingual UI strings, and crawler evidence registry.
* Preserve prior crawler and robots.txt policy behavior.


= 5.85.1 =
* Clarifies pre-prepared 404-retention SQL for code analysis and sanitizes DOCUMENT_ROOT before checking private-cache location.

= 5.85.0 =
* Enforces verified owner-only permissions for cached AI documents; uncached fallback on restricted hosts.

= 5.84.11 =
* Stores AI-document caches outside public uploads and clears old cache payloads.

= 5.84.10 =
* Clears cached AI documents before post deletion; respects WordPress robots.txt privacy setting.

= 5.84.9 =
* Hides password-protected or nonpublic front/posts-page SEO metadata from public headless endpoints.

= 5.84.8 =
* Isolates Multisite AI-document caching and Elementor text-analysis caching by site.

= 5.84.7 =
* Streamlines WordPress.org readme.

= 5.84.6 =
* Prevents password-protected post URLs from entering or remaining in outbound IndexNow notifications.

= 5.84.5 =
* Requires administrator permission for Abilities sitemap diagnostics and excludes HEAD requests from organic entrance counts.






Full historical technical changes are recorded in the bundled `CHANGELOG.md`.

== Upgrade Notice ==

= 5.85.1 =
Improves WordPress Plugin Check compatibility without changing existing settings or features.

= 5.85.0 =
Verifies private AI-document cache permissions and disables disk caching if unavailable.

= 5.84.11 =
Moves AI-document cache files out of publicly accessible uploads.

= 5.84.10 =
Corrects AI-document cache cleanup and site indexing directives.
