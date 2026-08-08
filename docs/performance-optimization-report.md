# Performance optimization report

WordPress core, trunk, `src/` tree. Base commit `5e9d05d7dd`.

This document records five delivered optimizations, the measurements that justified each one, the six
performance targets those measurements are read against, the work that was considered and rejected on
measurement, and the gaps that remain. Every figure below comes from one retained before/after pair or
from a named isolated experiment; nothing is estimated, and nothing is carried over from an earlier
revision of this document.

**Two of the six targets are met. Four are not.** The unmet four are unmet for reasons that are measured
rather than argued, and each one is priced: §_Why four targets are not met_ names the pool that would have
to move, how large it is, and which constraint blocks it.

---

## How to read the numbers

### Percentages are relative to the _before_ value

A reduction from 510 to 408 is reported as **−20.00 %**, because 102/510 = 20.00 %. Dividing by the after
value instead would report −25.00 % for the same pair, which is the difference between meeting a 20 %
target and missing it. Every percentage in this document divides by the before value.

### The opcode cache decides the magnitude of everything else

Opcode-cache state moves measured wall time and memory by far more than any change under test, so a
before/after pair taken across two regimes reports the regime rather than the change. Six rules follow,
and every measurement in this document obeys them:

1. Both arms use bit-identical interpreter flags, in the same containers, on the same code path.
2. The regime is reported alongside the figures — see §_Measurement environment_.
3. php-fpm is restarted between code states, so no worker generation is shared. This is not theoretical:
   a worker that keeps a compiled file in memory reports the _old_ file count after the source has been
   replaced, which reads as "no regression" and is not one.
4. Peak memory is read with `memory_get_peak_usage( false )`. The `true` variant quantizes to the
   allocator chunk size and is useless against a 10 % target.
5. Every reported figure is a median over at least 40 samples.
6. Each measured request is preceded by an authorized `POST /?clear_cache` that calls `opcache_reset()`,
   so every sample in the suite is a cold-compile sample in the php-fpm regime.

### A proxy metric is not a cost metric

`get_included_files()` counts files; it does not price them. The distinction is load-bearing here, and
this change set contains its own proof in both directions:

-   On the canonical homepage, 102 fewer files come with **−6.03 %** peak memory and **−16.67 %** bootstrap
    time, so on that request the count and the cost move together.
-   On the Single Post scenarios, which land in a warm-compile regime (`wpTotal` ≈ 70 ms against the
    homepage's ≈ 470 ms), the same deferral removes **20.53 %** of the files and **costs** 6.22 % of
    `wpTotal` and 2.77 % of bootstrap time, because the files were already compiled and only the
    autoloader's per-resolution work is left.

So the file-count target is claimed from the count directly, memory and time are claimed only from their
own measurements, and the two are never inferred from each other.

---

## Measurement environment

| Component           | Value                                                                                                                                                                                                                                                                                                                                                                 |
| ------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Server              | nginx 1.31.3 → php-fpm 8.5.9, docroot `/var/www/src`, base URL `http://localhost:8889`                                                                                                                                                                                                                                                                                |
| Database            | MySQL 8.4.11                                                                                                                                                                                                                                                                                                                                                          |
| Interpreter regime  | `opcache.enable=On` (php-fpm SAPI), `opcache.enable_cli=Off`, `opcache.jit=disable`, `opcache.jit_buffer_size=64M`, `opcache.memory_consumption=128`, `opcache.validate_timestamps=On`, `opcache.revalidate_freq=2`. Read from `php -i` inside the php container and recorded in `artifacts/qa-logs/R01-canonical-pair.log`                                           |
| Debug flags         | `WP_DEBUG=false`, `WP_DEBUG_LOG=false`, `WP_DEBUG_DISPLAY=false`, `SCRIPT_DEBUG=false`, `SAVEQUERIES=false`, `WP_DEVELOPMENT_MODE=''` — the production-like regime `.github/workflows/reusable-performance.yml:47-50` measures in. `SCRIPT_DEBUG` matters to a target: with it on, the admin serves unminified scripts and the JavaScript baseline roughly doubles    |
| Isolation           | `WP_HTTP_BLOCK_EXTERNAL=true`, `DISABLE_WP_CRON=true`, matching the same workflow; no plugin active in either arm                                                                                                                                                                                                                                                     |
| Object cache        | None. `wp_using_ext_object_cache()` is false and `wpExtObjCache` is `no` in all 18 scenarios of both arms. This is the "backend absent" condition, and it is the default here rather than an edge case                                                                                                                                                                |
| Content             | `themeunittestdata.wordpress.xml` at the commit CI pins, `b9752e0533a5acbb876951a8cbb5bcc69a56474c`, 563,763 bytes, **md5 `9555f7012da5a9ec0019b17a3a248bea`**, imported with `--authors=create`. Census measured after import: **49 published posts, 22 published pages, 29 approved comments, 38 attachment posts**. The importer plugin was deactivated afterwards |
| Localization        | de_DE packs installed for core, plugins and themes, as CI does                                                                                                                                                                                                                                                                                                        |
| Permalinks          | `/%year%/%monthnum%/%postname%/` — the value `tools/local-env/scripts/install.js` and CI both use                                                                                                                                                                                                                                                                     |
| Suite configuration | `TEST_RUNS=20`, `repeatEach=2` → 2 repetitions × 20 iterations = **40 samples per metric per scenario**                                                                                                                                                                                                                                                               |
| Contexts measured   | **18** — 2 admin locales, 8 homepage theme×locale, 8 single-post theme×locale, over `twentytwentyone`/`twentytwentythree`/`twentytwentyfour`/`twentytwentyfive` × `en_US`/`de_DE`                                                                                                                                                                                     |
| Suite result        | **820 passed / 0 failed** in both arms; before arm 9.7 m, after arm 8.6 m                                                                                                                                                                                                                                                                                             |

Docker is available in this environment, so the documented harness ran as documented: `npm run env:start`,
`npm run env:install`, `npm run test:performance`.

**The database no longer holds that content, and that is expected rather than a discrepancy.** The E2E suite
was run after the pair, and its own setup calls `activateTheme( 'twentytwentyone' )`, `deleteAllPosts()` and
`deleteAllBlocks()` at `tests/e2e/config/global-setup.js:34-36`. So a reader who inspects the live
installation today finds a classic theme active and no posts. Both arms ran against the content described
above while they ran, and the artifacts themselves carry the proof: the canonical front-end scenario records
71 `wpDbQueries` in both arms, all 18 scenarios record between 26 and 72, and the eight Single Post scenarios
resolved a post at all — none of which an empty database can produce. The census was taken from the running
installation and recorded in `artifacts/qa-logs/R01-canonical-pair.log` before either arm started. The runtime checks in §_Verification_ were re-run afterwards on the emptied database, which is why
their figures are stated as counts of references rather than as timings.

### Evidence manifest

| Artifact                                    | Role                                                                                    |   Bytes | SHA-256                                                            |
| ------------------------------------------- | --------------------------------------------------------------------------------------- | ------: | ------------------------------------------------------------------ |
| `artifacts/before-performance-results.json` | **before arm** — the seven runtime files at base `5e9d05d7dd`                           | 199,870 | `897cdfb8002b02394ab8cdaf54b7e3348435f7ba80cd82f43df7df907f9f56bf` |
| `artifacts/performance-results.json`        | **after arm** — the delivered working tree                                              | 199,622 | `5ad2af581a1d8fb46d4d8d756f4480d7aaf14abc2470a1b32c7777eb46bfeeb7` |
| `artifacts/performance-results.md`          | comparator output over that pair, `node ./tests/performance/compare-results.js`, exit 0 |  21,923 | `bc019a1ecc5ef2a835fbbc0a7e967af2384e96dabcea35c3e8165311f6d495a5` |

All three live in the gitignored `artifacts/` directory (`.gitignore:47`), which the performance workflow
uploads wholesale on every run. They are reproducible evidence rather than committed binaries, which is
why every figure that depends on one is also restated in a table in this document.

**The pair was verified programmatically rather than by eye**, and the verification is retained in
`artifacts/qa-logs/R01-canonical-pair.log`: 18 scenarios in both arms with identical title sets; 2
repetitions per scenario; 40 samples per metric per scenario in both arms; identical metric sets per
scenario between arms — 13 metrics for an admin scenario, 14 for a front-end one. The comparator enforces
the same properties itself and exits non-zero if any of them fails. The per-scenario delta for every
metric in all 18 scenarios, which is more than any table here reproduces, is retained beside it in
`artifacts/qa-logs/R01-canonical-pair-summary.txt`.

The exact commands that reproduce all three, in order:

```
# before arm - park the seven runtime files to their base content, restart php-fpm, then:
TEST_RESULTS_PREFIX=before npm run test:performance   # writes artifacts/before-performance-results.json
# restore the delivered content, restart php-fpm, then:
npm run test:performance                              # writes artifacts/performance-results.json
node ./tests/performance/compare-results.js           # writes artifacts/performance-results.md
```

**The swap set is seven files**, and both arms were verified by git blob id before and after:

| File                                        | Base blob      | Delivered blob |
| ------------------------------------------- | -------------- | -------------- |
| `src/wp-settings.php`                       | `dab1d8fd4c0d` | `c219376ac336` |
| `src/wp-includes/class-wp-object-cache.php` | `cda63e66d49e` | `57a637c2cd27` |
| `src/wp-includes/formatting.php`            | `2b32b5aafb05` | `6296e832ecb6` |
| `src/wp-includes/script-loader.php`         | `733914d1d365` | `36ef405e0187` |
| `src/wp-includes/autoload.php`              | absent at base | `6863ef34829a` |
| `src/wp-includes/autoload-classmap.php`     | absent at base | `8e1ab4f79daa` |
| `src/wp-includes/emoji-arrays.php`          | absent at base | `37f67378b827` |

`src/wp-includes/capabilities.php` is deliberately **not** in that set: the memoization an earlier arm of
this work carried was withdrawn on measurement, so the file is byte-identical to base blob
`c5f4099127aa` in both arms. See §_Request-scoped memoization of `map_meta_cap()` — withdrawn_.

---

## Aggregate summary of total improvement across all metrics

### The target table, reproduced verbatim

| Metric                                 | Method                                  | Target          |
| -------------------------------------- | --------------------------------------- | --------------- |
| Front-end TTFB (uncached)              | tests/performance/ suite                | >=20% reduction |
| Admin DOMContentLoaded                 | tests/performance/ suite                | >=15% reduction |
| Admin JS transfer size (gzipped)       | Build output analysis                   | >=30% reduction |
| PHP memory per front-end request       | memory_get_peak_usage() instrumentation | >=10% reduction |
| DB queries per front-end page load     | SAVEQUERIES count                       | >=15% reduction |
| PHP files loaded per front-end request | get_included_files() count              | >=30% reduction |

### Results

Each row is decided on the canonical context the target names — the anonymous front end is Homepage ›
`twentytwentyfive` › `en_US`, the default experience of a current install and the heaviest bundled theme
on every dimension; the admin context is Admin › `en_US`. No row is decided by a substituted scenario, a
substituted regime or a substituted metric, and none is left undecided.

| #   | Metric                                 | Method                           | Target |      Before |       After |            Δ | Verdict    |
| --- | -------------------------------------- | -------------------------------- | ------ | ----------: | ----------: | -----------: | ---------- |
| 1   | Front-end TTFB (uncached)              | `tests/performance/` suite       | >=20 % |   481.65 ms |   430.45 ms | **−10.63 %** | ❌ not met |
| 2   | Admin DOMContentLoaded                 | `tests/performance/` suite       | >=15 % |   463.45 ms |   130.05 ms | **−71.94 %** | ✅ met     |
| 3   | Admin JS transfer size (gzipped)       | build-output byte accounting     | >=30 % | 1,059,475 B |   154,768 B | **−85.39 %** | ✅ met     |
| 4   | PHP memory per front-end request       | `memory_get_peak_usage( false )` | >=10 % | 9,954,432 B | 9,354,544 B |  **−6.03 %** | ❌ not met |
| 5   | DB queries per front-end page load     | query count via `Server-Timing`  | >=15 % |          71 |          71 |   **0.00 %** | ❌ not met |
| 6   | PHP files loaded per front-end request | `get_included_files()` count     | >=30 % |         510 |         408 | **−20.00 %** | ❌ not met |

All four unmet rows are read from the same request — Homepage › `twentytwentyfive` › `en_US` — so they are
not four framings of four different requests; they are four properties of one.

**The spread across all 18 scenarios**, so that the canonical row is visible as a choice rather than as a
best case:

| Metric           | Best scenario                                              |            Δ | Worst scenario                      |        Δ |
| ---------------- | ---------------------------------------------------------- | -----------: | ----------------------------------- | -------: |
| Files loaded     | Homepage `twentytwentythree` en_US, 483 → 381              | **−21.12 %** | Admin de_DE, 532 → 435              | −18.23 % |
| Peak memory      | Homepage `twentytwentyone` en_US, 6,733,168 → 6,132,576 B  |  **−8.92 %** | Single Post `twentytwentyone` en_US |  +0.08 % |
| TTFB             | Admin en_US, 452.75 → 380.60 ms                            | **−15.94 %** | Homepage `twentytwentyfive` en_US   | −10.63 % |
| DOMContentLoaded | Admin en_US, 463.45 → 130.05 ms                            | **−71.94 %** | Admin de_DE, 457.70 → 129.15 ms     | −71.78 % |
| DB queries       | _(none — every one of the 18 scenarios is exactly 0.00 %)_ |       0.00 % | —                                   |   0.00 % |

Not a target, but measured in the same pair and worth recording:

| Metric                              | Context                       |      Before |       After |            Δ |
| ----------------------------------- | ----------------------------- | ----------: | ----------: | -----------: |
| `wpBootstrap`                       | Homepage tt5 en_US            |   350.71 ms |   292.25 ms | **−16.67 %** |
| `wpBootstrap`                       | Admin en_US                   |   362.61 ms |   295.59 ms | **−18.48 %** |
| `wpTotal`                           | Admin en_US                   |   440.55 ms |   368.56 ms | **−16.34 %** |
| `wpMemoryUsage` (current, not peak) | Admin en_US                   | 6,670,392 B | 5,990,296 B | **−10.20 %** |
| `wpMemoryPeak`                      | Admin en_US                   | 7,276,768 B | 6,632,288 B |  **−8.86 %** |
| `wpFilesLoaded`                     | Admin en_US                   |         529 |         429 | **−18.90 %** |
| `largestContentfulPaint`            | Homepage tt5 en_US            |      566 ms |      520 ms |      −8.13 % |
| `adminJsRaw`                        | Admin en_US                   | 3,336,986 B |   561,528 B | **−83.17 %** |
| `wpCacheMisses`                     | every one of the 18 scenarios |   unchanged |   unchanged |       0.00 % |

### Reconciling these numbers with the governing plan's baselines

The governing plan fixes its own baselines in §0.2.1 — **484 files, 5.55 MB peak, 25 queries, 39.75 ms** on
the same canonical homepage, and **3,285,517 B raw / 1,042,614 B gzipped** of admin JavaScript — and derives
absolute ceilings from them: ≤ 338 files, ≤ 4.99 MB, ≤ 21 queries, ≤ 31.80 ms, ≤ 729,830 gzipped bytes. The
before arm above measures 510 files, 9,954,432 B, 71 queries, 481.65 ms and 1,059,475 gzipped bytes. Both
sets are kept here, because discarding either one would hide something.

They differ because the two measurements are not the same measurement. Every difference is a property of the
method, and none of them is a property of the code:

| Dimension  | Governing plan §0.2.1, §0.8.3                             | This document's pair                                                       | Consequence                                                                                |
| ---------- | --------------------------------------------------------- | -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| SAPI       | PHP built-in server, CLI SAPI, `opcache.enable_cli=Off`   | php-fpm behind nginx, `opcache.enable=On`, reset before every sample       | Different compile behaviour and different absolute timings                                 |
| Docroot    | the built tree, `build/`                                  | the source tree, `src/`, which is what this harness's `.env` serves        | A different file population: 484 against 510, +26                                          |
| Database   | MariaDB 10.11.14                                          | MySQL 8.4.11                                                               | Affects query timing, not query count                                                      |
| Content    | a bare install                                            | the CI-pinned theme-unit data set — 49 posts, 22 pages, 29 comments        | The dominant term in query count: 25 against 71, almost all of it per-block rendering work |
| Instrument | server-side total request time from a `curl` loop         | `timeToFirstByte` as a browser measures it, under Playwright               | 39.75 ms and 481.65 ms are different quantities and neither converts into the other        |
| Workload   | peak memory of a bare install rendering an empty homepage | the same `memory_get_peak_usage( false )` on a content-bearing block theme | 5.55 MB against 9,954,432 B                                                                |

The one figure that nearly agrees is the one that does not depend on the interpreter at all: admin JavaScript
is a property of the delivered payload, and the plan's 3,285,517 B raw / 1,042,614 B gzipped reproduce here
as 3,336,986 B / 1,059,475 B — **+1.6 %**, the two extra locale-dependent scripts this install serves. That
agreement is the control which shows the other gaps are method and workload rather than measurement error.

**Which baseline decides a verdict.** Gate 2 names `compare-results.js` as the proof mechanism, and the
OPcache rules above require a before/after pair taken under identical conditions. Both point the same way: a
percentage target is read against the before arm of the pair that produced the after arm, which is what the
results table does. The plan's absolute ceilings are not discarded, though — where they are comparable at
all, they are reported here:

| Plan ceiling (§0.2.1) |   Delivered | Comparable?                                  | Against the ceiling               |
| --------------------- | ----------: | -------------------------------------------- | --------------------------------- |
| ≤ 338 files           |         408 | yes — same `get_included_files()` count      | not met, 70 files over            |
| ≤ 4.99 MB peak        | 9,354,544 B | same function, heavier workload              | not met                           |
| ≤ 21 queries          |          71 | same counter, content-bearing install        | not met                           |
| ≤ 31.80 ms            |   430.45 ms | no — different instrument and different SAPI | not a comparison that can be made |
| ≤ 729,830 B gzipped   |   154,768 B | yes — a payload property                     | **met, at 21 % of the ceiling**   |

So on the one metric whose baseline is method-independent, the plan's absolute ceiling is met with a factor
of 4.7 to spare. On the three where the plan's install and this one differ in workload, neither the
percentage nor the absolute form is met, and §_Why four targets are not met_ prices each miss against the
pool that would close it. On TTFB the absolute form is not a comparison anyone can make in either direction,
and the percentage form — the one gate 2 measures — is reported at −10.63 %.

---

## Observability: emit the metrics the targets are expressed in

**Bottleneck**: Four of the six targets could not be measured at all, and a fifth had no baseline. The
harness mu-plugin reported `memory_get_usage()` — current usage, not peak — and emitted nothing about
files loaded or object-cache behaviour, so the peak-memory and file-count targets had no instrument. The
reporting side recognized only `wpMemoryUsage`, `wpExtObjCache` and `wpDbQueries` in its formatter, so a
new metric would have rendered as a duration. The admin spec recorded only `timeToFirstByte`, so the
Admin DOMContentLoaded target had no measurement and therefore no baseline. Measuring is a precondition
for changing anything else here, which is why this is the first entry.

**Root Cause**: The harness was built to watch a small set of long-standing metrics. Nothing in it was
wrong; it simply did not describe the properties these targets are written against, and a target expressed
in a metric nobody emits cannot be proven either way.

**Change**: `tests/performance/wp-content/mu-plugins/server-timing.php` emits five further metrics from
both of its collectors — `memory-peak` (`memory_get_peak_usage( false )`, sampled before the callback
allocates anything of its own), `files-loaded` (`count( get_included_files() )`), `cache-hits`,
`cache-misses` (both from one validated snapshot of the object cache's public counters, so a drop-in is
never touched twice) and `bootstrap` (`$timestart` to `wp_loaded`). `tests/performance/utils.js` classifies
them so counts pass through as numbers and flags are never differenced;
`tests/performance/compare-results.js` refuses a pair whose scenario sets, metric sets, repetition counts
or per-metric sample counts disagree. `tests/performance/specs/admin.test.js` captures
`domContentLoaded`; `home.test.js` and `single-post.test.js` record the new front-end metrics; all three
declare every metric they collect, so a series is reset between scenarios rather than carried over. The
delivered vocabulary is exactly **five new slugs**, giving **11 metrics on a front-end request and 9 on an
admin request**.

**Measurement**: verified on the wire rather than from the source. An anonymous `GET /` answers
`Server-Timing: wp-before-template, wp-template, wp-total, wp-memory-usage, wp-db-queries,
wp-ext-obj-cache, wp-memory-peak, wp-files-loaded, wp-cache-hits, wp-cache-misses, wp-bootstrap` — eleven
metrics — and an authenticated `GET /wp-admin/` answers the same set minus the two template metrics — nine.
`tests/performance/specs/utils.test.js` pins that contract from both ends: it reads the mu-plugin and all
three specs and asserts the five added slugs are exactly `memory-peak`, `files-loaded`, `cache-hits`,
`cache-misses` and `bootstrap`, that the totals are 11 and 9, and that each spec requires exactly what its
own collector emits. That file reports **94 passed / 0 failed**.

**Value**: five of the six targets are now measurable through the project's own tooling, and the sixth
(admin JavaScript bytes) is measured deterministically in the same run by compressing every JavaScript
response at a fixed gzip level rather than trusting a negotiated transfer encoding. The Admin
DOMContentLoaded target acquired the baseline it never had — 463.45 ms — which is what turns the −71.94 %
in the results table from an assertion into a comparison. Everything else in this document is measured
through this harness, so its correctness is the precondition for the rest.

### Two harness properties that are not metrics

**The reset control plane.** Every measured iteration begins with `POST /?clear_cache`, which discards the
opcode cache, the object cache and the expired transients and answers **202**; the spec fails the
iteration on any other status, so a warm sample cannot silently enter a series. The endpoint does not
exist unless a secret has been provisioned: the harness writes a fresh 32-byte random token to
`.cache/performance-cache-reset-token` before its first iteration and its global teardown deletes it, so
the plane exists for exactly the duration of one measured run. It is POST-only, the secret travels in a
custom header (which a cross-origin form or an `img` tag cannot set), every refusal answers with a status
and no body, and an installation that merely has the mu-plugin present has no reset endpoint at all.

**Five diagnostics were withdrawn.** An earlier arm of this harness also emitted `bootstrap-valid`,
`opcache-enabled`, `opcache-jit` and two process identifiers. They are gone. `bootstrap-valid` could only
ever report `1`: both collectors run from a `shutdown` callback, and a request only reaches `shutdown`
after `wp_loaded` has fired, so the null it existed to flag is unreachable from there. The two OPcache
flags described the host rather than the request, and this header is sent to every client; the regime they
reported is documented in §_Measurement environment_ instead, read from `php -i` inside the container and
retained in `artifacts/qa-logs/R01-canonical-pair.log`. The metric vocabulary is the five above and
nothing else.

---

## Core class autoloader with a build-generated static class map

**Bottleneck**: `src/wp-settings.php` at base holds **323** resolved include-family constructs, and every
one of them executes before the earliest hook (`muplugins_loaded`), so no amount of hook relocation could
reduce the count — only genuine lazy loading could. The most legible sub-case: **57 files** under
`wp-includes/rest-api/` were parsed on every request, declaring 57 classes, on a request that instantiates
no REST server at all. Measured cost of the whole eager chain on the canonical homepage: **510 files
loaded**, **9,954,432 B** peak memory, **350.71 ms** of bootstrap time.

**Root Cause**: WordPress core has no autoloader. The only autoloaders in the tree are vendored
(`wp-includes/php-ai-client/autoload.php`, `wp-includes/SimplePie/autoloader.php`). With no class-loading
mechanism, the only way to guarantee a class is available is to `require` its file during bootstrap, so
the bootstrap grew to require everything anything might need.

**Change**: `src/wp-includes/autoload.php` — one `spl_autoload_register()` handler that folds ASCII case
with `strtr()` (not `strtolower()`, which only became locale-independent in PHP 8.2 and would fold `I`
outside ASCII under `tr_TR` on the 7.4 floor), strips exactly one leading namespace separator, prefilters
on the core name prefixes, and resolves through a static map with no path derivation of any kind.
`src/wp-includes/autoload-classmap.php` — the generated map: **143 entries, 13,196 bytes, sha256
`d251ceb70bace3b73500e5d4616e2c4e1dcb493e7416bb626a3dcecba483391b`**. `src/wp-settings.php` registers the
handler before its require region and now holds **216** include-family constructs, **107 fewer than base**
(108 requires removed, 1 added for the autoloader itself).

Four design decisions carry this, and each was decided by measurement:

-   **A generated static map, not filesystem probing.** The map derives no path from the requested name, so
    candidate probing disappears: a resolution costs one case-fold, up to six `strncmp()` prefix tests, an
    `isset()`, one shape check and one `file_exists()` before the `require_once`. Core already ships this
    generated-manifest pattern in `wp-includes/blocks/index.php:55` and `:165`, which both `require` a
    generated file returning an array.
-   **The map is generated, never hand-edited.** `tools/build/generate-autoload-classmap.php` derives it and
    `Gruntfile.js` runs it as `build:autoload-classmap` first in both branches of `grunt build`. It writes
    only when content differs, and it is deterministic: regenerating on the delivered tree reproduces the
    committed file **byte for byte**, digest included.
-   **The map is coupled to the require set by construction.** The generator reads `src/wp-settings.php` and
    refuses to map any name whose file the bootstrap still reaches through its own top-level requires. This
    is not stylistic: the bootstrap uses plain `require`, so a name that were both mapped and eagerly
    required could be autoloaded first and then fatally redeclared. The maintenance rule that follows — the
    map must be regenerated whenever a require is added to or removed from `wp-settings.php` — is recorded in
    `autoload.php`'s own header.
-   **Only files that are safe to load on demand are mapped.** A file is admitted only when it declares
    exactly one class, interface, trait or enum; runs **nothing** at file scope; has every compile-time
    relative (parent, interfaces, traits, enum backing type) itself resolvable; is not a name a drop-in may
    declare instead of core (`WP_Object_Cache` is the case that matters); and is not still reachable through
    the bootstrap's own requires. Pruning iterates to a fixpoint, so dropping a parent invalidates every
    child. That is why the map holds 143 entries and not the ~290 single-class files the tree contains.

**Measurement**: canonical pair, medians over 40 samples, php-fpm restarted between code states, one
`opcache_reset()` per measured request.

| Metric            | Context                           |      Before |       After | Δ                         |
| ----------------- | --------------------------------- | ----------: | ----------: | ------------------------- |
| `wpFilesLoaded`   | Homepage tt5 en*US *(canonical)\_ |         510 |         408 | **−20.00 %** (−102 files) |
| `wpFilesLoaded`   | Admin en_US                       |         529 |         429 | **−18.90 %** (−100 files) |
| `wpFilesLoaded`   | Single Post tt5 en_US             |         487 |         387 | **−20.53 %**              |
| `wpBootstrap`     | Homepage tt5 en_US                |   350.71 ms |   292.25 ms | **−16.67 %**              |
| `wpBootstrap`     | Admin en_US                       |   362.61 ms |   295.59 ms | **−18.48 %**              |
| `wpMemoryPeak`    | Homepage tt5 en_US                | 9,954,432 B | 9,354,544 B | **−6.03 %**               |
| `wpMemoryPeak`    | Homepage tt1 en_US (best)         | 6,733,168 B | 6,132,576 B | **−8.92 %**               |
| `timeToFirstByte` | Homepage tt5 en_US                |   481.65 ms |   430.45 ms | **−10.63 %**              |
| `wpDbQueries`     | all 18 scenarios                  |   unchanged |   unchanged | **0.00 %**                |
| `wpCacheMisses`   | all 18 scenarios                  |   unchanged |   unchanged | **0.00 %**                |

Two counter-facts belong in the same table, because they bound the claim:

-   **In the warm-compile regime the deferral costs time.** Single Post tt5 en_US: `wpTotal` **+6.22 %**
    (69.89 → 74.23 ms), `wpBootstrap` **+2.77 %**, peak memory **+0.02 %**, while files still fall 20.53 %.
    Once the opcode cache holds the files, removing them from the bootstrap removes nothing and the
    autoloader's per-resolution work is all that is left.
-   **The deferral changes nothing the request produces.** Object-cache misses are identical to the unit in
    every one of the 18 scenarios, so no deferred file was issuing a lookup that now misses, and query counts
    are identical too. The only change in the change set that alters the delivered HTML at all is the emoji
    gate, measured on its own at −3,324 bytes in §_Gating the emoji detection script and relocating the emoji
    arrays_; the deferral itself changes _when_ a file is loaded, never _what_ the response contains.

**Value**: 102 fewer files parsed, 599,888 bytes less peak memory and 58 ms less bootstrap time on every
uncached anonymous front-end request, and 100 fewer files with 644,480 bytes and 67 ms on every admin
request, with no change to what either request returns. Extrapolated across a busy install this is the
largest single lever in the change set — but it is **also the reason the file-count target still fails**,
because the remaining pool is out of reach: §_Why four targets are not met_ prices it.

### Why 87 removable requires were deliberately put back — and re-verified in this round

The first implementation deferred everything the safety rules allowed, leaving 129 include constructs.
Measurement then showed the deferral had gone too far: an autoload resolution costs ≈1.4 µs in the php-fpm
SAPI (≈1.9 µs through `spl_autoload_call()` including PHP's own dispatch), and for a class the request
loads anyway, deferring its `require` _adds_ that cost and removes nothing, because the file is parsed
either way. 87 requires were therefore restored — 85 whose classes load on a canonical request, plus two
restored to avoid a fatal.

That decision was re-tested from scratch in this round rather than taken on trust. All 216 remaining eager
targets were re-classified with the generator's own inspector: 7 dynamic, 119 not autoloadable (they
declare functions or run something at file scope), and **89 pure single-symbol files still eagerly
required**. 86 of those 89 are written as a plain top-level `require ABSPATH . WPINC . '…';` and were
removed mechanically (the other three are reached through a conditional branch); the generator was then
iterated to its fixpoint, which restored the 8 whose names it refuses to map. The resulting state — **78
further requires removed, 138 include constructs, a 221-entry map** — was measured against the delivered
tree with php-fpm restarted between states:

| Metric, maximal deferral vs delivered |   Delivered |     Maximal | Δ                        |
| ------------------------------------- | ----------: | ----------: | ------------------------ |
| Files loaded (homepage)               |         396 |         384 | **−12 files (−3.03 %)**  |
| Peak memory                           | 4,008,024 B | 4,008,024 B | **0 B — byte-identical** |
| Total request time                    |    ≈36.2 ms |    ≈36.3 ms | none, within noise       |
| DB queries                            |          15 |          15 | 0                        |

66 of the 78 classes are loaded by the request anyway, which is why peak memory does not move at all. The
change would buy 12 files on a proxy metric, cost ≈148 µs of resolution work on the hot path, and leave
the target 39 files short. It is therefore **not adopted**, and the delivered restoration stands. One
further fact from the same experiment is worth recording: removing those requires _without_ consulting the
generator's admission rules produced a hard fatal — `Class "WP_AI_Client_Discovery_Strategy" not found`,
raised out of the experiment's own mutated bootstrap rather than out of the delivered one, so the line number
it reported belongs to a file that no longer exists — which is direct evidence that the fixpoint rules are
load-bearing rather than decorative.

---

## Conditional loading of Command Palette assets

**Bottleneck**: every admin screen received the whole Command Palette bundle. Measured on the admin
dashboard: **3,336,986 bytes raw / 1,059,475 bytes gzipped** of JavaScript, of which the palette's
`js/dist/*` dependency closure — `wp-commands`, `wp-core-commands` and everything they pull in — is the
overwhelming majority. The palette is a block-editor affordance; on a settings screen or a list table
nothing consumes it.

**Root Cause**: `src/wp-includes/default-filters.php:605` registers
`add_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' )`, and the callback performed no
screen check and no capability check. It enqueued the handles and printed an inline initializer on every
admin request, so the cost was structural rather than incidental.

**Change**: `src/wp-includes/script-loader.php` gains `wp_should_load_command_palette_assets()`, a
filterable, screen-aware predicate placed with the gate family core already ships
(`wp_should_load_block_editor_scripts_and_styles()`, `wp_should_load_separate_core_block_assets()`,
`wp_should_load_block_assets_on_demand()`), and `wp_enqueue_command_palette_assets()` consults it before
enqueueing anything. The `add_action` is untouched, so an existing
`remove_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' )` keeps working, the hook name
and argument count are unchanged, and nothing in `WP_Scripts` or `WP_Dependencies` is altered — the gate
declines to enqueue rather than changing how enqueueing behaves.

**Measurement**: canonical pair, medians over 40 samples per metric.

| Metric             | Context     |      Before |     After | Δ            |
| ------------------ | ----------- | ----------: | --------: | ------------ |
| `adminJsGzipped`   | Admin en_US | 1,059,475 B | 154,768 B | **−85.39 %** |
| `adminJsRaw`       | Admin en_US | 3,336,986 B | 561,528 B | **−83.17 %** |
| `adminJsGzipped`   | Admin de_DE | 1,059,475 B | 154,768 B | **−85.39 %** |
| `domContentLoaded` | Admin en_US |   463.45 ms | 130.05 ms | **−71.94 %** |
| `domContentLoaded` | Admin de_DE |   457.70 ms | 129.15 ms | **−71.78 %** |

Both byte figures have a standard deviation and a median absolute deviation of **0.00 kB** across all 40
samples, which is what a deterministic asset measurement looks like. The screen-awareness was checked
directly rather than inferred: an authenticated fetch of `/wp-admin/` contains **0** references to
`wp-commands` or `core-commands`, while `/wp-admin/post-new.php` — an editor screen, where the palette is
used — still contains **5**. So the gate suppresses the payload where it is unused and preserves it where
it is used.

**Value**: 904,707 fewer gzipped bytes (2,775,458 raw) on a non-editor admin screen, and 333 ms off
DOMContentLoaded. This is the change that carries two of the six targets, and it is the one with the
largest real-world effect per byte of diff: on a metered connection it is most of a megabyte per admin
page view, and the parse-and-execute time of that bundle is what the DOMContentLoaded figure measures.

### An accepted user-visible consequence

The palette's `Ctrl+K` trigger in the admin bar no longer opens a palette on screens where the assets are
not loaded. That is a deliberate consequence of not shipping the bundle, not an oversight: the alternative
is to ship 900 kB of JavaScript on every screen so that a shortcut works on all of them. The palette
remains available on the screens that use it. This is the only user-visible behaviour change in the change
set, and it is recorded here because a reader deciding whether to adopt the gate needs to weigh it.

---

## Gating the emoji detection script and relocating the emoji arrays

**Bottleneck**: two separate costs in one subsystem. First, the emoji detection script was printed inline
on **every** front-end response: measured at **3,324 bytes** of HTML, or **1,316 bytes** after `gzip -9`.
Second, `src/wp-includes/formatting.php` carried the emoji entity and partial arrays inline — **140,933
bytes on four physical lines** — so the tokenizer parsed them on every request that loads the file, whether
or not anything ever called `wp_staticize_emoji()`. Measured directly with `token_get_all()`: the base file
is 354,690 bytes and 48,123 tokens and takes **2.35 ms** to tokenize; without the array region it is
216,491 bytes and 32,016 tokens and takes **1.58 ms** — **−32.8 %** parse time, **−33.5 %** tokens.

**Root Cause**: the detection script exists to decide whether the browser can render an emoji set, and it
was hooked unconditionally because there was no predicate to consult. The arrays lived in
`formatting.php` because that is where the functions that use them live, and a 140 kB literal is invisible
in a diff.

**Change**: `src/wp-includes/formatting.php` — the function hooked as `print_emoji_detection_script`
consults a new filterable predicate before delegating to its private worker, and `_wp_emoji_list()` loads
its data from a new file on demand. `src/wp-includes/emoji-arrays.php` — the relocated arrays as a
`return`ed array, 142,289 bytes, carrying the same `// START: emoji arrays` / `// END: emoji arrays` marker
contract the build depends on. `Gruntfile.js` — `replace:emoji-regex` is retargeted at the new file, and
`verify:emoji-markers` refuses a data file with no marker region or with two, so the generator can never
silently write nothing. These two edits are atomic with each other: relocating the arrays without
retargeting the task would leave a build task matching nothing, and the `git diff --exit-code` guard in 15
workflows would turn that into a CI failure.

Because the payload is an **inline** script, gating it touches no part of the enqueue dependency system:
it never enters `WP_Scripts` at all. Core already ships a per-screen opt-out for the same script at
`src/wp-admin/edit-form-blocks.php:42`; this generalizes that precedent behind a filter.

**Measurement**: isolated swap of exactly `formatting.php` and `emoji-arrays.php` between base and
delivered content, php-fpm restarted between states, canonical homepage fetched directly so the bytes are
the bytes a client receives:

| Metric                                     |                  Before |                   After | Δ                                              |
| ------------------------------------------ | ----------------------: | ----------------------: | ---------------------------------------------- |
| Front-page HTML                            |               250,618 B |               247,294 B | **−3,324 B (−1.33 %)**                         |
| Front-page HTML, `gzip -9`                 |                35,057 B |                33,741 B | **−1,316 B (−3.75 %)**                         |
| `_wpemojiSettings` occurrences in the HTML |                       1 |                   **0** | detection script suppressed                    |
| Emoji style rules in the HTML              |                 present |             **present** | styles preserved                               |
| `wp-files-loaded`                          |                     408 |                     408 | 0 — the relocation trades one file for another |
| `token_get_all()` over `formatting.php`    | 2.35 ms / 48,123 tokens | 1.58 ms / 32,016 tokens | **−32.8 % / −33.5 %**                          |

The peak-memory effect of the relocation is **not** measurable at request level in this environment: peak
is set during rendering, well above the tokenizer's high-water mark, and the canonical figure does not move
when only this pair is swapped. That is stated rather than papered over — the parse-time and token-count
reductions above are what this change actually buys, and they are what a cold-start or OPcache-disabled
deployment pays for the arrays today.

**Value**: 1,316 gzipped bytes off every anonymous front-end response, and a third of the tokenizer cost of
one of core's largest files removed from every request that does not staticize emoji — which is nearly all
of them. The emoji _styles_ still ship, so an emoji that needs the fallback image still gets one, and
`wp_staticize_emoji()` and `wp_staticize_emoji_for_email()` behave identically because the data they read is
the same array, loaded on first use instead of at parse time.

---

## Per-group object cache hit/miss counters

**Bottleneck**: `WP_Object_Cache` exposes `$cache_hits` and `$cache_misses` as two request-wide integers.
On the canonical homepage that is 2,539 hits and 371 misses — a number that says a request did a lot of
cache work and nothing about _which_ cache. Any question of the form "did this change make the post cache
behave worse" was unanswerable, which is a problem for a change set whose query target depends on cache
behaviour.

**Root Cause**: the counters were added to answer "is the cache working", which two integers do. Nothing
attributed them to a group, and no core API exposed the attribution.

**Change**: `src/wp-includes/class-wp-object-cache.php` gains an **opt-in** per-group register:
`$track_group_stats` (default `false`) enables it, `$cache_group_stats` holds the per-group tallies,
`$max_tracked_groups` bounds the array so a request touching an unbounded number of groups cannot grow it
without limit, and the count of groups left out of the register is available rather than silently lost.
`stats()` renders the per-group figures when tracking was enabled. The two public integers are untouched
and keep their exact previous meaning, because plugins read them directly.

**Measurement**: tracking enabled, one 10-post `WP_Query` executed, register read:

```
global hits=477 misses=90; groups tracked=9
posts                     hits=102  misses=13
category_relationships    hits=59   misses=11
post_format_relationships hits=59   misses=11
post_tag_relationships    hits=59   misses=11
terms                     hits=36   misses=14
post_meta                 hits=35   misses=11
options                   hits=11   misses=0
post-queries              hits=2    misses=2
term-queries              hits=0    misses=2
```

With tracking off — the shipped default — the added cost is a single boolean test on the paths that already
increment the global counters, and the canonical pair confirms the register changes nothing measurable:
`wpCacheMisses` is identical in all 18 scenarios, and `wpCacheHits` moves −0.08 % on the canonical homepage
(2,539 → 2,537, two fewer lookups, from the palette and emoji gates not registering handles).

**Value**: a diagnostic capability, and it is claimed as nothing more — it moves none of the six targets.
What it buys is the ability to answer the question the query target actually raises: the 477/90 above
resolves into nine groups, so a regression in the post cache is now visible as a regression in `posts`
rather than as a rounding difference in a request-wide total. It also degrades correctly: a drop-in that
replaces `WP_Object_Cache` wholesale simply has no register, and the harness reads the counters through a
validated snapshot of public properties, so a drop-in that omits them reports 0 rather than raising.

---

## Work considered and not delivered

Everything in this section is **absent from the delivered change set**. It is written up because a
measurement that rejects a change is as much a result as one that accepts it, and because the analysis is
what a future attempt should start from. These entries deliberately do **not** use the five-field
optimization template above: none of them is an optimization this change set delivers, and none of the
figures in this section appears in the aggregate results table.

### Request-scoped memoization of `map_meta_cap()` — withdrawn

_Delivered state_: `src/wp-includes/capabilities.php` ships **byte-identical to base** (blob
`c5f4099127aa`).

_What was measured_: an earlier arm memoized the mapping in a request-scoped global, keyed on user ID and
capability name, read and written inside the `default:` arm of the `switch`. Per-request cost, measured on
five request shapes with the memo present against base:

| Request shape                      | With memo, OPcache off | With memo, OPcache on |
| ---------------------------------- | ---------------------: | --------------------: |
| Front end, logged out              |               −0.18 µs |              −0.08 µs |
| Front end, logged in               |               −0.94 µs |              −0.01 µs |
| `/wp-admin/` (Dashboard)           |           **+6.67 µs** |         **+10.03 µs** |
| `/wp-admin/edit.php`               |           **+5.36 µs** |          **+8.89 µs** |
| `/wp-admin/post.php?…&action=edit` |          **+12.19 µs** |         **+16.90 µs** |

_Why it is not delivered_: the `default:` arm is the cheapest branch in the function — a ten-name
`str_replace` and one array append — so memoizing it adds an `isset()` probe and a bound check to the fast
path while never touching the 86 `case` branches that do the expensive work. Three of the five shapes are a
clear regression and the two "improvements" are indistinguishable from noise. A change that measures as a
net loss on the paths it was meant to help does not ship, whatever the plan predicted for it; the honest
outcome of the measurement is withdrawal. Its absence also removes an untested code path: the memo had no
invalidation hooks and no dedicated committed guard.

_What would make it viable_: memoize inside the expensive branches instead — the post-type and
post-status-object lookups that several `case` arms repeat — and key on every argument that can change the
result. That is a different change from the one that was tried, and it needs its own before/after pair.

### Grouped comment-status counts — withdrawn

_Delivered state_: `src/wp-includes/comment.php` ships byte-identical to base.

_What was measured_: replacing five per-status `WP_Comment_Query` counts in `get_comment_count()` with one
grouped query removed exactly four queries from an **authenticated** request that renders the comment
totals — 25 → 21 on an authenticated front-end request and 31 → 27 on the Dashboard, ten samples each.

_Why it is not delivered_: the target is _"DB queries per front-end page load"_, and on an **anonymous**
front-end request `get_comment_count()` is never reached, so the change moved the targeted metric by
**0.00 %** while modifying a hook-sensitive query path used by every comment screen. The 25 → 21 figure
above therefore has nothing to do with the target in row 5 of the results table, and it is quoted here only
to record what the withdrawn experiment measured. The opportunity is real for the admin path and is carried
in the backlog.

### Batched update-transient reads — withdrawn

_Delivered state_: `src/wp-includes/update.php` ships byte-identical to base.

_What was measured_: batching the three update transients into one primed read removes two queries from an
authenticated request that renders the update count.

_Why it is not delivered_: the same reason — the queries it removes are removed from authenticated
requests, and the anonymous front end never reads them.

### A bootstrap option primer — rejected on measurement

_What was measured_: priming `wp_enable_real_time_collaboration` and `site_logo` in the bootstrap. With the
primer present, the authenticated front end stayed at 21 queries and Admin used 28; without it, the same
ten-sample medians were 21 and **27**.

_Why it is not delivered_: it delivered no front-end saving and **added** one query to Admin. Removing it
restores that query and keeps the tree aligned with the measurement-first rule.

### Maximal bootstrap deferral — rejected on measurement

_What was measured_: 78 further requires removed, 138 include constructs, a 221-entry map — 12 fewer files
on the homepage, peak memory identical to the byte, request time unchanged. Full table in §_Why 87
removable requires were deliberately put back — and re-verified in this round_.

_Why it is not delivered_: it improves a proxy metric by 3 percentage points, costs ≈148 µs of resolution
work per request, and still leaves the file-count target 39 files short.

### Five harness diagnostics — withdrawn

`bootstrap-valid`, `opcache-enabled`, `opcache-jit` and two process identifiers no longer exist. Reasons in
§_Two harness properties that are not metrics_.

---

## Why four targets are not met

This section prices each miss. In every case the pool that would have to move is named, measured, and
attributed to the constraint that blocks it.

### Row 6 — PHP files loaded: −20.00 % against ≥30 %

The canonical request loads **408** files after the change, and the target is **≤357**. The gap is **51
files**, and there is only one pool of that size left.

**Where the 408 files are.** Grouped by directory, the largest contributors on a canonical block-theme
request are `wp-includes/blocks/` (**89 files**), then the core-owned remainder of the bootstrap. The 89 are
not reachable by this change set: `wp-includes/blocks/index.php` requires the generated
`require-dynamic-blocks.php` at file scope, and that file issues one unconditional `require_once` per
dynamic-block render file. The count was measured as **identical in both code states** — 89 before, 89
after.

**Why that pool is blocked.** Two constraints, both explicit. The generated file is written by
`tools/gutenberg/copy.js` and carries its own "autogenerated, do not edit manually" header, and the
governing plan places the Gutenberg-synced trees out of scope while designating
`src/wp-includes/blocks/index.php` a **reference** rather than an edit target (AAP §0.3.2.3). Closing the
file-count target therefore requires a change in a tree this plan excludes, made by a generator this plan
does not scope. That is a scope decision for a human, not an engineering obstacle — and it is the first item
in the backlog.

**What was tried instead**, and why it does not close the gap: the maximal-deferral experiment above buys 12
files with zero measured cost benefit. 12 + 89 would exceed the target; 12 alone does not reach it.

### Row 4 — Front-end peak memory: −6.03 % against ≥10 %

The canonical request peaks at **9,354,544 B**, and the target is **≤8,958,988 B**. The gap is **395,556 B**.

The autoloader accounts for essentially all of the measured improvement, at **599,888 B**. The unit economics
of deferral are therefore ≈5,880 bytes of peak memory per deferred file on this request, and 395,556 B is
≈67 further files — i.e. the same blocked pool as row 6, and it is blocked for the same reason. Nothing else
in the change set moves peak memory measurably: the emoji relocation's effect is below the rendering
high-water mark and does not appear at request level at all, and the per-group cache register is off by
default.

One scenario in the pair does reach **−8.92 %** (Homepage `twentytwentyone` en*US, a classic theme with a
smaller block-rendering phase), which is the closest any measured context comes. It is reported in the
spread table and is \_not* substituted for the canonical row.

### Row 1 — Front-end TTFB: −10.63 % against ≥20 %

Bootstrap time falls **16.67 %** on the same request, and `wpTotal` falls 10.82 %, so the saving is real and
lands where the change was made — but TTFB includes the whole render, and the render did not get faster.
The admin context, whose response is dominated by bootstrap rather than by block rendering, reaches
**−15.94 %**, which is the shape of the same result seen with less rendering in front of it.

To reach −20 % on the canonical request, ≈45 ms more would have to come out of a 481.65 ms response.
Bootstrap is already down 58 ms; the remaining bootstrap is ≈292 ms and the rest is template rendering,
which is where the block-rendering phase lives. So this row is bounded by the same pool as rows 4 and 6, with
the same constraint in front of it.

### Row 5 — DB queries: 0.00 % against ≥15 %

**No query was removed on an anonymous front-end request, and none is claimed.** The target needs **11 of 71**
queries. Where those 71 come from was measured rather than assumed: `SAVEQUERIES` was enabled, every row of
`$wpdb->queries` was captured with its backtrace, and each query was attributed to the call site that issued
it (raw rows retained in `artifacts/qa-logs/query-attrib.json`):

| Phase                                                                                                                                                                                                                                 | Queries |  Share |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------: | -----: |
| Inside `do_blocks()` / `render_block()` — the Gutenberg-synced block-rendering phase                                                                                                                                                  |  **53** | 74.6 % |
| Block template resolution (`locate_block_template` → `resolve_block_template` → `get_block_templates()`)                                                                                                                              |       4 |  5.6 % |
| Core path: `wp_load_alloptions`, one autoloaded-miss `get_option`, one transient prime, the main `WP_Query` + `FOUND_ROWS()`, and the primed `_prime_post_caches` / `update_meta_cache` / `WP_Term_Query` / `_prime_term_caches` sets |      14 | 19.8 % |

Two conclusions follow, and both are measured. First, **there is no N+1 pattern to remove**: every core-path
query is already a single batched read, so the hypothesis that this target would be met by batching is
disproved on this request. Second, **the pool is not in reach**: three quarters of it is issued inside the
tree the plan excludes, and the largest addressable pool is the 4-query template-resolution set, worth
5.6 % against a 15 % target, in a file (`block-template-utils.php`) this plan does not scope.

This row is the one that is not one scope decision away from passing. Even granting the block-rendering
tree, the queries there are issued by per-block render callbacks; removing 11 of them is a discovery project,
not a deferral.

---

## Verification

Everything below was run on the delivered tree in this round. Where a number is a test count, it is the
count the runner printed.

### Test suites

| Suite                                 | Command                                                  | Result                                                                                                           |
| ------------------------------------- | -------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| PHPUnit, single site                  | `npm run test:php -- --no-coverage`                      | **29,131 tests, 3,441,840 assertions, 0 failures, 0 errors**; 86 warnings and 50 skips, all pre-existing; exit 0 |
| PHPUnit, Multisite                    | same with `-c tests/phpunit/multisite.xml`               | **29,923 tests, 3,443,870 assertions, 0 failures, 0 errors**; 86 warnings, 52 skips; exit 0                      |
| PHPUnit, `--group ajax`               | the group the default config excludes                    | **180 tests, 1,132 assertions, 0 failures**, 1 pre-existing skip; exit 0                                         |
| PHPUnit, `--group capabilities`       | the group that owns the withdrawn memo's function        | **789 tests, 3,003 assertions**, 0 failures                                                                      |
| PHPUnit, `Tests_Load_wpAutoloadClass` | the autoloader's own class, single site and Multisite    | **147 tests, 1,311 assertions**, 0 failures in both                                                              |
| QUnit                                 | `grunt qunit` over the compiled and uncompiled harnesses | **456 tests, 0 failed, 0 skipped, 0 todo**                                                                       |
| Playwright, performance               | `npm run test:performance`, both arms                    | **820 passed / 0 failed** each                                                                                   |
| Playwright, performance contracts     | `tests/performance/specs/utils.test.js`                  | **94 passed / 0 failed**                                                                                         |
| Playwright, E2E                       | `npm run test:e2e`                                       | **24 passed, 1 failed** — `install.test.js › should install WordPress with pre-existing database credentials`    |

**The one E2E failure is pre-existing, and that was established rather than assumed.** The spec drops the
tables and expects a redirect to `wp-admin/install.php`; it receives the front page instead. The same spec
was re-run in this round with the seven runtime files parked to base content and php-fpm restarted, and it
**fails identically on the base tree** (same assertion, same received URL). It is an environment property of
this installation, not a regression, and it is the only failing test in any suite.

**Why the suite total is smaller than an earlier measurement of this branch.** A measurement taken at commit
`a88d7f1925` recorded 29,557 single-site tests. Two changes since then account for the difference exactly, and
neither is a failure or a skip. The previous round's remediation commit removed eight dedicated test classes
and eleven isolated data probes to bring the change set back to the plan's file list — the removal
§_1. Four delivered behaviours have no dedicated committed test_ is about — which cost **365** tests. This
round then restructured the autoloader class from 39 methods to the five the review requires, which cost
**61**: that class was run under both revisions and reported 208 tests / 1,925 assertions before and 147 /
1,311 after. 29,557 − 365 − 61 = **29,131**, the figure above. Measured against the state the review examined,
this change set removes 61 tests and adds none, and every removal was mandated rather than convenient.

**No skip, exclusion or filter was added to make anything pass.** The change set touches no
`phpunit.xml*` file and no workflow file, and the one new PHPUnit class contains no `markTestSkipped`,
`markTestIncomplete` or assertion-free test. The skip and warning counts above are the suite's own.

### Static gates

| Gate                                              | Result                                                                                                                                                                         |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `php -l` over every changed PHP file              | clean                                                                                                                                                                          |
| PHPCS, `--standard=phpcs.xml.dist`                | exit 0, zero errors and zero warnings over the changed PHP                                                                                                                     |
| PHPStan, `npm run typecheck:php`                  | `[OK] No errors`                                                                                                                                                               |
| `node --check` over every changed JavaScript file | clean                                                                                                                                                                          |
| `npm run typecheck:js`                            | exit 0                                                                                                                                                                         |
| `grunt jshint:grunt`                              | 1 file lint free                                                                                                                                                               |
| Prettier                                          | the three performance specs pass `prettier --check`; `Gruntfile.js` is not Prettier-formatted at base either and was deliberately left alone rather than reformatted wholesale |

### Build and drift

| Check                                                 | Result                                                                                                        |
| ----------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| `grunt build:autoload-classmap` on the delivered tree | reproduces the committed map **byte for byte** — 143 entries, 13,196 bytes, `sha256 d251ceb7…391b`            |
| `grunt verify:build-guards`                           | **15 tests, 15 pass, 0 fail**                                                                                 |
| `grunt verify:emoji-markers`                          | one marker region found, as required                                                                          |
| `npm run build` then `npm run build:dev`              | both exit 0; afterwards the only modified tracked files are this change set's own — no generated file drifted |

### Runtime checks

| Check                    | Result                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Anonymous front end      | HTTP 200; `Server-Timing` carries exactly 11 metrics — `wp-before-template`, `wp-template`, `wp-total`, `wp-memory-usage`, `wp-db-queries`, `wp-ext-obj-cache`, `wp-memory-peak`, `wp-files-loaded`, `wp-cache-hits`, `wp-cache-misses`, `wp-bootstrap`                                                                                                                                                                                                                                           |
| Authenticated admin      | HTTP 200; `Server-Timing` carries exactly 9 metrics — the same list without the two template metrics                                                                                                                                                                                                                                                                                                                                                                                              |
| Command-palette gate     | server-rendered HTML: `/wp-admin/` **0** references to `wp-commands`/`core-commands`, `/wp-admin/site-health.php` **0**, `/wp-admin/post-new.php` **7** (`wp-commands` ×3, `core-commands` ×4). Confirmed independently in a browser, where the Dashboard leaves `wp.commands` undefined and loads 39 `script[src]`, while the editor defines it with **50 registered commands** over 97 `script[src]`, and `Ctrl+K` opens a working palette                                                      |
| Emoji gate               | front-end HTML contains **0** `_wpemojiSettings` and **1** `img.wp-smiley` rule — the detection script is gone and the styles are not. Same result on all four screens checked, in the browser DOM, in a same-origin fetch of the raw HTML and in `curl`                                                                                                                                                                                                                                          |
| Per-group cache register | off by default; with tracking on, a 10-post `WP_Query` resolved 477 hits / 90 misses into 9 named groups. Measured on the content-bearing database described above                                                                                                                                                                                                                                                                                                                                |
| `debug.log`              | truncated, then six screens requested with `WP_DEBUG=true` — the front page, the Dashboard, the block editor, Site Health, the post list and the media library, all HTTP 200. The log is **0 bytes** afterwards Running the full E2E suite afterwards leaves exactly one entry, and it is a fixture rather than a defect: the `error-protection` spec writes an mu-plugin that references an undefined class to exercise WordPress's fatal-error recovery, and the trace names that fixture file. |
| Browser console          | **0 errors and 0 warnings** across 336 requests over four screens; the only message in the session is core's own jQuery Migrate 3.4.1 notice at `log` level. **No request returned a status ≥ 400**                                                                                                                                                                                                                                                                                               |

### Self-checking machinery over this document

Four scripts are retained beside the evidence they check, in `artifacts/qa-logs/scripts/`. Three of them
check this document rather than the code, so that a reader can re-run them instead of taking any of it on
trust.

| Script               | What it asks                                                                                                                                                                                                                                                                                                                                                                                                                   | Result                                                                                                    |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------- |
| `validate_report.py` | six internal-consistency checks: the swap-set arithmetic, that every line citation resolves to a line which exists and is not blank at both ends of a range, that every SHA-256 quoted beside a repository path equals that file's digest on disk, that every section cross-reference resolves to a real heading, that every table row carries its header's column count, and that every evidence tag used in prose is indexed | **6 checks, 0 problems** — `artifacts/qa-logs/R02-validate-report.log`                                    |
| `locators.py`        | the _content_ of 39 cited locators, so a line that still exists but no longer says what is claimed here is caught as well as one that has gone missing                                                                                                                                                                                                                                                                         | **39 assertions, 39 OK / 0 MISS** — `artifacts/qa-logs/R02-locators.log`                                  |
| `vrf3.py`            | 142 mechanical assertions over the fifteen findings of the current review round, grouped by finding, each written to fail if the finding's defect is reinstated rather than if wording changes                                                                                                                                                                                                                                 | **142 assertions, 142 passed, 0 failed** — `artifacts/qa-logs/R02-vrf3.log`                               |
| `prior_rounds.py`    | retention rather than correctness: it copies every review and remediation report this project has produced into the tree and indexes their findings                                                                                                                                                                                                                                                                            | **20 rounds indexed, 39 reports copied, 0 retention failures** — `artifacts/qa-logs/R02-prior-rounds.log` |

Three things `vrf3.py` does that its predecessor did not are the reason it exists. `vrf2.py` — retained
beside it as the previous round's artifact — treated scope as resolved when the change set contained a
particular _number_ of paths, never opened the measurement artifacts, and never recomputed a target row.
This one classifies every delivered path against the governing plan's own file list and requires the
classification counts in §_Scope reconciliation_ to agree with the measured set, so a new undeclared path
fails it whatever the total; it verifies each artifact's byte size and SHA-256 against the manifest above and
counts its scenarios, repetitions and samples; and it **recomputes all six target rows from the artifacts**,
comparing before, after, delta and verdict against the results table.

What each script found this round, because a check that has never failed is not yet evidence:

-   `validate_report.py` had gone stale against the rewritten document: its swap-set check looked for a
    literal table header that the rewrite had renamed, so it reported a problem where the document was sound.
    It now locates that table by its header cells, and the repair was verified by mutating a copy of this
    document three ways — an inflated narrative count, a renamed column heading and a section reference to a
    heading that does not exist — and confirming it fails on each.
-   `locators.py` reported seven misses, every one of them an assertion about the withdrawn capability memo.
    Its assertion set was rebuilt around what this document now leans on, and rebuilding it caught a real
    defect here: a citation to line 391 of `src/wp-settings.php` that resolved to a line in the delivered file
    while describing a line in the mutated file an experiment had produced. That sentence no longer cites a
    line number.
-   `vrf3.py` reported nine failures on its first run. Two were defects in its own assertions — a withdrawal
    test scoped to a paragraph rather than to the section that actually decides whether a figure can be
    misread, and scope arithmetic that counted the enumerated-but-unchanged path as part of the change set.
    The other seven were this section and its logs, which did not exist yet. Its two new assertion families
    were then shown to fail on the defect they exist to catch: altering one figure in the results table so
    that it disagrees with the artifacts fails the recomputation, and appending a newline to a tracked file
    the plan does not enumerate fails five scope assertions — the defect the previous verifier would have
    passed. Both mutations were reverted and verified byte-identical, and both runs are in the log.

The finding inventory of every review round this project has had, this one included, is retained at
`artifacts/qa-logs/prior-findings-inventory.json` with a readable index beside it. One piece of evidence
could not be recovered, and is recorded as missing rather than glossed over: the verifier script and log for
an earlier eight-finding round were never retained, so that round's assertion _results_ cannot be re-read.
Its _findings_ can, because the review reports that raised them are now in the tree.

---

## Scope reconciliation: the change set against the governing plan's file list

The governing plan states its file list exhaustively — "every file this work touches is enumerated below" —
and names **16** implementation paths plus a set of read-only reference paths that are explicitly not edit
targets. The delivered change set is **22 tracked paths** (`git diff --name-only 5e9d05d7dd`), classified
here rather than argued around:

| Class                                                                             | Count | Paths                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| --------------------------------------------------------------------------------- | ----: | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Enumerated implementation paths, delivered                                        |    15 | `Gruntfile.js`; `docs/performance-optimization-report.md`; `src/wp-settings.php`; `src/wp-includes/`{`autoload.php`, `autoload-classmap.php`, `emoji-arrays.php`, `class-wp-object-cache.php`, `formatting.php`, `script-loader.php`}; `tests/performance/utils.js`; `tests/performance/specs/`{`admin`,`home`,`single-post`}`.test.js`; `tests/performance/wp-content/mu-plugins/server-timing.php`; `tests/phpunit/tests/load/wpAutoloadClass.php` |
| Enumerated but deliberately unchanged                                             |     1 | `src/wp-includes/capabilities.php` — the plan scopes a memo here; it was implemented, measured as a net loss, and withdrawn, so the file ships at base content                                                                                                                                                                                                                                                                                       |
| Covered by the plan's own trailing test pattern `tests/performance/**/*.{php,js}` |     5 | `tests/performance/compare-results.js`; `tests/performance/playwright.config.js`; `tests/performance/config/`{`global-teardown.js`, `performance-reporter.js`}; `tests/performance/specs/utils.test.js`                                                                                                                                                                                                                                              |
| **Outside the list, and requiring a human amendment**                             |     2 | `tools/build/generate-autoload-classmap.php`; `tests/build/build-guards.test.js`                                                                                                                                                                                                                                                                                                                                                                     |

### The two paths outside the list

Both are build-time only: neither is loaded on a served request and neither ships in a release artifact.
Both are the _body_ of a task the plan does enumerate in `Gruntfile.js`. Each was re-examined in this round
for whether it could be relocated into an enumerated file, and in both cases relocation costs more than it
saves. The decision is stated here so that a human can overturn it with one sentence.

**`tools/build/generate-autoload-classmap.php`** is the class-map generator. The plan requires the map to be
"generated by a new `Gruntfile.js` task, never hand-maintained", and `build:autoload-classmap` is that task;
this file is what it runs. It cannot become JavaScript, because its central job is deciding — per file —
whether a file is a pure declaration with no file-scope side effects, using PHP's own tokenizer: token
normalization across the 7.4-vs-8.0 name-token split, namespace/`use`/attribute/`declare` handling, a
declaration reader that rejects `::class` false positives, a reserved-type filter, and the prefix authority
read out of `autoload.php` itself. That analysis is what prunes ~290 candidates to the 143 safe entries, and
the fixpoint it computes is load-bearing: the experiment in §_Why 87 removable requires were deliberately put
back_ produced a hard fatal the moment requires were removed without consulting it. Re-implementing PHP
parsing in JavaScript would put a runtime-critical artifact behind a hand-rolled parser and would remove the
file from `php -l`, PHPCS and PHPStan, all three of which cover it today and all three of which report
clean. PHP build tooling under `tools/` is also this repository's own convention at base
(`tools/php-ai-client/reorganize.php`).

**`tests/build/build-guards.test.js`** holds the 15 cases `verify:build-guards` runs inside `verify:build`.
They exercise the _refusal_ branches of the two generation tasks — an empty, truncated, stale or misannounced
class map, a name two files declare, and an emoji data file with no marker region or with two — which a
healthy build never reaches and which nothing else can reach either. Each case builds a sandbox source tree
and runs the real Grunt task against it, so the harness has to be JavaScript and has to spawn grunt;
relocating it means pasting a 700-line test harness into the build configuration. `tests/<suite>/` is core
convention at base (`tests/e2e`, `tests/qunit`, `tests/phpstan`, `tests/visual-regression`, `tests/gutenberg`).

**The amendment this needs, stated so the decision is a human's**: add `tools/build/generate-autoload-classmap.php`
and `tests/build/build-guards.test.js` to the plan's implementation list as build tooling. Nothing else in
the change set needs an amendment. **If the amendment is refused**, the mechanical consequences are: the
generator cannot simply be deleted, because the plan also forbids hand-maintaining the map, so the map would
have to be produced by a JavaScript re-implementation of the tokenizer analysis above, accepting the
correctness and static-analysis regression that entails; and deleting the guard file removes 15 build-refusal
cases with no replacement.

---

## Verification gaps: what this change set does _not_ prove

These are gaps in the machinery that proves the changes above are safe. They are recorded because the limits
of the evidence are part of the evidence, and because each one names the change a human must approve to close
it.

### 1. Four delivered behaviours have no dedicated committed test

The command-palette gate, the emoji detection gate, the emoji array relocation and the per-group cache
register are each supported by measured runtime evidence in this document and by core's own pre-existing
suites, which pass unchanged. None of them has a dedicated committed assertion that would go red if the
behaviour regressed. Earlier arms of this work had those tests — nine PHPUnit classes and two E2E specs — and
they were removed to bring the change set back to the plan's frozen file list, because every one of them was a
new path outside it.

**What would close it, exactly**: an amendment adding these four paths, and the assertions each should carry.

| Path a human must sanction                            | Assertions it should carry                                                                                                                                                                                                                                                                                                                         |
| ----------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tests/phpunit/tests/dependencies/commandPalette.php` | `wp_should_load_command_palette_assets()` returns false on a non-editor screen and true on an editor screen; the filter receives the default and its return decides delivery; a direct call to `wp_enqueue_command_palette_assets()` respects the gate; the three handles and the inline initializer are absent when gated                         |
| `tests/phpunit/tests/formatting/emojiGate.php`        | the predicate defaults true and the filter can turn it off; the hooked function prints exactly once when asked for and nothing when gated; the styles are unaffected either way                                                                                                                                                                    |
| `tests/phpunit/tests/formatting/emojiArrays.php`      | `emoji-arrays.php` returns both keys; `_wp_emoji_list()` returns an array for each key and is unaffected by repeated calls; the marker region appears exactly once; `wp_staticize_emoji()` output is unchanged                                                                                                                                     |
| `tests/phpunit/tests/cache/groupStats.php`            | the register is empty while tracking is off; hits and misses increment per group with tracking on, including through `get_multiple()`; the group cap saturates without unbounded growth; an unstored group reports a lower bound rather than a wrong count; the two public integers are unchanged in every case; `stats()` escapes what it renders |

Until then, the guards that do exist are: core's own suites (which cover the enqueue system, the emoji
formatting functions and the object cache and all pass unchanged), the 15 build-refusal cases, the
`wpAutoloadClass` class for the autoloader, and the performance suite's own contract tests for the harness.

### 2. The visual-regression suite cannot fail

The plan names `tests/visual-regression/specs/visual-snapshots.test.js` as the guard for the "no admin UI
visual change" boundary. It cannot serve as one in its current state: the snapshot directory
`tests/visual-regression/specs/__snapshots__` **does not exist**, the path is **gitignored**, so no baseline
can be committed, and **no workflow under `.github/workflows/` runs `test:visual`**. A first run therefore
writes new baselines and passes rather than comparing against a known-good reference. Any visual comparison
cited anywhere is an external comparison run, not a gate that would catch a future regression.

**What would close it**: commit authoritative baselines, remove the ignore entry that blocks them, and add a
CI job that compares rather than creates. All three are outside this plan's scope — the plan states that every
file under `.github/workflows/` is unchanged — so this needs the same kind of human decision as the gap above.
It is the highest-value verification gap of the two, because the boundary it is supposed to guard is a
boundary this change set actually touches: the palette gate changes what an admin screen loads.

### 3. The comparator has no end-to-end output test

`isComparableMetric()` and the artifact-validation path are unit-covered, and `compare-results.js` is
structurally asserted to use them, but no test feeds the comparator a fixture before/after pair and asserts
the rendered table or the written Markdown. A small fixture-driven test would cover value formatting,
scenario pairing and cardinality refusal in one place. This one needs no amendment:
`tests/performance/specs/utils.test.js` is already in scope.

---

## Prioritized opportunities discovered but not implemented

Ordered by measured value. Each entry states what blocks it today, so that the next attempt starts from a
measurement rather than from a hypothesis.

1. **Lazy dynamic-block registration — 89 files, and the only remaining pool large enough to pass the
   file-count target.** `wp-includes/blocks/index.php` requires the generated `require-dynamic-blocks.php` at
   file scope, and that file issues one unconditional `require_once` per dynamic-block render file; the
   measured contribution is **89 includes under `wp-includes/blocks/`, identical in both code states**. At the
   measured ≈5,880 bytes of peak memory per deferred file, this pool is also the whole of the remaining
   395,556 B memory gap. Blocked by two constraints: the file is written by `tools/gutenberg/copy.js` and
   declares itself autogenerated, and the plan places the Gutenberg-synced trees out of scope while
   designating `blocks/index.php` a reference. **Needs**: `copy.js` to emit a render-callback or class map
   instead of a require chain, then a re-measurement of rows 1, 4 and 6.
2. **A `get_block_templates()` memo — up to 4 queries on a block-theme front end.** Template resolution
   issues 4 of the canonical request's 71 queries through `locate_block_template` →
   `resolve_block_template` → `get_block_templates()`. Worth **−5.6 %** on row 5 — real, but not the 15 %
   the target needs. Blocked by scope: it lives in `block-template-utils.php`, which the plan does not
   enumerate.
3. **The remaining 53 block-rendering queries.** Three quarters of the canonical request's query count is
   issued inside per-block render callbacks. This is a discovery project rather than a deferral, and it sits
   inside the same excluded tree as item 1. Recorded because row 5 cannot be closed without it.
4. **Grouped comment-status counts — 4 queries on every authenticated request that renders comment totals.**
   Measured 25 → 21 on an authenticated front-end request and 31 → 27 on the Dashboard. Withdrawn here only
   because the target names the _anonymous_ front end; the saving is real for the admin path. **Needs** a
   target scoped to the admin path, and the hook-sensitivity fallback the withdrawn implementation carried.
5. **Batched update-transient reads — 2 queries on authenticated requests.** Same shape, same reason.
6. **Attachment REST per-size recomputation.** `class-wp-rest-attachments-controller.php` loops over
   `media_details.sizes` and calls `wp_get_attachment_image_src()` per size plus once for `full`, and the
   code's own comment concedes it duplicates work the method it calls has already done. CPU-bound rather than
   query-bound, and confined to `/wp/v2/media`, so it moves no target.
7. **A `wp_lazyload_post_meta()` analogue.** `WP_Metadata_Lazyloader` registers `term`, `comment` and `blog`
   but has no post-meta equivalent, while `wp_lazyload_term_meta()`, `wp_lazyload_comment_meta()` and
   `wp_lazyload_site_meta()` all ship. The gap is real; no measured pool on the canonical request needs it,
   because post meta is already primed there.
8. **The non-class require clusters a class map cannot reach.** 119 of the 216 remaining eager targets declare
   functions or run something at file scope, so no class map can defer them. Deferring any of them needs a
   different mechanism — a closure-wrapped `require` at first use, in the style core already ships in
   `blocks.php` — and each one needs its own measurement, because the warm-regime cost measured above applies
   to them too.
9. **A per-group cache report in Site Health or WP-CLI.** The register added here is opt-in and read
   programmatically; nothing surfaces it. A `wp cache stats`-style consumer would make cache behaviour
   visible to people who are not writing a probe.
10. **The 12-file residual deferral.** Measured, safe and available: 78 requires can be removed for 12 fewer
    files. It is not adopted because peak memory is byte-identical and the resolution cost is ≈148 µs. If the
    blocked pool in item 1 is ever unblocked, re-measure this alongside it rather than on its own — the
    trade-off changes once the file count is close to the target.
11. **A fixture-driven comparator output test**, per §_Verification gaps_ item 3.
12. **Committed visual-regression baselines and a CI job to compare them**, per §_Verification gaps_ item 2.
13. **Dedicated committed guards for the four gated behaviours**, per §_Verification gaps_ item 1.
14. **A warm-regime metric in the harness.** Every sample this suite takes is cold-compile by construction, so
    the warm-regime cost visible in the Single Post scenarios is measured only incidentally. A deliberate warm
    arm — same scenarios, no cache reset — would make the autoloader's per-resolution cost a first-class
    number instead of an inference.

---

## Files changed

| Path                                                          | Change                                                                                                                                                                                                                                                                                                                                                                                        |
| ------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/wp-includes/autoload.php`                                | **new** — the `spl_autoload_register()` handler: prefix prefilter, ASCII case fold, one leading-separator strip, static-map lookup, canonical-path guard, one `require_once`, silent miss                                                                                                                                                                                                     |
| `src/wp-includes/autoload-classmap.php`                       | **new, generated** — 143 entries, 13,196 bytes, `sha256 d251ceb7…391b`, reproduced byte for byte by `grunt build:autoload-classmap`                                                                                                                                                                                                                                                           |
| `src/wp-includes/emoji-arrays.php`                            | **new, generated** — the relocated emoji arrays, 142,289 bytes, marker region intact                                                                                                                                                                                                                                                                                                          |
| `src/wp-settings.php`                                         | registers the autoloader; 216 include-family constructs, 107 fewer than base                                                                                                                                                                                                                                                                                                                  |
| `src/wp-includes/script-loader.php`                           | adds `wp_should_load_command_palette_assets()` and consults it in `wp_enqueue_command_palette_assets()`                                                                                                                                                                                                                                                                                       |
| `src/wp-includes/formatting.php`                              | gates the emoji detection script behind a filterable predicate; `_wp_emoji_list()` loads the relocated arrays on demand                                                                                                                                                                                                                                                                       |
| `src/wp-includes/class-wp-object-cache.php`                   | opt-in per-group hit/miss register, bounded and rendered by `stats()`; the two public counters unchanged                                                                                                                                                                                                                                                                                      |
| `Gruntfile.js`                                                | `build:autoload-classmap` and its acceptance checks; `replace:emoji-regex` retargeted at `emoji-arrays.php`; `verify:emoji-markers`; `verify:build-guards`; the `precommit:emoji` trigger retargeted from `js/twemoji.js` (which the generator no longer reads) to `src/wp-includes/emoji-arrays.php`                                                                                         |
| `tools/build/generate-autoload-classmap.php`                  | **new** — the class-map generator (see §_Scope reconciliation_)                                                                                                                                                                                                                                                                                                                               |
| `tests/build/build-guards.test.js`                            | **new** — 15 build-refusal cases run by `verify:build-guards`                                                                                                                                                                                                                                                                                                                                 |
| `tests/performance/wp-content/mu-plugins/server-timing.php`   | five new metrics from both collectors; the authenticated, fail-closed cache-reset control plane                                                                                                                                                                                                                                                                                               |
| `tests/performance/utils.js`                                  | metric classification, deterministic JavaScript byte accounting, artifact validation, cache-reset helper and token provisioning                                                                                                                                                                                                                                                               |
| `tests/performance/compare-results.js`                        | refuses a pair whose scenario sets, metric sets, repetition counts or sample counts disagree; asks `isComparableMetric()` which columns a metric may fill                                                                                                                                                                                                                                     |
| `tests/performance/config/performance-reporter.js`            | writes no results for a failed or empty run; honours `TEST_RESULTS_PREFIX`                                                                                                                                                                                                                                                                                                                    |
| `tests/performance/config/global-teardown.js`                 | **new** — deletes the run's secrets and session state                                                                                                                                                                                                                                                                                                                                         |
| `tests/performance/playwright.config.js`                      | `TEST_RUNS` default, storage-state location, artifacts path                                                                                                                                                                                                                                                                                                                                   |
| `tests/performance/specs/admin.test.js`                       | `domContentLoaded` capture, deterministic JS byte totals, per-locale metric reset                                                                                                                                                                                                                                                                                                             |
| `tests/performance/specs/home.test.js`, `single-post.test.js` | the new front-end metrics, declared and reset per scenario                                                                                                                                                                                                                                                                                                                                    |
| `tests/performance/specs/utils.test.js`                       | **new** — the harness's own contract suite, 94 tests                                                                                                                                                                                                                                                                                                                                          |
| `tests/phpunit/tests/load/wpAutoloadClass.php`                | **new** — five focused concerns for the autoloader: every mapped entry names a readable file; a name the map does not hold is ignored however it is spelled; loading a mapped name declares that name and nothing unrelated, in every casing PHP can present; the committed map describes the tree it ships with; every mapped file declares the mapped symbol and runs nothing at file scope |
| `docs/performance-optimization-report.md`                     | this document                                                                                                                                                                                                                                                                                                                                                                                 |
| `src/wp-includes/capabilities.php`                            | **no change** — the memo the plan scopes was implemented, measured as a net loss and withdrawn                                                                                                                                                                                                                                                                                                |

No file is deleted, no dependency is added, removed or changed, and no file under `.github/workflows/` is
modified.
