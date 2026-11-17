=== Yablonsky SEO — GEO & AEO ===
Contributors: yablonsky
Tags: seo, aeo, geo, schema, woocommerce
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 5.87.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SEO, GEO & AEO in one WordPress workspace: metadata, Schema, sitemaps, AI discovery controls, WooCommerce, internal links and more.

== Description ==

**Be found. Be understood. Be the answer.**

Make technical SEO easier to operate while preparing public content for search and AI discovery.

**Yablonsky SEO — GEO & AEO** unifies SEO, structured content and AI discovery in WordPress. Core SEO works locally without an AI subscription; optional AI assistance is available when requested.

= One plugin. Three complementary visibility layers. =

* **SEO — Search Engine Optimization:** Control titles, descriptions, indexing, canonicals, sitemaps, structured data and technical discovery.
* **GEO — Generative Engine Optimization:** Prepare public content and machine-readable context for generative discovery. Here GEO means *generative*, not geographical.
* **AEO — Answer Engine Optimization:** Assess clarity, content structure and answer-readiness so visitors and compatible search experiences can understand your pages.

No plugin guarantees rankings, AI citations or placement in Google AI Overviews or AI Mode.

= Search appearance and on-page SEO =

* Edit SEO titles, meta descriptions, canonical URLs, robots directives and social sharing metadata.
* Review content optimization, readability and answer-readiness signals in the SEO editor.
* Manage post and page metadata at scale with the Bulk SEO Editor.
* Protect private and password-protected content from public SEO output.

= Technical SEO and discovery =

* Build XML sitemaps for eligible public content, with support for relevant image, video and news discovery workflows.
* Manage redirects and investigate unresolved URLs in the 404 Monitor.
* Improve internal-link discovery with indexing, analysis and suggested link opportunities.
* Configure breadcrumbs, canonical behavior, crawl directives and other search presentation controls.
* Optionally notify participating engines through IndexNow when enabled by an administrator.

= Schema, rich context and structured content =

* Generate and manage Schema.org / JSON-LD graphs for supported WordPress content.
* Support breadcrumbs, page identity, local business context, media and commerce data where applicable.
* Keep structured information tied to the actual content and business facts on your site.

= GEO, AEO and AI crawler policies =

* Audit AEO readiness and review machine-readable discovery signals.
* Configure crawler preferences for search discovery, AI use and model-training contexts where providers document relevant controls.
* Manage an independent OAI-AdsBot preference, plus documented inheritance and robots interoperability.
* Offer optional local Markdown and `llms.txt`-oriented resources derived from eligible published content, not protected pages.
* Keep bot policy, bot authenticity and request performance distinct: Yablonsky Security and Yablonsky Performance are optional separate products, not requirements.

= WooCommerce and international SEO =

* Work with WooCommerce product information, product discovery and commerce-feed tools.
* Review product discovery compatibility.
* Provide language-aware URL and `hreflang` behavior when an explicit compatible multilingual language provider is available.
* Use the English reference interface and translated resources for 35 additional locale packages; language updates may also be distributed through WordPress.org.

= Analytics, operations and safe migration =

* Explore locally aggregated organic landing signals in Search Performance, without requiring a Google account.
* Optionally consult authorized Search Console information through the Google Site Kit integration.
* Review diagnostics and SEO task lists.
* Assess supported competing SEO providers in safe Shadow mode before switching public output authority.
* Preview migration and backup evidence before controlled handover; existing source-provider data is not silently rewritten.

= Optional AI assistance, not a subscription requirement =

Content, metadata and image workflows can optionally use Yablonsky AI. Core SEO works without an AI key; review generated results.

**Learn more:** [Yablonsky SEO product overview](https://yablonsky.io/seo/) | [Yablonsky.io](https://yablonsky.io/) | [Source code and issue tracker on GitHub](https://github.com/yablonsky-io/yablonsky-seo)

== Installation ==

1. Go to Plugins > Add New > Upload Plugin and select the Yablonsky SEO ZIP.
2. Activate Yablonsky SEO and open Settings.
3. Configure indexing, metadata, Schema, sitemaps and crawler policies for your public content.
4. If a supported SEO provider is already active, review Shadow mode, migration evidence and backup before enabling YSEO as public SEO authority.
5. Enable optional services only when needed.

== Frequently Asked Questions ==

= Is the core plugin free? Do I need an AI account? =

The core local SEO functions work without a Yablonsky AI subscription or API key. Remote AI compute is optional and requires a separately configured API key.

= Will this make my pages appear in Google AI Overviews or AI Mode? =

No plugin can guarantee indexing, ranking, AI citations or placement. YSEO manages SEO foundations and discovery signals.

= Can I migrate from another SEO plugin? =

Supported migrations use a preservation-first workflow. Check provider compatibility, review an inventory and backup, and change public SEO authority only when you are ready.

= Does local processing mean I can target only my home country? =

No. Local processing describes work done inside your WordPress installation. YSEO supports international sites; geographic business information should reflect real-world facts.

= Does YSEO include 36 interface languages? =

English and 35 bundled translation packages are included. Native-speaker review varies.

= Where can I report a bug or read the source code? =

Use the [Yablonsky SEO GitHub repository](https://github.com/yablonsky-io/yablonsky-seo) for source and issues, or see the [official product page](https://yablonsky.io/seo/) for the product overview.

== Screenshots ==

1. Dashboard: the central Yablonsky SEO workspace and visibility overview.
2. SEO, AEO and GEO readiness checks and prioritized signals.
3. Search appearance controls for WordPress content.
4. On-page SEO analysis and optimization guidance.
5. Readability and content quality analysis.
6. WooCommerce product optimization and bulk workflows.
7. Page-level SEO signals and filters.
8. Post-level SEO signals and filters.
9. Search Performance and organic discovery indicators.
10. Metadata inventory and operational SEO controls.
11. Bulk SEO Editor for managing fields across many pages.
12. Local business and geographic discovery tools.
13. Commerce feeds and product discovery information.
14. 404 Monitor, statistics and recovery operations.
15. International visibility and language coverage.
16. Internal-link analysis and suggested opportunities.
17. Redirect Manager and advanced rule filters.
18. Configuration and site-wide SEO preferences.

== External Services and Privacy ==

**Core local SEO:** No remote account is required; optional services are administrator-controlled.

= Yablonsky AI (optional remote AI compute) =

When an administrator requests a configured AI workflow, YSEO contacts `https://ai.yablonsky.io/api`. Requests may include the selected operation/model, relevant SEO fields and bounded content, an image brief for requested generation, and the key through an `X-Api-Key` header. Responses are reviewed before saving; generated images can be copied to WordPress Media Library. The credential is kept server-side.

Service: https://ai.yablonsky.io/
Terms: https://ai.yablonsky.io/policies/terms
Privacy: https://ai.yablonsky.io/policies/privacy

= IndexNow (optional URL notifications) =

When enabled, public canonical URL changes may be submitted asynchronously through `https://api.indexnow.org/indexnow` with host, URL list and protocol key/key-file URL. Protected content and AI credentials are not included.

Service: https://www.indexnow.org/
Terms and privacy (Privacy section): https://www.indexnow.org/terms

= OpenStreetMap (optional explicit map embed) =

The `[yseo_location_map]` shortcode embeds an OpenStreetMap iframe only when deliberately added to content with valid coordinates. The visitor's browser then contacts OpenStreetMap and sends standard network information. Installing YSEO alone does not load the map.

Service: https://www.openstreetmap.org/
Privacy: https://osmfoundation.org/wiki/Privacy_Policy
Terms: https://osmfoundation.org/wiki/Terms_of_Use

= Google Preferred Sources (user-clicked link) =

The `[yseo_preferred_source]` shortcode creates a user-clicked link to Google with the site host in a query parameter; no Google scripts or server requests are initiated by the shortcode.

Service: https://developers.google.com/search/docs/appearance/preferred-sources
Privacy: https://policies.google.com/privacy
Terms: https://policies.google.com/terms

= Google Site Kit (optional authorized reporting) =

If Site Kit is installed and authorized, an administrator can request bounded Search Console data and supporting Analytics metrics where available. YSEO uses Site Kit's permission-gated interface and does not store Google OAuth tokens.

Service: https://sitekit.withgoogle.com/
Privacy: https://policies.google.com/privacy
Terms: https://policies.google.com/terms

= Commerce platform reference links (no automatic external service calls) =

The platform-directory `setup_url` and `docs_url` entries (such as Prisjakt and Skroutz) are documentation links presented to administrators who choose a marketplace. Merely opening an SEO or commerce page does not transmit site data to those documentation services. The administrator may follow a documentation link manually, at which point their browser communicates directly with that destination under its own policies. Commerce feeds and submissions require separately configured workflows.

== Changelog ==

= 5.87.1 =
* Correct inactive date-filter placeholders in Internal Links and 404 Diagnostics for strict MySQL DATETIME validation. Preserve prepared SQL queries, filtering, and existing data.


= 5.86.9 =
* Document the validated nested-form sanitation boundary and array-argument SQL preparation for automated code review without modifying runtime behavior.
* Preserve v5.86.8 functionality and compatibility while preparing for a fresh WordPress.org release gate.


= 5.86.8 =
* Make 404 Monitor and Internal Links query templates fully static and prepared while retaining the existing filters, indexed sort order and pagination.
* Ensure nested administrative form inputs are sanitized at their request boundary without changing their saved field formats.
* Keep explicit nonce/capability checks for 404 bulk actions and document the one-time sitemap table bridge database queries.
* Preserve verified migration, rollback state, settings, translations and public SEO output contracts.

= 5.86.7 =
* WordPress.org manual-review corrections: nonce origin checks, typed input boundaries, output validation, prepared SQL filters and compatibility-preserving runtime identifier normalization.
* Preserve previous Yoast migration data and validated rollback evidence while upgrading legacy option names.

= 5.86.6 =
* Correct WordPress Plugin Check input-validation warnings in bulk SEO, social templates, internal links, redirects and frontend inspector without losing nested field formats.
* Respect WordPress query filters when resolving public author resources for headless SEO.
* Retain verified Yoast 28.3 migration and rollback compatibility, existing REST v1 contracts and localized interfaces.

= 5.86.5 =
* Validate incoming admin, editor and runtime fields at their request boundary; preserve JSON, CSV, URL patterns and multiline metadata with schema-specific validation.
* Require a genuinely public, non-password-protected post before exposing an author archive through public Headless SEO APIs.
* Preserve verified Yoast 28.3 migration and rollback compatibility, all translated interfaces and existing REST v1 contracts.
* Add a packaged SHA256 manifest for support provenance.

= 5.86.4 =
* Harden public headless author projection to hide users without published searchable content. Sanitize unsaved editor analysis HTML at the request boundary. Preserve the verified Yoast 28.3 migration workflow and existing public API version.

= 5.86.3 =
* WordPress.org review corrective release: harden nested shortcode output escaping, clarify optional external-service disclosures, and replace unchecked checkbox input reads with presence checks. Retains validated Yoast 28.3 migration support and rollback safeguards from 5.86.2.

= 5.86.2 =
* Corrective Yoast migration compatibility: explicitly support Yoast SEO 28.3, ignore only known non-output user interface flags, and classify disabled optional Schema Aggregator endpoint as default-equivalent. Keep enabled or unknown semantic fields blocking.

= 5.86.1 =
* Corrected Plugin URI to the official product landing and expanded the WordPress.org product description. No functional behavior changes.

= 5.86.0 =
* Introduced a separate OAI-AdsBot preference with documented policy inheritance and crawler evidence updates.
* Preserved previous search-crawler and robots behavior.
* Consolidated SEO, discovery, localization and admin workflow refinements.

Further release history is recorded in the publicly available GitHub repository.

== Upgrade Notice ==

= 5.86.9 =
Static-analysis clarification only; no runtime or storage changes from 5.86.8.


= 5.86.8 =
Compatibility-preserving WordPress Plugin Check corrections; original SEO content, migration state and settings are retained.

= 5.86.7 =
Compliance and security corrections with lossless migration-state compatibility.

= 5.86.6 =
Plugin Check input-boundary and public-author query corrections; existing settings, metadata and migration state are preserved.

= 5.86.5 =
Input-boundary safety and public author-archive privacy refinements. Existing SEO settings, metadata and Yoast 28.3 migration state are preserved.

= 5.86.4 =
Public SEO projection safeguards and input processing hardening; retains the verified Yoast 28.3 migration.

= 5.86.3 =
Corrective security and documentation refinements; includes the verified Yoast 28.3 migration workflow from 5.86.2.

= 5.86.1 =
Documentation and plugin-header compliance updates; functionality unchanged.

= 5.86.0 =
Crawler policy and administrative improvements. Review your crawl settings after updating.
