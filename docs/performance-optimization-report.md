# Performance optimization report

WordPress core, trunk, `src/` tree. Base commit `5e9d05d7dd`.

This document records seven delivered optimizations, the measurements that justified each one, the six
performance targets those measurements are read against, the work that was considered and rejected on
measurement, and the gaps that remain. Every figure below comes from one retained before/after pair or from
a named isolated experiment, and every one of them was re-measured for this revision; nothing is estimated,
and no figure is carried over from an earlier revision of this document.

**Two of the six targets are met. Four are not.** The unmet four are unmet for reasons that are measured
rather than argued, and each one is priced: §_Why four targets are not met_ names the pool that would have
to move, how large it is, which constraint blocks it, and what a human has to decide.

**Two default behaviours change**, and they are enumerated together in §_Every default behaviour change this
ships_ rather than left to be found in the entries. One of them removes a documented JavaScript global from
some admin screens; its one-line reversal is published there.

---

## How to read the numbers

### Percentages are relative to the _before_ value

A reduction from 510 to 406 is reported as **−20.39 %**, because 104/510 = 20.39 %. Dividing by the after
value instead would report −25.62 % for the same pair, which is the difference between meeting a 20 % target
and missing it. Every percentage in this document divides by the before value.

### The opcode cache decides the magnitude of everything else

Opcode-cache state moves measured wall time and memory by far more than any change under test, so a
before/after pair taken across two regimes reports the regime rather than the change. Six rules follow, and
every measurement in this document obeys them:

1. Both arms use bit-identical interpreter flags, in the same containers, on the same code path.
2. The regime is reported alongside the figures — see §_Measurement environment_.
3. php-fpm is restarted between code states, so no worker generation is shared. This is not theoretical: a
   worker that keeps a compiled file in memory reports the _old_ file count after the source has been
   replaced, which reads as "no regression" and is not one.
4. Peak memory is read with `memory_get_peak_usage( false )`. The `true` variant quantizes to the allocator
   chunk size and is useless against a 10 % target.
5. Every reported figure from the suite is a median over 40 samples. The two isolated experiments state
   their own sample counts, and both are ≥ 15.
6. Each measured request is preceded by an authorized `POST /?clear_cache` that calls `opcache_reset()`, so
   every sample in the suite starts from a discarded opcode cache.

### A proxy metric is not a cost metric

`get_included_files()` counts files; it does not price them. The distinction is load-bearing here, and this
change set contains its own proof in both directions:

-   On the canonical homepage the deferral removes **20.39 %** of the files and comes with **−6.10 %** peak
    memory and **−18.58 %** bootstrap time, so there the count and the cost move together.
-   On the eight Single Post scenarios, whose measured request lands in a warm-compile regime (`wpBootstrap`
    ≈ 25 ms against the homepage's ≈ 290 ms), the same deferral removes **19.56 % to 21.12 %** of the files
    while **costing** 4.75 % to 10.70 % of `wpTotal` and leaving peak memory unchanged to two decimal
    places, because those files were already compiled and only the autoloader's per-resolution work is
    left.

So the file-count target is claimed from the count directly, memory and time are claimed only from their own
measurements, the two are never inferred from each other, and the warm-compile cost is stated as a result in
§_The warm-compile split_ rather than mentioned in passing.

---

## Measurement environment

| Component           | Value                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| ------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Server              | nginx 1.31.3 → php-fpm 8.5.9, docroot `/var/www/src`, base URL `http://localhost:8889`                                                                                                                                                                                                                                                                                                                                                                                                 |
| Database            | MySQL 8.4.11                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| Interpreter regime  | `opcache.enable=On` (php-fpm SAPI), `opcache.enable_cli=Off`, `opcache.jit=disable`, `opcache.memory_consumption=128`, `opcache.validate_timestamps=On`, `opcache.revalidate_freq=2`                                                                                                                                                                                                                                                                                                   |
| Debug flags         | `WP_DEBUG=false`, `WP_DEBUG_LOG=false`, `WP_DEBUG_DISPLAY=false`, `SCRIPT_DEBUG=false`, `SAVEQUERIES=false`, `WP_DEVELOPMENT_MODE=''` — the production-like regime `.github/workflows/reusable-performance.yml:47-50` measures in. `SCRIPT_DEBUG` matters to a target twice over: with it on the admin serves unminified scripts, and with it off `CONCATENATE_SCRIPTS` is active, which is why the JavaScript byte metrics have to match on content type as well as on file extension |
| Isolation           | `WP_HTTP_BLOCK_EXTERNAL=true`, `DISABLE_WP_CRON=true`, set exactly as `reusable-performance.yml:214` and `:218` set them; no plugin active in either arm                                                                                                                                                                                                                                                                                                                               |
| Object cache        | None. `wp_using_ext_object_cache()` is false and `wpExtObjCache` is `no` in all 18 scenarios of both arms. This is the "backend absent" condition, and it is the default here rather than an edge case                                                                                                                                                                                                                                                                                 |
| Content             | `themeunittestdata.wordpress.xml` at the commit CI pins, `b9752e0533a5acbb876951a8cbb5bcc69a56474c`, 563,763 bytes, md5 `9555f7012da5a9ec0019b17a3a248bea`, imported with `--authors=create`. Census measured after import: **49 published posts, 22 published pages, 29 approved comments, 38 attachment posts**. The importer plugin was deactivated afterwards                                                                                                                      |
| Localization        | de_DE packs installed for core, plugins and themes, as CI does                                                                                                                                                                                                                                                                                                                                                                                                                         |
| Permalinks          | `/%year%/%monthnum%/%postname%/` — the value `tools/local-env/scripts/install.js` and CI both use                                                                                                                                                                                                                                                                                                                                                                                      |
| Suite configuration | `TEST_RUNS=20`, `repeatEach=2` → 2 repetitions × 20 iterations = **40 samples per metric per scenario**                                                                                                                                                                                                                                                                                                                                                                                |
| Contexts measured   | **18** — 2 admin locales, 8 homepage theme×locale, 8 single-post theme×locale, over `twentytwentyone`/`twentytwentythree`/`twentytwentyfour`/`twentytwentyfive` × `en_US`/`de_DE`                                                                                                                                                                                                                                                                                                      |
| Suite result        | **820 passed / 0 failed in both arms**; before arm 10.3 m, after arm 9.6 m                                                                                                                                                                                                                                                                                                                                                                                                             |

Docker is available in this environment, so the documented harness ran as documented: `npm run env:start`,
`npm run env:install`, `npm run test:performance`.

### Evidence manifest

| Artifact                                    | Role                                                                                    |   Bytes | SHA-256                                                            |
| ------------------------------------------- | --------------------------------------------------------------------------------------- | ------: | ------------------------------------------------------------------ |
| `artifacts/before-performance-results.json` | **before arm** — the eight runtime files at base `5e9d05d7dd`                           | 199,141 | `41077ce849177db5d03617c1982687f64bbd041ad91c2ea68cdbe40c06fb27f1` |
| `artifacts/performance-results.json`        | **after arm** — the delivered working tree                                              | 199,419 | `ed67df5ab38b415703b1a55dc9e0af632d278b2c530efbe44e5b76278982dd5d` |
| `artifacts/performance-results.md`          | comparator output over that pair, `node ./tests/performance/compare-results.js`, exit 0 |  21,973 | `7f6b27e1c3dff396ed91066409dc31d5011fc1163703020e3ac60073c112f150` |

All three live in the gitignored `artifacts/` directory (`.gitignore:47`), which the performance workflow
uploads wholesale on every run. They are reproducible evidence rather than committed binaries, which is why
every figure that depends on one is also restated in a table in this document. Nothing in this document
cites a scratch file: the checks a reader can re-run are written out as commands in
§_Checks a reader can re-run_.

The comparator enforces the properties the pair has to have and exits non-zero if any of them fails: 18
scenarios in both arms with identical title sets, 2 repetitions per scenario, 40 samples per metric per
scenario in both arms, and identical metric sets per scenario between arms — 13 metrics for an admin
scenario, 14 for a front-end one.

The exact commands that reproduce all three, in order:

```
# before arm - park the eight runtime files to their base content, restart php-fpm, then:
TEST_RESULTS_PREFIX=before npm run test:performance   # writes artifacts/before-performance-results.json
# restore the delivered content, restart php-fpm, then:
npm run test:performance                              # writes artifacts/performance-results.json
node ./tests/performance/compare-results.js           # writes artifacts/performance-results.md
```

**The swap set is eight files**, and both arms were verified by git blob id before and after. Each delivered
id below is the id of the file as it ships in this change set, readable with
`git hash-object <path>`:

| File                                        | Base blob      | Delivered blob | Delivered bytes |
| ------------------------------------------- | -------------- | -------------- | --------------: |
| `src/wp-settings.php`                       | `dab1d8fd4c0d` | `741bc4fe83bf` |          31,606 |
| `src/wp-includes/class-wp-object-cache.php` | `cda63e66d49e` | `9ec1e6312487` |          24,724 |
| `src/wp-includes/formatting.php`            | `2b32b5aafb05` | `f994f85dbe25` |         218,126 |
| `src/wp-includes/script-loader.php`         | `733914d1d365` | `fcfb492413e9` |         167,196 |
| `src/wp-includes/capabilities.php`          | `c5f4099127aa` | `1ee10936ed23` |          47,157 |
| `src/wp-includes/autoload.php`              | absent at base | `5dc35bf9c390` |          10,332 |
| `src/wp-includes/autoload-classmap.php`     | absent at base | `22ed7543a5b7` |          13,196 |
| `src/wp-includes/emoji-arrays.php`          | absent at base | `559fd76fff06` |         142,289 |

`src/wp-includes/capabilities.php` is in that set for the first time in this revision: the memoization an
earlier revision recorded as withdrawn is now delivered, in a different shape, with its own measurement.
See §_Request-scoped memoization of the post capability arms_.

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
`twentytwentyfive` › `en_US`, the default experience of a current install and the heaviest bundled theme on
every dimension; the admin context is Admin › `en_US`. No row is decided by a substituted scenario, a
substituted regime or a substituted metric, and none is left undecided.

| #   | Metric                                 | Method                           | Target |      Before |       After |            Δ | Verdict    |
| --- | -------------------------------------- | -------------------------------- | ------ | ----------: | ----------: | -----------: | ---------- |
| 1   | Front-end TTFB (uncached)              | `tests/performance/` suite       | >=20 % |   482.75 ms |   425.85 ms | **−11.79 %** | ❌ not met |
| 2   | Admin DOMContentLoaded                 | `tests/performance/` suite       | >=15 % |   466.95 ms |   141.00 ms | **−69.80 %** | ✅ met     |
| 3   | Admin JS transfer size (gzipped)       | build-output byte accounting     | >=30 % | 1,158,494 B |   253,787 B | **−78.09 %** | ✅ met     |
| 4   | PHP memory per front-end request       | `memory_get_peak_usage( false )` | >=10 % | 9,943,360 B | 9,337,024 B |  **−6.10 %** | ❌ not met |
| 5   | DB queries per front-end page load     | query count via `Server-Timing`  | >=15 % |          73 |          73 |   **0.00 %** | ❌ not met |
| 6   | PHP files loaded per front-end request | `get_included_files()` count     | >=30 % |         510 |         406 | **−20.39 %** | ❌ not met |

All four unmet rows are read from the same request — Homepage › `twentytwentyfive` › `en_US` — so they are
not four framings of four different requests; they are four properties of one.

Row 2 does not depend on the definition of DOMContentLoaded. The metric recorded is the client-side
interval `domContentLoadedEventEnd − responseEnd`, which excludes server time; the conventional page-level
figure is the sum of two metrics the same navigation records, `timeToFirstByte + domContentLoaded`, and it
moves **921.70 ms → 526.55 ms = −42.87 %** in `en_US` and −42.58 % in `de_DE`. Either definition passes a
15 % target by a wide margin.

Row 3's before and after values are both larger than an earlier revision of this document reported
(1,059,475 → 1,158,494 before; 154,768 → 253,787 after). That is a **measurement correction, not a
regression**: in this regime `CONCATENATE_SCRIPTS` is active, the concatenated admin payload is served from
`wp-admin/load-scripts.php`, and a collector that matched only on a `.js` pathname never saw it. Matching on
content type as well adds the same ≈ 99,019 gzipped bytes to **both** arms. The delta is unchanged in
character and the after figure is still 35 % of the plan's absolute ceiling.

**The spread across all 18 scenarios**, so that the canonical row is visible as a choice rather than as a
best case:

| Metric           | Best scenario                                              |            Δ | Worst scenario                      |        Δ |
| ---------------- | ---------------------------------------------------------- | -----------: | ----------------------------------- | -------: |
| Files loaded     | Homepage `twentytwentythree` en_US, 483 → 379              | **−21.53 %** | Admin de_DE, 532 → 435              | −18.23 % |
| Peak memory      | Homepage `twentytwentythree` en_US                         |  **−9.23 %** | Single Post `twentytwentyone` en_US |  −0.00 % |
| TTFB             | Homepage `twentytwentythree` de_DE                         | **−18.21 %** | Homepage `twentytwentyfive` en_US   | −11.79 % |
| DOMContentLoaded | Admin de_DE, 468.85 → 137.30 ms                            | **−70.72 %** | Admin en_US, 466.95 → 141.00 ms     | −69.80 % |
| DB queries       | _(none — every one of the 18 scenarios is exactly 0.00 %)_ |       0.00 % | —                                   |   0.00 % |

Every one of the 18 scenarios improves on files loaded (−18.23 % to −21.53 %) and on TTFB (−11.79 % to
−18.21 %). No scenario regresses on files, memory, queries or TTFB.

### The warm-compile split

This is the one place where the change set costs something, and it is a result rather than a caveat.

| Group                           | `wpTotal`              | `wpBootstrap`                   | `wpMemoryPeak`    | `wpFilesLoaded`     | TTFB                |
| ------------------------------- | ---------------------- | ------------------------------- | ----------------- | ------------------- | ------------------- |
| Admin + Homepage (10 scenarios) | −12.19 % … −18.65 %    | −16.70 % … −20.61 %             | −5.89 % … −9.23 % | −18.23 % … −21.53 % | −11.79 % … −18.21 % |
| Single Post (8 scenarios)       | **+4.75 % … +10.70 %** | +0.50 % … +7.29 % (one −0.15 %) | −0.10 % … −0.00 % | −19.56 % … −21.12 % | −11.87 % … −14.14 % |

On the Single Post scenarios the measured request is served by a php-fpm worker that has already compiled
the files, so the deferral has nothing left to save and the autoloader's per-resolution work is all that
remains: `wpTotal` rises by 5.68 ms on `twentytwentyone` en_US, against a standard deviation of 4.30 ms and
a MAD of 2.75 ms. Individually that is inside 1.4 standard deviations; across all eight scenarios it is
systematic, so it is reported as a real ≈ 5 ms cost rather than as noise.

Two things are true at once there, and both belong in the record: the server-side request costs more, and
the browser-observed TTFB still improves by 11.87 % to 14.14 %, because the navigation's preceding
cold-compile request improves by much more than the warm one costs.

**What a human is accepting:** on a steady-state production install with OPcache warm and a request shape
that touches nothing new, this change set trades roughly 5 ms of request time for 100 fewer compiled files
and a smaller cold-start cost. That trade is favourable on cold start, after every deploy, on any host with
OPcache off, and on every scenario measured here that is not a warm repeat — and unfavourable on a warm
repeat. Backlog item 14 adds a deliberate warm arm so this stops being incidental to the sampling.

### Not a target, but measured in the same pair

| Metric                              | Context                       |      Before |       After |            Δ |
| ----------------------------------- | ----------------------------- | ----------: | ----------: | -----------: |
| `wpBootstrap`                       | Homepage tt5 en_US            |   351.11 ms |   285.88 ms | **−18.58 %** |
| `wpBootstrap`                       | Admin en_US                   |   362.75 ms |   298.00 ms | **−17.85 %** |
| `wpTotal`                           | Admin en_US                   |   441.75 ms |   372.99 ms | **−15.57 %** |
| `timeToFirstByte`                   | Admin en_US                   |   454.75 ms |   385.55 ms | **−15.22 %** |
| `wpMemoryUsage` (current, not peak) | Homepage tt5 en_US            | 9,237,936 B | 8,561,912 B |  **−7.32 %** |
| `wpMemoryPeak`                      | Admin en_US                   | 7,284,232 B | 6,648,736 B |  **−8.72 %** |
| `wpFilesLoaded`                     | Admin en_US                   |         529 |         429 | **−18.90 %** |
| `largestContentfulPaint`            | Homepage tt5 en_US            |      576 ms |      520 ms |      −9.72 % |
| `adminJsRaw`                        | Admin en_US                   | 3,680,012 B |   904,554 B | **−75.42 %** |
| `wpCacheMisses`                     | every one of the 18 scenarios |   unchanged |   unchanged |       0.00 % |

### Reconciling these numbers with the governing plan's baselines

The governing plan fixes its own baselines in §0.2.1 — **484 files, 5.55 MB peak, 25 queries, 39.75 ms** on
the same canonical homepage, and **3,285,517 B raw / 1,042,614 B gzipped** of admin JavaScript — and derives
absolute ceilings from them: ≤ 338 files, ≤ 4.99 MB, ≤ 21 queries, ≤ 31.80 ms, ≤ 729,830 gzipped bytes. The
before arm above measures 510 files, 9,943,360 B, 73 queries, 482.75 ms and 1,158,494 gzipped bytes. Both
sets are kept here, because discarding either one would hide something.

The plan's figures were taken with the PHP built-in server over the `build/` tree with an empty database and
no opcode cache; these were taken through nginx and php-fpm over `src/` with the pinned mock content, an
authenticated admin session and a cold-compile opcode cache per sample. The two are not comparable
absolutely, which is exactly why every target above is decided on a percentage measured inside one pair.
Against the plan's absolute ceilings, read directly: files **406, over the 338 ceiling**; peak memory
**9,337,024 B, over the 4.99 MB ceiling**; queries **73, over the 21 ceiling**; admin JavaScript
**253,787 B, under the 729,830 B ceiling** at 35 % of it; TTFB not comparable, since 31.80 ms was a
server-side `curl` total under the CLI SAPI and 425.85 ms is a browser TTFB under php-fpm with a cold
opcode cache.

### What the bootstrap looks like before and after

```mermaid
graph TD
    subgraph BEFORE["Before - base 5e9d05d7dd - 510 files on the canonical homepage"]
        B1["wp-settings.php: 323 include constructs"] --> B2["311 plain requires run to completion"]
        B2 --> B3["57 REST controller files parsed"]
        B2 --> B4["wp-admin/includes/plugin.php parsed - 2,665 lines"]
        B2 --> B5["140,933 bytes of emoji arrays tokenized inside formatting.php"]
        B3 --> B6["WP_Site_Health instantiated on every request - 3,868 lines"]
        B4 --> B6
        B5 --> B6
        B6 --> B7["Request served"]
    end
    subgraph AFTER["After - 406 files on the same request"]
        A1["wp-settings.php: 216 include constructs"] --> A2["autoload.php registered before the require region"]
        A2 --> A3["143-entry generated class map, read once on the first core-prefixed name"]
        A3 --> A4["class referenced -> one require, only if referenced"]
        A1 --> A5["plugin.php required only when a plugin is active or is_admin()"]
        A1 --> A6["WP_Site_Health instantiated only for admin, Cron or WP-CLI"]
        A1 --> A7["emoji arrays in emoji-arrays.php, required only when emoji are staticized"]
        A4 --> A8["Request served"]
        A5 --> A8
        A6 --> A8
        A7 --> A8
    end
%% Legend: each AFTER path is a file that is no longer parsed unless something asks for it.
```

---

## Observability: emit the metrics the targets are expressed in

**Bottleneck**: four of the six targets could not be read at all. The performance mu-plugin reported
`memory_get_usage()` — current, not peak — and emitted nothing about files loaded, object-cache behaviour or
bootstrap duration, and the admin spec recorded only `timeToFirstByte`, so the Admin DOMContentLoaded target
had no baseline of any kind. The reporting layer's `formatValue()` recognized three metric keys.

**Root Cause**: the harness was built to answer the questions the suite already asked. Nothing about it was
wrong; it simply did not measure peak memory, file counts, cache hits and misses, bootstrap duration or DOM
readiness, and a target expressed in a metric that is not emitted cannot be met or missed — only asserted.

**Change**: `tests/performance/wp-content/mu-plugins/server-timing.php` emits five new Server-Timing
metrics — `wp-memory-peak` via `memory_get_peak_usage( false )`, `wp-files-loaded` via
`count( get_included_files() )`, `wp-cache-hits` and `wp-cache-misses` read out of `WP_Object_Cache` with
`get_object_vars()` and validated for finiteness and range, and `wp-bootstrap` for the `$timestart` →
`wp_loaded` interval. Peak is sampled before the instrumentation's own buffer handling, so the reading
excludes the instrument. `tests/performance/utils.js` formats them and adds deterministic
`gzipSync({ level: 9 })` byte accounting over the JavaScript responses of a page, matched on `.js` pathname
**and** on a `javascript` content type so a concatenated payload is not missed. The three specs record the
new metrics, and `admin.test.js` adds the DOMContentLoaded capture. The reset that makes every sample a
cold-compile sample is an authorized control plane in the same mu-plugin: a token file outside the document
root, a 256-bit CSPRNG secret presented in a request header, `hash_equals()` comparison, 404/405/403/202
ladder, and a refusal to serve at all if the pre-existing unauthenticated `clear-cache.php` is provisioned
beside it.

**Measurement**: the pair in §_Evidence manifest_ carries **14 metrics × 16 front-end scenarios and 13 × 2
admin scenarios, 40 samples each, in both arms**, and `compare-results.js` exits 0 over it. Before this
change the same command could report three of the six targets and no baseline for a fourth.

**Value**: this is what makes every other entry in this document falsifiable. It is also what caught two
things nothing else would have: that the admin JavaScript payload was being under-counted by ≈ 99,019
gzipped bytes whenever `CONCATENATE_SCRIPTS` is active, and that the Single Post scenarios measure a
warm-compile request while the Homepage and Admin scenarios measure a cold one — the fact §_The
warm-compile split_ is built on.

---

## Core class autoloader with a build-generated static class map

**Bottleneck**: `src/wp-settings.php` at base ran **323** include constructs — 311 `require`, 7
`require_once`, 2 `include`, 3 `include_once` — before any hook fires, and the canonical homepage finished
with **510** files loaded and **9,943,360 B** of peak memory. The largest single cluster was the REST API:
57 files under `wp-includes/rest-api/` parsed on every request, declaring 57 classes, on a request that
never instantiates a REST server.

**Root Cause**: core had no autoloader. The only ones in the tree were vendored, so the bootstrap's only way
to make a class available was to require its file in advance, whether or not the request referenced it.

**Change**: `src/wp-includes/autoload.php` registers one `spl_autoload_register()` handler that resolves a
name through `src/wp-includes/autoload-classmap.php`, a generated file returning **143 entries** (142
classes and 1 interface, 13,196 B, `sha256 d251ceb70bace3b73500e5d4616e2c4e1dcb493e7416bb626a3dcecba483391b`).
Resolution is a lower-cased array lookup with no path derived from the requested name, behind a six-prefix
prefilter so a name that cannot be a core class is rejected without reading the map. `wp` is one of those
prefixes, so a third-party `WP_*` name does pass the prefilter and does trigger the one-time map parse — the
prefilter bounds how often the map is read, not who can cause the first read. The value is checked against a canonical
`wp-includes/`-or-`wp-admin/includes/` pattern anchored with `\z`, and `is_readable()` rather than
`file_exists()` guards both the map and the target, so an existing-but-unreadable file degrades to doing
nothing instead of raising an uncatchable `E_COMPILE_ERROR`. A `CompileError` from a mapped file is
re-raised rather than swallowed, because that says the file is not valid PHP and no other autoloader can
help; any other `Error` is reported under `WP_DEBUG` through `wp_trigger_error()` and absorbed otherwise.
The map is generated by `tools/build/generate-autoload-classmap.php`, wired into the build as
`build:autoload-classmap` with seven fail-closed acceptance checks, and the generator refuses to map any
name the bootstrap still requires at top level. `src/wp-settings.php` keeps **216** constructs, 107 fewer.

**Measurement**: the canonical homepage moves **510 → 406 files (−20.39 %)**, peak memory
**9,943,360 → 9,337,024 B (−6.10 %)**, bootstrap **351.11 → 285.88 ms (−18.58 %)** and TTFB
**482.75 → 425.85 ms (−11.79 %)**; Admin moves 529 → 429 files and 7,284,232 → 6,648,736 B (−8.72 %). All
18 scenarios improve on files loaded. The class map is byte-reproducible: regenerating it from the delivered
tree yields the same 13,196 bytes and the same digest.

**Value**: 104 fewer files compiled per front-end request, 606,336 fewer bytes of peak memory, and 65 ms
less bootstrap time on a cold-compile request — which is every request after a deploy, every request on a
host without an opcode cache, and every first request to a new worker. **The gain is a cold-compile gain and
is not claimed beyond that**: on a warm repeat the same deferral costs about 5 ms, priced in §_The
warm-compile split_. Estimated globally, the file-count reduction is the one figure that holds in every
regime, because it is the count itself.

---

## Deferring the two admin-only files off the front-end path

**Bottleneck**: two admin-only files were parsed on every front-end request. `wp-admin/includes/plugin.php`
(2,665 lines) was required unconditionally before the active-plugin loop, and
`wp-admin/includes/class-wp-site-health.php` (3,868 lines) was loaded by an unconditional
`WP_Site_Health::get_instance()` — 6,533 lines of tokenizing for code an anonymous front-end request cannot
reach. The Site Health case had a second edge: once the class map existed, the `class_exists()` probe in
front of the require resolved the name through the map, so the file loaded on every request exactly as
before, plus one autoload resolution. A mapped entry that defers nothing reads as coverage and is not.

**Root Cause**: `plugin.php` is required at that point so that `get_plugin_data()` is available to the loop
that follows and to any plugin that loads the file itself (`#62244`); the loop does not run when no plugin
is active, and the admin loads the file again through `wp-admin/includes/admin.php` on every screen. The
Site Health instance exists "so that Cron events may fire", and its constructor registers exactly four
callbacks — `admin_body_class`, `admin_enqueue_scripts`, `site_health_tab_content` and
`wp_site_health_scheduled_check` — none of which a front-end request fires.

**Change**: in `src/wp-settings.php`, `wp_get_active_and_valid_plugins()` is called once into a temporary,
the `require_once` runs only `if ( $_wp_active_plugins || is_admin() )`, the loop consumes the temporary and
the existing `unset()` clears it, so no new global survives the bootstrap. The Site Health block is gated on
`is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI )`. The class stays reachable everywhere:
it is in the generated class map, so `class_exists( 'WP_Site_Health' )` and `WP_Site_Health::get_instance()`
resolve on any request — which is what the Site Health REST controller relies on, since
`create_initial_rest_routes()` (`rest-api.php:396`) calls `get_instance()` itself.

**Measurement**: isolated pair on the canonical homepage with php-fpm restarted between code states, 12
samples, medians: `wp-files-loaded` 383 → 381, `wp-memory-peak` 5,767,928 → 5,761,240 B,
`wp-bootstrap` 27.905 → 26.095 ms (**−6.49 %**), `wp-total` 56.39 → 53.875 ms (**−4.46 %**), queries
unchanged. Verified by probe on the delivered tree: an anonymous homepage loads **neither** file; an
authenticated `/wp-admin/` loads both; a REST request loads `class-wp-site-health.php` through the class map
by design and `plugin.php` not at all. The two files are 2 of the 104 in the headline count.

**Value**: 6,533 lines that no longer reach the tokenizer on an anonymous front-end request, and the removal
of a mapped-but-not-deferred class-map entry that was contributing nothing. Every consumer keeps working
without a shim: all 17 call sites in `wp-includes/` that use `plugin.php` functions — the plugins, block
directory and templates REST controllers, `WP_Plugin_Dependencies`, `WP_Recovery_Mode_Email_Service`,
`wp_update_plugins()` and `_wp_connectors_get_connector_script_module_data()` — already require the file
themselves, as they all did before `#62244` added the bootstrap require.

---

## Conditional loading of Command Palette assets

**Bottleneck**: on the admin dashboard the before arm transferred **3,680,012 B raw / 1,158,494 B gzipped**
of JavaScript. `wp_enqueue_command_palette_assets()` was registered on `admin_enqueue_scripts` with no
screen check and no capability gate, and the `wp-commands` and `wp-core-commands` bundles it enqueues drag
in the block editor and component packages behind them.

**Root Cause**: the callback was written to deliver the Command Palette everywhere the admin exists, and the
palette's dependency chain is most of the editor. `common.min.js`, the file usually suspected in a payload
this size, is 0.75 % of it; `js/dist/*` is 91.2 %, and none of it can be split here because that tree is
copied in by `tools/gutenberg/copy.js` rather than built by the core webpack configuration.

**Change**: `src/wp-includes/script-loader.php` gains `wp_should_load_command_palette_assets()`, placed with
the four `wp_should_load_*()` predicates core already ships and modelled on the first of them. It returns
false outside the admin **before** applying its filter, so no filter can widen delivery to an
unauthenticated visitor, and otherwise defaults to `$current_screen->is_block_editor()` — which covers both
editors, since `edit-form-blocks.php:29` and `site-editor.php:126` each set it. It is consulted inside
`wp_enqueue_command_palette_assets()` only while `admin_enqueue_scripts` is running, so an admin page that
calls the enqueue directly still gets the palette by name. The change is purely additive: 69 lines, none
removed, and the `add_action` at `default-filters.php:605` is untouched, so existing `remove_action()`
callers — including the Gutenberg plugin's — keep working.

**Measurement**: Admin › en_US moves **3,680,012 → 904,554 B raw (−75.42 %)** and
**1,158,494 → 253,787 B gzipped (−78.09 %)**, identical in both admin locales, with a standard deviation and
MAD of 0.00 kB across all 40 samples because the payload is deterministic. `wpFilesLoaded` on the same
screen moves 529 → 429 and `domContentLoaded` 466.95 → 141.00 ms. Runtime census on the delivered tree:
Dashboard and Settings serve no `wp-core-commands`; the post editor and the site editor serve it and render
the Ctrl+K button; with `add_filter( 'should_load_command_palette_assets', '__return_true' )` the Dashboard
serves it again and renders the button.

**Value**: 904,707 fewer gzipped bytes on every non-editor admin screen, which is most admin screens and
almost all admin navigation — the single largest transfer reduction in this change set, and the reason the
admin DOMContentLoaded figure moves as far as it does. It ships a user-visible consequence, enumerated with
its reversal in §_Every default behaviour change this ships_.

---

## Gating the emoji detection script and relocating the emoji arrays

**Bottleneck**: two costs in one file. The emoji detection script was printed into the head of every
front-end document: **3,324 B raw, 1,312 B gzipped** measured on the canonical homepage in this regime, on a
page that may contain no emoji at all. Separately, `src/wp-includes/formatting.php` carried **140,933 bytes
of emoji arrays on four physical lines**, tokenized on every request whether anything staticized an emoji or
not.

**Root Cause**: the detection script is a client-side polyfill for browsers that cannot render the emoji
WordPress recognises, and it was unconditional because it predates any mechanism for deciding. The arrays
lived in `formatting.php` because that is where the functions that read them live, and the build task that
maintains them from the pinned Twemoji list matched markers in that file.

**Change**: `wp_should_load_emoji_detection_script( $context )` gates the hooked
`print_emoji_detection_script()`. The default is off **only** in the `'front'` context, which is the one that
was measured; the admin and oEmbed contexts keep the trunk default, because no cost was measured there and
turning them off would change behaviour without a measurement behind it. The context is derived from
`doing_action( 'embed_head' )` and `is_admin()` and passed to the filter, so a site can decide any context
either way. The `static $printed` one-shot is consulted before the gate and set only when the worker is
actually scheduled, so declining does not consume it. The arrays move verbatim to
`src/wp-includes/emoji-arrays.php` (142,289 B, 4,008 entities and 1,438 partials), and `_wp_emoji_list()`
requires it on first use behind a `file_exists()` guard with `is_array()` validation on the outer value and
on each key, so it returns an array on every path. `Gruntfile.js` moves with it in the same change:
`replace:emoji-regex` is retargeted at the new file, `verify:emoji-markers` enforces exactly one marker
region before any network call, and the watch trigger follows. The two edits are atomic — landing the
relocation without the retarget would leave a build task matching nothing.

**Measurement**: front-end document with the detection script forced on against the delivered default:
**247,300 → 243,976 B raw** and **34,850 → 33,538 B at `gzip -9`**, so 3,324 raw and 1,312 gzipped bytes
leave every front-end document. `wp-files-loaded` is unchanged at 406 by the relocation, and the probe says
why: on an anonymous homepage `emoji-arrays.php` **is not loaded at all**, because `_wp_emoji_list()` is
reached only from `wp_encode_emoji()` and `wp_staticize_emoji()`, whose callers are write paths and the
feed and mail filters. The same probe on `/feed/` shows `emoji-arrays.php` **loaded**, which is the
relocation working as intended rather than a file traded for another. The relocated data is verbatim:
`emoji-arrays.php` reproduces trunk's two generated lines exactly.

**Value**: 1,312 gzipped bytes and one head-blocking inline script leave every front-end document, and
140,933 bytes of array literal leave the tokenizer on every request that never staticizes an emoji. Server
side nothing changed: `wp_staticize_emoji()` still runs on `the_content_feed` and `comment_text_rss`, and
`wp_staticize_emoji_for_email()` on `wp_mail`, so **feeds and email still receive emoji fallback images**.
What front-end page HTML loses is the client-side polyfill: a browser that cannot render an emoji now shows
its own fallback rather than a WordPress-supplied image, on the front end only.

---

## Request-scoped memoization of the post capability arms

**Bottleneck**: `map_meta_cap()` is 834 lines with 86 `case` arms and, at base, no memoization of any kind.
Its post edit and delete arms are re-entered with identical arguments many times in one request — a list
table asks for `edit_post` and `delete_post` per row and again for each row action, and every
`get_edit_post_link()` in a loop asks again — and each entry costs a post lookup, a post type object lookup
and a walk through the arm. A list-table-shaped workload of 100 such calls measured **362.16 µs** with the
opcode cache off and **343.80 µs** with it on.

**Root Cause**: the function is a pure mapping from its arguments and the state of the post, and it
recomputed that mapping every time it was asked. An earlier attempt memoized the `default:` arm instead,
which is the cheapest branch in the function, and measured a regression on three of five request shapes
(+5.36 µs to +16.90 µs); that attempt was withdrawn, and this one is not it.

**Change**: `src/wp-includes/capabilities.php` adds a request-scoped static memo covering `edit_post`,
`edit_page`, `delete_post` and `delete_page`. There is nothing to invalidate, because the key carries every
input those arms read: the capability, the user, the post's id, type, status, author and parent, the post
type's `map_meta_cap` flag, a fingerprint of its whole capability map — so a post type re-registered with
different capabilities computes a new entry — and the `wp_page_for_privacy_policy` option. Every case that
answers from something the key does not hold is excluded outright: `read_post` and `read_page` (they resolve
status through the filterable, parent-inheriting `get_post_status()`), a post that does not resolve or whose
type is not registered (both answer through `_doing_it_wrong()`, which has to be raised each time), a
revision, a trashed post, the privacy policy page, and any call carrying more than one argument. Both
`$caps` and `$cap` are memoized, because the arms reassign `$cap` for a post type that maps its own
capabilities and the filter must receive the resolved name. **`apply_filters( 'map_meta_cap', … )` runs on
every call**, memo or not, so a callback added part way through a request still sees every check and a
callback that answers differently per call still decides every call.

**Measurement**: the same workload, base and memo interleaved round by round so drift cannot favour either
arm, 15 timed samples per run, medians: **362.16 → 330.21 µs (−8.8 %)** with `opcache.enable_cli=0` and
**343.80 → 316.14 µs (−8.0 %)** with it on, with the memo faster in **7 of 7 rounds in both regimes**.
Correctness is measured too: 13 committed tests each move one input between two otherwise identical checks
and assert the answer moves with it.

**Value**: about a third of a microsecond per repeated post capability check, or ≈ 32 µs per 100 checks.
**That is immaterial against the six targets and is not claimed against any of them** — it does not appear
in the aggregate table, and the canonical anonymous front-end request does not call these arms at all. What
it is worth is on authenticated screens that check the same post many times, where the work removed is real,
reproducible in both opcode-cache regimes, and free of any invalidation risk by construction. It is
recorded here at its measured size rather than inflated to match the workstream it satisfies.

---

## Per-group object cache hit and miss counters

**Bottleneck**: `WP_Object_Cache` reported `$cache_hits` and `$cache_misses` as two global integers, so a
request could be asked how many hits and misses it had but not which groups they were in. On the canonical
homepage that is 2,551 hits and 373 misses with no attribution.

**Root Cause**: the counters were added for a debug readout, not for attribution, and the group is available
at the call site but was never recorded.

**Change**: `src/wp-includes/class-wp-object-cache.php` adds an opt-in per-group register —
`$cache_group_stats`, `$track_group_stats`, `$max_tracked_groups`, `$untracked_group_count` and a private
name register — reported through `stats()`. Collection is off by default because it is diagnostic work on
every `get()`. `record_group_stat()` is called from exactly the two sites inside `get()`, immediately beside
the two global increments and each behind the same flag, so the global and per-group counts cannot diverge,
and `get_multiple()` delegates to `get()` so nothing is counted twice. Growth is capped at 250 groups
because the group name comes from the caller; groups past the cap are counted as a number of distinct names,
and once that register is full the number is reported as a lower bound. `flush()` and `reset()` keep trunk's
cumulative counter semantics, and `stats()` reconciles by listing groups that have counters but no current
entries. The public `$cache_hits` and `$cache_misses` are untouched for the plugins that read them, all new
properties are declared, and with tracking off `stats()` prints exactly what it printed before.

**Measurement**: `wp-cache-hits` and `wp-cache-misses` are now emitted for every request in the pair —
2,551 → 2,548 hits and 373 → 373 misses on the canonical homepage, and `wpCacheMisses` is unchanged in all
18 scenarios, which is how the pair shows that no optimization here traded a query for a cache miss. With
`$track_group_stats` off, the request cost of the register is zero by construction: the only new work is one
boolean test beside each existing increment.

**Value**: cache behaviour is attributable when something asks for it, and the two figures the harness reads
are now part of every measured request rather than invisible. **It moves none of the six targets and is not
claimed to**; it is what made "no optimization here trades queries for cache misses" a measurement instead
of an assumption. Its reader is `stats()`, which is also the only reader trunk's global counters ever had —
core has never called `stats()` itself. A first-class surface for it (Site Health, or a CLI readout) is
backlog item 9.

---

## Every default behaviour change this ships

Two behaviours differ by default after this change set. Neither is a bug and both are reversible with one
line, and they are listed together here because a reader should not have to assemble the list from seven
entries.

| #   | What changes                                                                                                                                                                                                                                                                      | Where                                             | Reversal                                                               |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------- | ---------------------------------------------------------------------- |
| 1   | The Command Palette is not delivered on admin screens that are not block editor screens. `wp.commands` and `wp.coreCommands` are undefined there, and the admin bar's Ctrl+K button is not rendered, because both callbacks that add it require `wp-core-commands` to be enqueued | Dashboard, Posts, Settings, Media, Site Health, … | `add_filter( 'should_load_command_palette_assets', '__return_true' );` |
| 2   | The emoji detection script is not printed on the front end. The admin and oEmbed contexts are unchanged                                                                                                                                                                           | front-end documents only                          | `add_filter( 'should_load_emoji_detection_script', '__return_true' );` |

Change 1 is a change to a documented interface: the `wp.*` JavaScript global surface. It needs human
sign-off on that basis, not only on the basis of the missing keyboard shortcut. The rollback path above is
the one the quality gates require for a behavioural change to a documented API, it is published in the
predicate's own docblock, and it is verified: with the filter in place the Dashboard serves
`wp-core-commands` again and renders the button.

Change 2's blast radius is narrower than it first appears, and the reason is worth recording.
`_print_emoji_detection_script()` is scheduled on `wp_print_footer_scripts`, and that action fires from
exactly one place — `wp_print_footer_scripts()` at `script-loader.php:2313`, hooked to `wp_footer`, a
front-end-only action. The admin fires `admin_print_footer_scripts` instead. So the admin registration never
rendered the script in trunk either; measured against both code states, `_wpemojiSettings` appears once on
the front end at base and not at all in the admin or in an oEmbed template in **either** arm. Restoring the
admin and embed defaults is therefore about the code path a third party can drive — the Gutenberg plugin
fires `wp_print_footer_scripts` inside an admin request at
`lib/experimental/class-wp-rest-block-editor-settings-controller.php:419` — and about not disabling
something that was never measured.

Nothing else changes by default. `wp_staticize_emoji()`, `wp_staticize_emoji_for_email()`,
`wp_enqueue_emoji_styles()`, every hook name and argument count, every REST route and schema, the
`wp_enqueue_script()`/`wp_enqueue_style()` dependency behaviour, and the public method signatures of
`WP_Query`, `WP_Hook`, `wpdb`, `WP_REST_Server`, `WP_REST_Request`, `WP_REST_Response` and
`WP_Customize_Manager` are all unchanged.

---

## Work considered and not delivered

Everything in this section is **absent from the delivered change set**. It is written up because a
measurement that rejects a change is as much a result as one that accepts it, and because the analysis is
what a future attempt should start from. These entries deliberately do **not** use the five-field
optimization template above: none of them is an optimization this change set delivers, and none of the
figures in this section appears in the aggregate results table.

### Memoizing the `default:` arm of `map_meta_cap()` — withdrawn

_What was measured_: an earlier arm memoized the mapping in a request-scoped global keyed on user ID and
capability name, read and written inside the `default:` arm of the `switch`. Per-request cost against base,
on five request shapes:

| Request shape                      | With memo, OPcache off | With memo, OPcache on |
| ---------------------------------- | ---------------------: | --------------------: |
| Front end, logged out              |               −0.18 µs |              −0.08 µs |
| Front end, logged in               |               −0.94 µs |              −0.01 µs |
| `/wp-admin/` (Dashboard)           |           **+6.67 µs** |         **+10.03 µs** |
| `/wp-admin/edit.php`               |           **+5.36 µs** |          **+8.89 µs** |
| `/wp-admin/post.php?…&action=edit` |          **+12.19 µs** |         **+16.90 µs** |

_Why it is not delivered_: the `default:` arm is the cheapest branch in the function — a ten-name
`str_replace` and one array append — so memoizing it added a probe to the fast path while never touching the
arms that do the expensive work. Three of the five shapes were a clear regression. **The arm-scoped memo
that replaced it is delivered**, measured at −8.8 % and −8.0 %, and documented in §_Request-scoped
memoization of the post capability arms_; this entry is kept only so that the rejected shape is not
attempted again.

### Grouped comment-status counts — withdrawn

_Delivered state_: `src/wp-includes/comment.php` ships byte-identical to base.

_What was measured_: replacing five per-status `WP_Comment_Query` counts in `get_comment_count()` with one
grouped query removed exactly four queries from an **authenticated** request that renders the comment
totals — 25 → 21 on an authenticated front-end request and 31 → 27 on the Dashboard, ten samples each.

_Why it is not delivered_: the target is _"DB queries per front-end page load"_, and on an **anonymous**
front-end request `get_comment_count()` is never reached, so the change moved the targeted metric by
**0.00 %** while modifying a hook-sensitive query path used by every comment screen. The 25 → 21 figure has
nothing to do with row 5 of the results table, and it is quoted here only to record what the withdrawn
experiment measured. The opportunity is real for the admin path and is carried in the backlog.

### Batched update-transient reads — withdrawn

_Delivered state_: `src/wp-includes/update.php` ships byte-identical to base.

_What was measured_: batching the three update transients into one primed read removes two queries from an
authenticated request that renders the update count.

_Why it is not delivered_: the same reason — the queries it removes are removed from authenticated requests,
and the anonymous front end never reads them.

### A bootstrap option primer — rejected on measurement

_What was measured_: priming `wp_enable_real_time_collaboration` and `site_logo` in the bootstrap. With the
primer present, the authenticated front end stayed at 21 queries and Admin used 28; without it, the same
ten-sample medians were 21 and **27**.

_Why it is not delivered_: it delivered no front-end saving and **added** one query to Admin.

### Maximal bootstrap deferral — rejected on measurement

_What was measured_: 78 further requires removed, 138 include constructs, a 221-entry map — 12 fewer files on
the homepage, peak memory identical to the byte, request time unchanged.

_Why it is not delivered_: it improves a proxy metric by about 3 percentage points, costs ≈ 148 µs of
resolution work per request, and still leaves the file-count target far short. The 87 requires it would have
removed are deliberately in place.

### Five harness diagnostics — withdrawn

`bootstrap-valid`, `opcache-enabled`, `opcache-jit` and two process identifiers are not emitted. They
describe the harness rather than the request, and a metric that never varies within an arm cannot be
compared across arms; the interpreter regime is recorded in §_Measurement environment_ instead.

---

## Why four targets are not met

This section prices each miss. In every case the pool that would have to move is named, measured, and
attributed to the constraint that blocks it. In three of the four cases that constraint is an explicit
exclusion in the governing plan, which is cited rather than paraphrased.

### Row 6 — PHP files loaded: −20.39 % against ≥ 30 %

406 files against a ceiling of 357 on this pair's own baseline (30 % of 510), or 338 against the plan's
baseline of 484. **49 more files would have to leave the request.**

There is one pool of that size left, and it is out of scope. Of the 406 files still loaded, **89 are
`wp-includes/blocks/`** — one loader file in `src/` and 87 block files that arrive in `build/` — plus 7 more
under `wp-includes/build/`. Plan §0.3.2.3 excludes everything written by `tools/gutenberg/copy.js` by name,
calls this cluster "the single largest contributor" and "untouchable", and lists
`src/wp-includes/blocks/index.php` as a REFERENCE rather than an edit target. Those files are required by a
generated require chain, not referenced as classes, so a class map cannot reach them at all: closing this row
means changing the generator to emit render callbacks or a map instead of a require chain, in a tree the plan
places out of bounds.

The remaining eager set is not a second pool. `src/wp-settings.php` holds **216** include constructs, of
which **209** have a compile-time literal path and **7** are dynamic or computed (209 + 7 = 216); of the 209
literal targets exactly **1** also has a class-map entry, and that one is the `WP_Site_Health` fallback
described in §_Deferring the two admin-only files off the front-end path_, which sits behind
`class_exists()` and behind an `is_admin() || wp_doing_cron() || WP_CLI` gate. The other 208 stay because
they declare functions, have file-scope side effects, or declare a name the generator rejected as
ineligible — the generator refuses to map any name the bootstrap still requires at top level, which is what
keeps the two sides from colliding. Both figures are re-runnable; the commands are in
§_Checks a reader can re-run_.

### Row 4 — Front-end peak memory: −6.10 % against ≥ 10 %

9,337,024 B against a ceiling of 8,949,024 B on this pair's baseline. **388,000 more bytes would have to
go.** At the 5,830 bytes per file this change set actually achieved (606,336 B over 104 files), that is
about 67 more files — which is the same pool as row 6, and blocked by the same exclusion. This row is not
independently closable: it is row 6 priced in bytes.

### Row 1 — Front-end TTFB: −11.79 % against ≥ 20 %

425.85 ms against a ceiling of 386.20 ms. **39.65 ms more would have to go.** Bootstrap is already down
18.58 % and is now 285.88 ms of the 425.85; the remaining 139.97 ms is template rendering, and 89 of the
files still being compiled are the block files that render it. So this row too is bounded by the pool row 6
names, plus the render phase inside it.

### Row 5 — DB queries: 0.00 % against ≥ 15 %

73 queries in both arms, in **all 18** scenarios. This is the only row with no delivered mechanism at all,
and it is the one row that is not one scope decision away from passing.

Measured attribution of the 73, taken by filtering `query` and classifying each call by its own backtrace,
reproduced identically on two consecutive requests: **54 (74.0 %) are issued inside
`do_blocks()`/`render_block()`/`WP_Block->render()`** in the Gutenberg-synced tree, **5** in block-template
resolution, and the remaining **14** on the core path that plan §0.2.2.5 shows is already batch-primed —
posts, post meta, terms, term meta lazily, authors, parents, thumbnails, users and comments are all primed by
`WP_Query` and by the REST controllers before the loop runs. 54 + 5 + 14 = 73. Plan §0.3.2.3 excludes the
N+1 and batch-priming workstream "in full" on exactly that evidence, and excludes the Gutenberg-synced tree
where three quarters of the queries are issued. The plan's §0.1.4 routes
this target through "cache-layer visibility and option-loading efficiency" instead; the cache-layer half is
delivered and, as its own entry says, moves no queries, and the option-loading half would mean editing
`src/wp-includes/option.php`, which §0.6.1 lists as REFERENCE.

**Every mechanism that could move this row is behind an explicit exclusion in the frozen plan.** That is not
an argument for accepting 0.00 %; it is a statement that the work cannot be done inside the agreed scope.

### What a human has to decide

| #   | Decision                                                                                                                                           | Consequence if taken                                                                                    | Consequence if not                                               |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| 1   | Extend scope to `tools/gutenberg/copy.js` and the generated block require chain, against plan §0.3.2.3                                             | Rows 6, 4 and 1 become reachable; ~89 files, ~500 KB of peak memory and the render phase come into play | Rows 6, 4 and 1 stay unmet at −20.39 %, −6.10 % and −11.79 %     |
| 2   | Extend scope to block rendering and template resolution for row 5, or re-scope row 5 to the admin path, where 25 → 21 was measured                 | Row 5 becomes a discovery project with a measured 74.0 % target pool                                    | Row 5 stays at exactly 0.00 % with no mechanism in the tree      |
| 3   | Accept the warm-compile trade in §_The warm-compile split_ — ≈ 5 ms on a warm repeat for 104 fewer compiled files and a cheaper cold start         | The change set ships as measured                                                                        | The autoloader has to become cheaper per resolution, or come out |
| 4   | Accept the two default behaviour changes in §_Every default behaviour change this ships_, including the `wp.*` surface change, or apply a reversal | The change set ships as measured; row 3's −78.09 % depends on the first of them                         | Reversing change 1 returns row 3 to base and fails that target   |
| 5   | Amend the plan's file list for the two paths in §_Scope reconciliation_, and register this document in `mkdocs.yml`                                | The delivered tree and the plan agree, and this document publishes                                      | Two delivered paths and this deliverable stay outside the plan   |

---

## Verification

### Test suites

| Suite                                | Result                                                                                |
| ------------------------------------ | ------------------------------------------------------------------------------------- |
| PHPUnit, single site, full           | **29,165 tests, 3,441,922 assertions, 0 failures, 0 errors**, 86 warnings, 50 skipped |
| PHPUnit, multisite, full             | **29,957 tests, 3,443,952 assertions, 0 failures, 0 errors**, 86 warnings, 52 skipped |
| PHPUnit, `--group load`              | 188 tests, 1,352 assertions, 0 failures                                               |
| PHPUnit, `--group emoji`             | 50 tests, 112 assertions, 0 failures                                                  |
| PHPUnit, `--group dependencies`      | 359 tests, 941 assertions, 0 failures                                                 |
| PHPUnit, `--group cache`             | 95 tests, 265 assertions, 0 failures                                                  |
| QUnit, `grunt qunit:compiled`        | **456 tests, 0 failed, 0 skipped, 0 todo** across `compiled.html` and `index.html`    |
| E2E, `npm run test:e2e`              | **exit 0 — 23 passed, 2 flaky, 0 failed** (both flakes pass on retry; see below)      |
| Performance suite, both arms         | 820 passed, 0 failed                                                                  |
| Performance suite, post-change smoke | 136 passed, 0 failed                                                                  |
| `compare-results.js` (Gate 2)        | exit 0 over the published pair, reproducing every figure in §_Results_                |
| `node --test tests/build/`           | 15 passed, 0 failed, 0 skipped                                                        |

The single-site count is the multisite count minus the Multisite-only suites, and both are the pre-change
counts plus exactly the 34 cases the four new behaviour test files add — 29,131 + 34 = 29,165 — so no existing
test was displaced or silently redefined.

Neither E2E flake is attributable to this change set, and both were reproduced to confirm it.
`install.test.js` rewrites `wp-config.php` in `beforeEach` and navigates immediately, while the container runs
`opcache.validate_timestamps=On` with `opcache.revalidate_freq=2`; for up to two seconds PHP still compiles
the previous `wp-config.php`, the site still looks installed, and the redirect assertion fails before passing
on retry. Nothing in this change set runs before that decision. `media-upload.test.js` failed once on
`admin-ajax.php?action=rest-nonce` returning 400 and passed on retry; run on its own twice it passed twice.
One further environment note, because it cost a full E2E run to find: `WP_HTTP_BLOCK_EXTERNAL`, which the
performance workflow sets and which the measurement pair needs, also blocks `install.php` from fetching the
language list, so the installer's first screen never renders and that spec cannot pass while it is set. It is
a measurement-time constant in a git-ignored `wp-config.php`, not part of the change set.

All 86 warnings are the pre-existing PHPUnit 9→10 `Expecting E_WARNING … is deprecated` notices raised at
`vendor/bin/phpunit:122`, none of them in a file this change set touches, and the warning and skip counts are
identical to the counts measured before these changes. `phpunit.xml.dist` is untouched, and none of the five
new test files contains `markTestSkipped`, `markTestIncomplete`, an assertion-free test or any output, so the
strict settings (`convertDeprecationsToExceptions`, `failOnRisky`, `beStrictAboutOutputDuringTests`) are
satisfied rather than worked around.

Five test files are added, 181 test cases in total across them:

| File                                                  | Cases | Covers                                                                                       |
| ----------------------------------------------------- | ----: | -------------------------------------------------------------------------------------------- |
| `tests/phpunit/tests/load/wpAutoloadClass.php`        |   147 | every map entry resolves; unmapped names ignored in every casing; the map describes the tree |
| `tests/phpunit/tests/user/mapMetaCapMemoization.php`  |    13 | one input moved per case; filter freshness; trash, revision, privacy page, missing post      |
| `tests/phpunit/tests/cache/objectCacheGroupStats.php` |     8 | opt-in default; attribution; totals reconciled with the globals; the cap; `stats()` output   |
| `tests/phpunit/tests/dependencies/commandPalette.php` |     7 | non-admin refusal before the filter; screen default; the rollback filter; the direct call    |
| `tests/phpunit/tests/formatting/emojiGate.php`        |     6 | per-context default; filter arguments; the one-shot; unchanged registrations                 |

### Static gates

| Gate                                        | Result                                                                                          |
| ------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| `php -l`                                    | clean on all 15 changed and added PHP files                                                     |
| PHPCS, tracked `phpcs.xml.dist`             | **exit 0, zero errors, zero warnings** on the 14 of 15 it covers — see below                    |
| PHPStan 2.1.39, tracked `phpstan.neon.dist` | **exit 0, no errors**, project-wide                                                             |
| PHPStan forced onto the class map generator | **exit 0, no errors** — it reported one `return.missing` before `@return never` was added       |
| `node --check`                              | clean on all 11 changed and added JavaScript files                                              |
| `prettier --check`                          | passes on **10 of 11**; `Gruntfile.js` fails and also fails on trunk's copy, so it is untouched |
| `npm run typecheck:js`                      | exit 0                                                                                          |
| `grunt jshint:corejs`                       | exit 0, 97 files lint free                                                                      |

One of the 15 files is covered by neither configured gate, and it is worth naming rather than averaging away.
`tools/build/generate-autoload-classmap.php` is excluded from PHPCS by `phpcs.xml.dist:91`
(`<exclude-pattern>/tools/*</exclude-pattern>`), and PHPStan's configured `paths:` in
`tests/phpstan/base.neon` cover `src/wp-admin`, `src/wp-includes`, the bundled themes and 13 root PHP files
but **not `tools/` or `tests/`**. So `npm run typecheck:php` does not analyse the generator and neither does
`npm run lint:php`. Of the tracked static gates only `php -l` reaches it.

Both exclusions predate this change set and neither was widened for it. The generator was therefore analysed
out of band, with the project's own PHPStan level and PHP version bounds, which is how the one error it had
was found and fixed. Held additionally against `WordPress-Core` with `--ignore-annotations` — a stricter bar
than any tracked gate applies to `tools/` — it reports 1 error and 14 warnings: seven equals-sign alignment
items, four deliberate `@token_get_all()`/`@chmod()` suppressions that are the correct idiom for tokenizing
arbitrary source and for a best-effort permission change, three `$namespace` parameter names, and one
double-quoted string. They are left alone because no finding raised them, no configured gate checks them, and
the pre-existing tracked `tools/php-ai-client/scoper.inc.php` reports 1 error and 6 warnings under the same
forced standard — so the file is consistent with its neighbours. Closing the gap properly means extending the
gates, which is items 12 and 13 of §_Prioritized opportunities discovered but not implemented_, not
hand-styling one file against a standard the repository does not apply there.

### Build and drift

`npm run build` followed by `npm run build:dev` under Node 20.20.2, then `git status`: **no drift**. No file
under `src/js/**` changed, so no built JavaScript needed regenerating; the emoji data moved to a new file
that `buildFiles` already globs, so `copy:files` carries it without wiring. `build/wp-includes/autoload.php`,
`build/wp-includes/autoload-classmap.php` and `build/wp-includes/emoji-arrays.php` are present and
byte-identical to `src/`. The class map is byte-reproducible: regenerating it yields the same 13,196 bytes
and the same SHA-256. `verify:build-guards` is no longer part of `verify:build`, so a production build no
longer spawns Grunt subprocesses against sandbox trees; it runs from `precommit` instead, reached through
`prerelease` whenever `Gruntfile.js`, `package.json` or `composer.json` changes — which is when a build task
guard can break — and from the branch used when neither git nor svn is available.

`grunt build` now needs a `php` executable on PATH, twice: to run the class map generator, and to `php -l`
its output. Trunk's build spawned only `node` and `composer`. Both call sites classify a missing executable
explicitly rather than failing opaquely, and three workflows run the core build without provisioning PHP —
`reusable-build-package.yml`, `reusable-test-gutenberg-build-process.yml` and
`test-and-zip-default-themes.yml` — so they rely on the runner image supplying one. That is a new build
dependency and it is listed in the backlog as item 12.

### Runtime checks

Each row was observed on the delivered tree with php-fpm restarted first.

| Check                                     | Observation                                                                                                                                         |
| ----------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| Front end, admin, REST, Cron              | `/` 200, `/wp-admin/` 302 anonymous and 200 authenticated, `/?rest_route=/wp/v2` 200, `/wp-cron.php` 200                                            |
| Admin screens                             | `site-health.php`, `plugins.php`, `post-new.php`, `site-editor.php` all 200                                                                         |
| Command Palette by screen                 | Dashboard and `options-general.php`: no `wp-core-commands`, no Ctrl+K node. Post editor and site editor: `wp-core-commands` present, node rendered  |
| Command Palette rollback                  | Dashboard with `should_load_command_palette_assets` filtered true: `wp-core-commands` present, node rendered                                        |
| Emoji detection script by context         | base: front end once, admin none, oEmbed none. Delivered: front end none, admin none, oEmbed none                                                   |
| `plugin.php` / `class-wp-site-health.php` | anonymous homepage: neither loaded. Authenticated admin: both loaded. REST: Site Health through the class map, `plugin.php` not loaded              |
| `emoji-arrays.php`                        | anonymous homepage: not loaded. `/feed/`: loaded, which is `wp_staticize_emoji()` reaching it                                                       |
| Mapped classes resolve                    | `class_exists( 'WP_REST_Posts_Controller' )`, `class_exists( 'WP_Site_Health' )` and `function_exists( 'wp_autoload_class' )` all true under WP-CLI |

### Security invariants

The governing plan's Gate 7 has two clauses: deferred loading must not bypass capability checks, nonce
verification or authentication, and conditional asset loading must not expose privileged JavaScript to
unauthenticated users. Both hold here as structural properties rather than as behaviour that happened to
test clean, so each is stated with the check that establishes it.

**The five primitives the plan names are never deferred.** `wp_authenticate`, `check_ajax_referer`,
`wp_verify_nonce` and `auth_redirect` are all declared in `src/wp-includes/pluggable.php`, and
`current_user_can` in `src/wp-includes/capabilities.php`. Both files are still required unconditionally, at
`src/wp-settings.php:604` and `src/wp-settings.php:218` respectively; neither of those two requires is among
the 107 this change set removed. Neither file contributes an entry to the class map either, so there is no
request shape on which one of them arrives late:

```
php -r '$m = require "src/wp-includes/autoload-classmap.php"; $n = 0; foreach ( $m as $k => $v ) { if ( in_array( basename( $v ), array( "pluggable.php", "capabilities.php", "class-wp-user.php", "class-wp-roles.php", "user.php" ), true ) ) { echo "IN MAP: $k => $v\n"; ++$n; } } echo $n, " of ", count( $m ), " entries are auth or capability files\n";'
```

**Both gates can only subtract.** `wp_should_load_command_palette_assets()` returns `false` as its first
statement when `! is_admin()`, so no front-end request can be talked into the palette bundles by any
filter — the filter is not reached. Inside the admin the predicate reads `$current_screen->is_block_editor()`,
a value the admin has already computed, and the only thing the callback does with a `false` is return before
enqueueing. The emoji gate is the same shape: it either prints the inline detection script or does not. No
capability, nonce or ownership decision is made on either path, and neither can grant a request something it
was not already entitled to. The rollback filter in §_Conditional loading of Command Palette assets_ restores
the bundles for authenticated admin screens only, for the same reason.

**REST permission callbacks register identically.** They are registered inside `create_initial_rest_routes()`,
which runs in full whenever a REST route is dispatched, and deferring the controller classes does not touch
it. The sorted set of route names is byte-identical across the two arms of the pair — not merely the same
count — with php-fpm restarted between them:

| Arm       | Routes | SHA-256 of the sorted route names                                  |
| --------- | -----: | ------------------------------------------------------------------ |
| Base      |    108 | `955c8273f0901edac7ef9661097afddc79865d96297f10ae53cd779345b1daaf` |
| Delivered |    108 | `955c8273f0901edac7ef9661097afddc79865d96297f10ae53cd779345b1daaf` |

The absolute number is 108 rather than the 106 recorded in the plan's own earlier experiment because route
registration depends on the installed post types and taxonomies and that experiment ran against a different
installation. Parity across the arms is the invariant here; the absolute count is a property of the fixture.

**The capability memo cannot leak a decision across users.** `(string) $user_id` is part of the key, together
with the capability and the post's identity, type, status, author and parent. A memo hit still runs
`apply_filters( 'map_meta_cap', … )` with the real `$user_id` and `$args`, so anything auditing or amending
the mapping sees every call, and the memo is skipped entirely whenever a non-core callback is on that filter.
The store is a request-scoped `static`, so nothing survives the request.

**The harness control plane is opt-in, and its one trade-off is written down rather than glossed.** The
cache-reset endpoint does not exist until a token file is provisioned; the secret is 256 bits of CSPRNG
output compared with `hash_equals()`, presented in a request header rather than a query argument so it cannot
be replayed by a navigation, a prefetch, an `img` tag or a cross-origin form, and it never appears in a URL,
a diagnostic or a test result. The file is created exclusively (`flag: 'wx'`) in the gitignored `.cache`
directory, which is outside the document root the harness serves, and `globalTeardown` deletes it. It is
written mode `0o644` — world readable — because PHP-FPM runs as a different user than the test runner and has
to read it. That is a real trade and `tests/performance/utils.js` states it in those terms: any local user of
the host can read the file while it exists, what it authorizes is flushing the caches of a throwaway test
installation, and a deployment where that matters should point `WP_PERF_CACHE_RESET_TOKEN_FILE` at a path
readable only by the runner and the PHP user, which both ends honour. The endpoint also refuses to serve at
all when the pre-existing unauthenticated `clear-cache.php` is provisioned beside it, so the two cannot be
installed together and leave an unauthenticated flush reachable.

**Two hygiene rules for whoever re-creates an evidence directory.** Neither is a defect in the delivered
tree; both are ways the previous round's evidence directory went wrong, recorded so the next one does not.
First, a copy of `wp-config.php` must never be written under `artifacts/`: an earlier evidence directory
contained one, complete with the installation's database credentials, and `artifacts/` is uploaded wholesale
by the workflow. That directory is not in this tree — the only files under `artifacts/` are the three named
in §_Evidence manifest_ plus Playwright's own `test-results/.last-run.json`. Second, an authenticated admin
session is a credential. `tests/performance/playwright.config.js:29-43` moves this suite's storage state out
of the uploaded tree to `.cache/performance-storage-states/admin.json`; `tests/e2e/playwright.config.js:13`
still defaults its own into `artifacts/storage-states/admin.json`. The stale file left there by an earlier run
has been removed, but the E2E default is unchanged because that file is outside the plan's scope — the same
relocation is item 16 of §_Prioritized opportunities discovered but not implemented_.

### Checks a reader can re-run

These are the checks behind the claims above, written as commands against the committed tree rather than as
references to a scratch file. Each one is independent of the measurement pair.

```
# The class map is generated, not maintained: this must leave the file unchanged.
php tools/build/generate-autoload-classmap.php src/ && git diff --exit-code src/wp-includes/autoload-classmap.php

# The include census in the bootstrap: 323 at base, 216 as delivered.
git show 5e9d05d7dd:src/wp-settings.php > /tmp/base-wp-settings.php
php -r '$c=0; foreach ( token_get_all( file_get_contents( $argv[1] ) ) as $t ) { if ( is_array( $t ) && in_array( $t[0], array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ), true ) ) { ++$c; } } echo $c, "\n";' /tmp/base-wp-settings.php
php -r '$c=0; foreach ( token_get_all( file_get_contents( $argv[1] ) ) as $t ) { if ( is_array( $t ) && in_array( $t[0], array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ), true ) ) { ++$c; } } echo $c, "\n";' src/wp-settings.php

# The class map's own shape: 143 entries, and every value readable.
php -r '$m = require "src/wp-includes/autoload-classmap.php"; echo count( $m ), " entries\n"; foreach ( $m as $k => $v ) { if ( ! is_readable( "src/" . $v ) ) { echo "UNREADABLE $k => $v\n"; } }'

# The emoji data is a verbatim relocation: one marker region there, none left behind.
grep -c 'START: emoji arrays' src/wp-includes/emoji-arrays.php src/wp-includes/formatting.php
php -r '$d = require "src/wp-includes/emoji-arrays.php"; echo count( $d["entities"] ), " entities, ", count( $d["partials"] ), " partials\n";'

# The gates behave as documented, without a browser.
vendor/bin/phpunit -c phpunit.xml.dist --group emoji
vendor/bin/phpunit -c phpunit.xml.dist tests/phpunit/tests/dependencies/commandPalette.php
vendor/bin/phpunit -c phpunit.xml.dist tests/phpunit/tests/user/mapMetaCapMemoization.php
vendor/bin/phpunit -c phpunit.xml.dist tests/phpunit/tests/cache/objectCacheGroupStats.php
```

---

## Scope reconciliation: the change set against the governing plan's file list

Plan §0.6.1 enumerates 16 create-or-update paths and a trailing-pattern section that reaches the rest of
`tests/performance/**`. The delivered tree changes 27 tracked paths: the 16 enumerated ones, 5 more under
`tests/performance/**` that the trailing pattern covers, 4 further PHPUnit test files, and 2 that the plan's
list does not contain.

Every enumerated path is delivered, including `src/wp-includes/capabilities.php`, which an earlier revision
of this document recorded as an update that shipped byte-identical to base. It is no longer byte-identical;
see §_Request-scoped memoization of the post capability arms_.

Four test paths are delivered beyond the one the plan enumerates
(`tests/phpunit/tests/load/wpAutoloadClass.php`). They exist because four behaviours this change set adds —
the Command Palette gate, the emoji gate, the capability memo and the per-group cache register — otherwise
shipped with no committed coverage at all, which no amount of measurement substitutes for. They add tests
and change nothing else.

### The two paths outside the list

| Path                                         | Why it exists                                                                                                                                                                                                                                                                                                                                                                                                              |
| -------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tools/build/generate-autoload-classmap.php` | The plan requires a build-generated class map but names no generator. Deciding whether a file is safe to autoload means knowing what it declares and whether it has file-scope side effects, which is PHP tokenizer work; writing it in JavaScript would mean reimplementing that and would move the file outside `php -l` and PHPCS, both of which cover it today. `tools/` PHP tooling is existing repository convention |
| `tests/build/build-guards.test.js`           | `build:autoload-classmap` and `verify:emoji-markers` exist to refuse a bad result, and a build that goes well exercises none of those refusals. These 15 cases drive them against sandbox trees                                                                                                                                                                                                                            |

Both need the plan's file list amended, or the paths removed. This document requests the amendment rather
than assuming it.

One more amendment is needed and is not about a source file: `mkdocs.yml` carries an explicit three-entry
`nav:` (`index.md`, `project-guide.md`, `technical-specifications.md`) which does not include this document,
and `docs/index.md` does not link it, so **this deliverable does not currently publish in the docs site**.
Both files are outside the plan's file list, so registering it needs the same amendment as the two paths
above rather than a unilateral edit.

---

## Verification gaps: what this change set does _not_ prove

### 1. Two refusal branches in the autoloader are not unit-testable as written

`wp_autoload_class()` memoizes the class map in a function static, so no test in the same process can point
it at a different map, and PHPUnit runs as root in this environment, so a file with mode `000` is still
readable. The `is_readable()` refusals and the `CompileError` re-raise are therefore covered by inspection,
`php -l`, PHPCS and PHPStan rather than by a test. The 147 cases in `wpAutoloadClass.php` cover every
mapped entry, every casing and the map-versus-tree consistency; they do not cover those two branches.

### 2. The visual-regression suite cannot fail

The plan names `tests/visual-regression/specs/visual-snapshots.test.js` as the mechanism for the "no admin UI
visual change" boundary. It has no committed baselines, `__snapshots__` is gitignored, and no workflow runs
`test:visual`. So the guard the plan designates for the boundary this change set comes closest to touching
cannot currently fail. The Command Palette consequence is instead established by the runtime census in
§_Verification_ and by `commandPalette.php`. Committing baselines and adding a comparing job is backlog
item 10; all three files involved are outside the plan's file list.

### 3. The comparator has no end-to-end output test

`compare-results.js` refuses malformed input in eleven ways and `specs/utils.test.js` asserts the metric
vocabulary, the reset ladder and the reporter contract, but nothing asserts the rendered Markdown of a
complete comparison. The three artifacts in §_Evidence manifest_ are that assertion by hand, once.

### 4. Row 5 has no mechanism, so there is nothing to test

No change in this set touches a query path. `wpDbQueries` is unchanged in all 18 scenarios, which is the
strongest statement available: not that queries were reduced, but that nothing here traded a query for
anything else.

### 5. One existing test now carries a comment that no longer describes it

`tests/phpunit/tests/template.php:1964` touches `wp-includes/js/wp-emoji-loader.js` before asserting, with
the comment "`_print_emoji_detection_script()` assumes `wp-includes/js/wp-emoji-loader.js` is present." With
the front-end default now `false`, that setup step is moot for the default path — the worker is not reached.
The test still passes, and it is left alone deliberately: it is not this change set's file, editing it would
be the unrelated cleanup Gate 5 prohibits, and the `touch()` is still correct for any caller that filters the
gate back on. It is recorded here because the comment is now quiet evidence of a default that changed, and a
maintainer reading it in isolation would draw the wrong conclusion about what the emoji worker assumes.

---

## Prioritized opportunities discovered but not implemented

Ordered by the size of the pool each one addresses, which is not the same as the order they should be done
in. Items 1-4 need a scope decision before they can be attempted at all.

1. **Have `tools/gutenberg/copy.js` emit a class map or render callbacks instead of a require chain.** 89
   files, the largest remaining pool, and the blocker on rows 6, 4 and 1 simultaneously. Out of scope under
   plan §0.3.2.3.
2. **Attribute and reduce the 54 block-rendering queries.** 74.0 % of the front-end query count, issued
   inside `do_blocks()`/`render_block()`. The only route to row 5 that addresses where the queries actually
   are. Out of scope under the same section.
3. **Batch the 5 queries in block-template resolution.** `src/wp-includes/block-template-utils.php`, which
   is not in the plan's file list.
4. **Re-scope row 5 to the authenticated admin path** and land the two withdrawn query reductions there
   (grouped comment counts, batched update transients), which measured 31 → 27 on the Dashboard.
5. **Reduce the autoloader's per-resolution cost** so that the warm-compile regime stops paying ≈ 5 ms. A
   sorted or partitioned map, or resolving without re-reading the whole array, are the obvious candidates;
   each needs its own before/after pair in the warm regime specifically.
6. **The per-image-size recomputation in the attachments REST controller.**
   `src/wp-includes/rest-api/endpoints/class-wp-rest-attachments-controller.php:1088-1104` calls
   `wp_get_attachment_image_src()` once per registered size plus once for `full`, and the method's own
   comment concedes it duplicates work. CPU-bound rather than query-bound, and confined to `/wp/v2/media`,
   so it moves none of the six targets.
7. **A `wp_lazyload_post_meta()` analogue.** `WP_Metadata_Lazyloader` registers `term`, `comment` and `blog`;
   `wp_lazyload_term_meta()`, `wp_lazyload_comment_meta()` and `wp_lazyload_site_meta()` all ship and there
   is no post-meta equivalent.
8. **The non-class require clusters a class map cannot reach.** 208 of the 216 remaining constructs target
   files that declare functions, have file-scope side effects, or declare a name the generator rejects.
   Reaching them means a different mechanism — closure-wrapped requires at the call site, in the style
   `blocks.php:568-572` already ships.
9. **A first-class reader for the per-group cache register.** Site Health, or a `wp cache stats`-style
   readout, so the register has a surface in core rather than only `stats()`.
10. **Commit visual-regression baselines, drop the `__snapshots__` ignore, and add a comparing CI job**, so
    that the guard the plan designates for admin appearance can fail. All three paths are outside the plan's
    file list.
11. **Concatenation-aware JavaScript byte accounting on the front end.** The admin collector now matches on
    content type as well as extension; the front-end specs do not collect JavaScript bytes at all, so a
    front-end payload target would need that added.
12. **Provision or pin `php` in the three workflows that run the core build without it**, now that
    `build:autoload-classmap` needs it: `reusable-build-package.yml`,
    `reusable-test-gutenberg-build-process.yml`, `test-and-zip-default-themes.yml`.
13. **Bring `tools/` inside the static gates.** Extend PHPStan's `paths:` in `tests/phpstan/base.neon` so the
    class map generator is analysed by `npm run typecheck:php` rather than only by a separate invocation, and
    decide whether `phpcs.xml.dist:91`'s `/tools/*` exclusion should stay now that a build step lives there.
    Both files are outside the plan's file list, and both exclusions predate this change set.
14. **Add a deliberate warm-compile arm to the performance suite.** The split in §_The warm-compile split_
    is currently a by-product of which scenarios happen to hit a warm worker. A named warm arm would make
    the trade a first-class measurement instead of an observation.
15. **A second reset mode for the harness** that flushes the object cache without resetting the opcode
    cache, so the two regimes can be measured deliberately rather than inferred from scenario order.
16. **Apply this suite's storage-state relocation to the E2E suite.** Not a performance item; a hardening
    one, which is why it is last rather than ranked by pool size. `tests/e2e/playwright.config.js:13`
    defaults its authenticated admin session into `artifacts/storage-states/admin.json`, and
    `reusable-end-to-end-tests.yml` uploads `path: artifacts` wholesale with `include-hidden-files: true` and
    `if: always()`. `tests/performance/playwright.config.js:29-43` shows the fix. Both files are outside the
    plan's list, and the hazard predates this change set.

---

## Files changed

27 tracked paths, no deletions, no dependency changes.

### Source, 8 paths

| Path                                        | Change                                                                                                 |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| `src/wp-settings.php`                       | autoloader registered before the require region; 107 requires deferred; the two admin-only files gated |
| `src/wp-includes/autoload.php`              | new: the `spl_autoload_register()` handler                                                             |
| `src/wp-includes/autoload-classmap.php`     | new, generated: 143 entries                                                                            |
| `src/wp-includes/emoji-arrays.php`          | new, generated: the relocated emoji data, verbatim                                                     |
| `src/wp-includes/formatting.php`            | the emoji detection gate with its context; `_wp_emoji_list()` loads the relocated data                 |
| `src/wp-includes/script-loader.php`         | `wp_should_load_command_palette_assets()` and its use; additive only                                   |
| `src/wp-includes/capabilities.php`          | the request-scoped memo on the post edit and delete arms                                               |
| `src/wp-includes/class-wp-object-cache.php` | the opt-in per-group hit and miss register, reported through `stats()`                                 |

### Build and tooling, 2 paths

| Path                                         | Change                                                                                                                                                                                  |
| -------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Gruntfile.js`                               | `build:autoload-classmap` with seven acceptance checks; `replace:emoji-regex` retargeted; `verify:emoji-markers`; guards moved out of `verify:build`; missing `php` reported explicitly |
| `tools/build/generate-autoload-classmap.php` | new: the class map generator — outside the plan's file list                                                                                                                             |

### Harness and tests, 12 paths

| Path                                                        | Change                                                                     |
| ----------------------------------------------------------- | -------------------------------------------------------------------------- |
| `tests/performance/wp-content/mu-plugins/server-timing.php` | five new metrics; the authorized cache-reset control plane                 |
| `tests/performance/utils.js`                                | formatting for the new metrics; deterministic gzip byte accounting         |
| `tests/performance/compare-results.js`                      | contract enforcement over the pair                                         |
| `tests/performance/specs/admin.test.js`                     | DOMContentLoaded; JavaScript bytes by extension and content type           |
| `tests/performance/specs/home.test.js`                      | the new front-end metrics                                                  |
| `tests/performance/specs/single-post.test.js`               | the new front-end metrics                                                  |
| `tests/performance/specs/utils.test.js`                     | new: the harness's own contract                                            |
| `tests/performance/config/performance-reporter.js`          | refuses to publish results from a failed or empty run                      |
| `tests/performance/config/global-teardown.js`               | new: removes the session and the run token                                 |
| `tests/performance/playwright.config.js`                    | keeps the authenticated session out of the published artifacts             |
| `tests/build/build-guards.test.js`                          | new: 15 cases over the two generation tasks — outside the plan's file list |
| `tests/phpunit/tests/**` (5 files)                          | `wpAutoloadClass.php` plus the four gates and the memo                     |

### Documentation, 1 path

`docs/performance-optimization-report.md` — this document.
