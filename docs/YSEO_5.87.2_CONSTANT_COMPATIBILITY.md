# YSEO 5.87.2 — constant compatibility verification

**Scope:** Released product identity, historical plugin handoff, and regression evidence.  
**Baseline:** [v5.87.2](https://github.com/yablonsky-io/yablonsky-seo/releases/tag/v5.87.2).  
**Disposition:** Test/documentation only; do not alter or republish the existing installer.

## Functional mapping

| Historical short global | Canonical active global | Role |
| --- | --- | --- |
| `YPS_VERSION` | `YSEO_VERSION` | Current release version |
| `YPS_FILE` | `YSEO_FILE` | Main plugin file (activation/deactivation hook identity) |
| `YPS_DIR` | `YSEO_DIR` | Absolute plugin directory for includes and language packs |
| `YPS_URL` | `YSEO_URL` | Public URL base for bundled resources |

The four canonical globals are defined in the shipped `yablonsky-seo.php` before other YSEO code is loaded. Internal product code uses the canonical names. The short-prefix aliases formerly defined by this new plugin were removed to meet the WordPress.org review's distinctive-prefix request. **Removal of an alias is not deletion of its value or its functionality.**

## Preserve historical product identity safely

The bootstrap still checks an *already-defined* `YPS_FILE` when the separate historical `yablonsky-performance-seo` plugin is active. In that case the new plugin registers the technical-identity activation handoff and returns before importing its autoloader, avoiding duplicate registration. The handoff records the historical and canonical plugin basenames and the manual-deactivation state. It **does not define, overwrite or delete** the historical `YPS_FILE`.

Historical `_yps_*` post/user/term metadata, old plugin options, sitemap table handling and cron cleanup follow their separate compatibility contracts. See `src/Core/LegacyMetaBridge.php` and the source-controlled v5.87.2 regression results.

## Verification

- **[Published YSEO 5.87.2 regression](https://github.com/yablonsky-io/yablonsky-seo/actions/runs/44049123749):** 39 WordPress functional assertions and 60 historical metadata compatibility assertions passed in PHP 8.3 + MySQL 8, after upgrading from 5.86.6; official Plugin Check returned no errors.
- **[WordPress review sniffs](https://github.com/yablonsky-io/yablonsky-seo/actions/runs/44049836301):** selected input/SQL/global prefix rules returned zero errors and warnings.
- **Standalone historical-identity contract:** `tests/legacy-constant-contract.php`, runnable with PHP 7.4 and PHP 8.3, tests the four canonical values and the historical-loaded activation path without changing site state.
- **Publication identity:** the plugin distribution source on the test/documentation branch must be byte-for-byte identical to the published `v5.87.2` Git tag, as enforced by the companion workflow.

## Explicit compatibility boundary

No change to code outside this repository is implied or guaranteed. External integrations which previously read `YPS_VERSION`, `YPS_DIR` or `YPS_URL` *from the new Yablonsky SEO plugin* must use the documented `YSEO_*` equivalents. Existing legacy-plugin detection via `YPS_FILE` continues to work when the original legacy plugin defines the constant. Repository code-search is evidence about indexed code, **not** proof that every third-party plugin or private customization is compatible.

Do not restore deprecated three-letter global declarations without a WordPress.org reviewer-approved compatibility exception. When an actual external integration break is documented, fix its canonical integration path with a targeted regression, preserving existing user data.
