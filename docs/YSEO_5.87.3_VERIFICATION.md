# YSEO 5.87.3 — corrective verification record

**Baseline:** published v5.87.2. **State:** draft, not approved for distribution. **Versioning:** YABLONSKY_PRODUCT_VERSIONING_CANON_v1.0.0_2026-09-21.md. This is a corrective PATCH only. Keep the existing release and packaged translations unchanged until all applicable acceptance gates pass.

## Completed source work

- Replace 105 ordinary scalar FILTER_CALLBACK boundaries with explicit type checks, wp_unslash and context-specific WordPress sanitizers; replace 17 byte-sensitive callbacks with explicit bounded validators; replace four array callbacks with typed leaf processing. The two bulk-row heuristic examples are also handled per expected scalar leaf. Preserve deliberately empty fields and do not clear existing metadata on malformed/omitted input.
- Preserve JSON, CSV, regex, percent-encoded URLs and HTML contracts; the existing schema/field validators remain responsible for decoded structured data. Do not blindly apply sanitize_text_field to encoded documents.
- Resolve commerce snapshots from wp_upload_dir only; unavailable uploads fail without a fallback write.
- New SEO migration backups use the resolved plugin uploads subtree, authenticated AES-256-GCM frames, non-autoloaded per-anchor database keys and owner-only filesystem modes. Historical verified anchors remain read-only at their original paths. An interrupted historical export cannot resume writes outside uploads; explicitly start a fresh export after a successful dry run. Never delete the original anchor or its key as part of routine reset.
- Agent document cache uses the resolved uploads subtree, authenticated encrypted bytes and per-site isolation. Existing public routes, body/ETag and cache invalidation semantics are preserved; unavailable storage/encryption renders uncached.
- Encrypted file contents protect confidentiality even when a host serves static bytes; this does not claim universal HTTP 403. Direct HTTP access protection and actual host compatibility must be validated. New backup creation requires available authenticated OpenSSL encryption; if unavailable, the operation blocks safely. Confirm this dependency against supported host configurations or implement an approved alternative before shipping.

## Executed local evidence

PHP 8.4.24; local isolated fixtures with small WordPress API stubs (not a real WordPress runtime). Changed source blobs reconstructed from locally available source and verified against the GitHub branch. All 15 changed input controllers and seven changed storage components plus InputValidation and plugin bootstrap passed PHP syntax. The isolated test matched the Git blob and returned **YSEO_T3_ISOLATED_PASS 30 assertions**: custom uploads, encrypted round-trip, physical offset retry, manifest integrity, legacy read-only anchor, tamper detection, atomic feed, cache HIT/ETag and site isolation, error handling and byte-sensitive input validators.

Reproduce on a checked-out branch using PHP CLI:

    php tests/t3-storage-contract.php
    find src -type f -name '*.php' -exec php -l {} \;
    php -l yablonsky-seo.php
    php tests/legacy-constant-contract.php

The standalone test does not exercise WordPress DB, actual HTTP requests, Plugin Check or full Yoast migration.

## Local / isolated WordPress acceptance still required

Run on dedicated disposable installations only, with WP_DEBUG and WP_DEBUG_LOG enabled, PHP 7.4 compatibility checks and a representative supported PHP/MySQL runtime. Do not run against a customer or production database. No GitHub-hosted compute is required. Build the package from the exact reviewed Git tree using the existing release allowlist: LICENSE, readme.txt, yablonsky-seo.php, uninstall.php, src, assets, data, languages, and SHA256SUMS.txt. The versioned filename must be yablonsky-seo-5.87.3.zip; no external publication yet.

1. Fresh install/activate/deactivate/reactivate; verify no YSEO WP_DEBUG warnings or fatal errors, all shipped locales retained, and exact version identity. Run Plugin Check on the installed candidate and relevant WPCS sniffs, recording real findings rather than suppressing them.
2. Upgrade from the historical 5.86.6 fixture (seed commands in the previous regression workflow) and from a representative 5.87.2 site. Run tests/review-regression-smoke.php and tests/prefix-compatibility-regression.php on the actual installed candidate using WP-CLI eval-file.
3. On the disposable site only, define YSEO_DISPOSABLE_TEST_SITE=true, then run wp eval-file tests/t3-wordpress-regression.php with the correct --path. This exercises real WordPress options, upload resolution, sealed encrypted backup and bulk input preservation. The test has been authored but not executed here.
4. Exercise actual Yoast Dry Run -> Backup Export -> verified anchor -> Shadow Import -> semantic parity -> manual authority transition -> Rollback. Verify source Yoast data, earlier verified anchors, current encrypted artifact retrieval, and failure/restart for interrupted historical jobs. Test real HTTP and nonstandard uploads, multisite and owner-only file modes, no plaintext exposure under Apache/Nginx, missing keys and crypto availability.
5. Exercise actual admin/AJAX form submissions: JSON import with schema validation, CSV/regex redirect import/edit, Unicode/quoted metadata, social image URLs, RSS templates, bulk rows/selected IDs, AI input and public request paths. Test authorized/unauthorized and malformed inputs. Confirm WPCS/Plugin Check outcomes on the exact ZIP and compare output against the accepted baseline.
6. Record the exact source commit and package SHA256; no merge, release, or WordPress.org submission until the evidence is reviewed and the remaining gates pass. Reply briefly to the existing review thread only after the corrected ZIP has been uploaded.

No new GitHub Actions workflow, release or installer is produced by this change.
