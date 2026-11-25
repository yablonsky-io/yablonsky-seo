# YSEO release checkpoint — 5.87.4

Date: 2026-11-25

Status: distribution candidate accepted for manual visual checks; source synchronization and release publication pending. This is a checkpoint, not a published release.

- Candidate: `yablonsky-seo-5.87.4.zip` (SHA-256 `38d443ef8bd32278dd62b921876ddb87caf247aedfbb887b0ef79da700bf7b06`).
- Version header, constant and stable tag: 5.87.4.
- Plugin Check: pass, as reported by product owner.
- Read-only installation audit: 283 manifest entries and 198 PHP syntax checks passed; seven isolated authenticated backup regression checks passed under PHP CLI 8.1. This does not establish PHP 7.4 or full WordPress integration coverage.
- Manual visual acceptance: metadata generation, settings and visible admin surfaces accepted by product owner. Commercial Feeds XML opens in Chrome; a Waterfox-specific opening issue was reported, cause unverified. Non-blocking observation; no code change approved.
- Pending: WPCS and PHP 7.4 compatibility evidence, exact source synchronization to main and matching tag/release asset. No hosted GitHub compute.
- Versioning reference: `YABLONSKY_PRODUCT_VERSIONING_CANON_v1.0.0_2026-09-21.md`.

Preserve existing accepted behavior and locale catalogs. No new version solely for an unconfirmed browser-specific observation. Do not substitute an older repository snapshot for the accepted archive. Do not publish private diagnostic evidence.
