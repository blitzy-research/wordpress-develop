# Performance optimization report

WordPress core, trunk, `src/` tree. Base commit `5e9d05d7dd`.

This document records **thirteen delivered optimizations**, the measurements that justified each one, the six
performance targets those measurements are read against, the work that was considered and rejected on
measurement, and the gaps that remain. Every figure below comes from one retained before/after pair or from a
named isolated experiment, and every one of them was re-measured for this revision; nothing is estimated, and no
figure is carried over from an earlier revision of this document.

**Four of the six targets are met in the configuration a site gets by default. Two are not.**

| #   | Target                                          |        Δ | Verdict    |
| --- | ----------------------------------------------- | -------: | ---------- |
| 1   | Front-end TTFB (uncached), ≥ 20 %               | −20.95 % | ✅ met     |
| 2   | Admin DOMContentLoaded, ≥ 15 %                  | −72.42 % | ✅ met     |
| 3   | Admin JS transfer size gzipped, ≥ 30 %          | −83.37 % | ✅ met     |
| 4   | PHP peak memory per front-end request, ≥ 10 %   |  −9.69 % | ❌ not met |
| 5   | DB queries per front-end page load, ≥ 15 %      | −16.67 % | ✅ met     |
| 6   | PHP files loaded per front-end request, ≥ 30 %  | −27.48 % | ❌ not met |

Nothing here is met by opting in. There is no second configuration, no filter to set and no arm to select: the
numbers above are what an unconfigured install measures. §_Why two targets are not met_ prices the two that are
not — the pool that would have to move, how large it is, which constraint blocks it, and what a human has to
decide — and its central finding is that both misses are the same pool counted twice: the 89 files of
`wp-includes/blocks/`, which the governing plan §0.3.2.3 excludes by name and calls "untouchable". **The
arithmetic maximum reachable inside the plan's scope is 349 files, or −27.89 %, against a requirement of ≤ 338.8.**

This is the third revision of this document, and two of its predecessors' headline claims are reversed here
rather than quietly amended. The first reported two of six met and reached them by declining the Command Palette
everywhere; the second reverted that, restored the palette to every admin screen, and reported **none** of the
six met. Both were wrong in the same way: they treated the palette as an all-or-nothing choice. The delivered
gate decides **by screen**, which is what the governing plan §0.5.1.3 and §0.5.1.6 actually specify — the
enqueues are "skipped on screens where the palette is not used" — so the block editor is byte-for-byte unchanged
from base while three ordinary admin screens stop receiving 900 KB of gzipped JavaScript they never used. That
distinction is what turns rows 2 and 3 from "opt-in" into "met", and it is measured on both arms across four
screens in §_Conditional loading of Command Palette assets_.

**Two default behaviours change**, and both are stated in §_Every default behaviour change this ships_ with a
one-line reversal each, rather than left to be found in an entry: the emoji detection script is no longer printed
on the front end, and the Command Palette is delivered on block-editor screens rather than on every admin screen.
Nothing else a site can observe changes by default.

---

## How to read the numbers

### Percentages are relative to the _before_ value

A reduction from 484 to 351 is reported as **−27.48 %**, because 133/484 = 27.48 %. Dividing by the after
value instead would report −37.89 % for the same pair, which is the difference between missing a 30 % target
and comfortably clearing it. Every percentage in this document divides by the before value.

### Units, STD and MAD

Where a target is expressed in bytes the figure is in bytes. Where one is quoted as `MB` or `kB` it is the
harness's own formatting, which is **decimal**: `tests/performance/utils.js:563` divides by 10^6 for
`wpMemoryPeak` and `wpMemoryUsage`, and `:567` by 10^3 for `adminJsRaw` and `adminJsGzipped`. So 6,606,328 B
appears here as 6.61 MB, not as 6.30 MiB.

`STD` and `MAD` are the two spread columns `compare-results.js` prints beside every metric, and both are the
harness's estimators rather than a convention assumed here. `standardDeviation()`
(`tests/performance/utils.js:578-589`) divides the squared deviations by the sample count, so it is the
**population** standard deviation, not the Bessel-corrected sample one. `medianAbsoluteDeviation()` (`:591-598`)
is the **median absolute deviation** — the median of the absolute deviations from the median. Every spread
figure in this document is one of those two, over the same samples the median came from — 20 for every suite
figure, per rule 5 below — so each can be checked against the comparator's own output instead of being
recomputed with a different estimator.

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
5. Every reported figure from the suite is a median over **40 samples** — `TEST_RUNS=20` with `repeatEach=2`,
   and `accumulateValues()` at `tests/performance/utils.js:677` concatenates the two repetitions before the
   median is taken, so the series the comparator sees is one series of 40 rather than two of 20. The isolated
   experiments state their own sample counts, and all are ≥ 12.
6. Each measured request is preceded by an authorized `POST /?clear_cache` that calls `opcache_reset()`, so
   every sample in the suite starts from a discarded opcode cache.

### A proxy metric is not a cost metric

`get_included_files()` counts files; it does not price them. The distinction is load-bearing here, and this
change set contains its own proof in both directions:

-   In the cold-compile regime the suite measures, removing 133 files from the canonical homepage comes with
    **−9.69 %** peak memory and **−22.82 %** bootstrap time, so there the count and the cost move together.
-   In a warm regime the same removal buys nothing measurable. The isolated front-end pair in
    §_An independent pair whose baseline is the plan's own number_ measures the identical **−27.48 % files**
    alongside **−0.26 % peak memory**, and three interleaved rounds of `wp-total` that read −6.80 %, **+2.55 %**
    and −2.80 % — a spread that disagrees in sign and therefore resolves nothing. Those files were already
    compiled, so nothing was saved by not compiling them again.
-   And a file count can move the wrong way for the right reason. A variant that deferred 77 more requires than
    the delivered state reached a *lower* file count while **increasing** peak memory by 12.5 KB, because the
    larger class map cost more than the two files it saved.
    §_Maximal bootstrap deferral — rejected on measurement_ records it.

So the file-count target is claimed from the count directly, memory and time are claimed only from their own
measurements, and the two are never inferred from each other.

---

## Measurement environment

| Component           | Value                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| ------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Server              | nginx → php-fpm 8.5.9, docroot `build/`, base URL `http://localhost:8890`                                                                                                                                                                                                                                                                                                                                                                                                               |
| Database            | MariaDB 10.11                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| Interpreter regime  | `opcache.enable=On` (php-fpm SAPI), `opcache.enable_cli=Off`, `opcache.jit=disable`, `opcache.memory_consumption=128`, `opcache.validate_timestamps=On`, `opcache.revalidate_freq=2`                                                                                                                                                                                                                                                                                                   |
| Debug flags         | `WP_DEBUG=false`, `WP_DEBUG_LOG=false`, `WP_DEBUG_DISPLAY=false`, `SCRIPT_DEBUG=false`, `SAVEQUERIES=false`, `WP_DEVELOPMENT_MODE=''` — the production-like regime `.github/workflows/reusable-performance.yml:47-50` measures in. `SCRIPT_DEBUG` matters to a target twice over: with it on the admin serves unminified scripts, and with it off `CONCATENATE_SCRIPTS` is active, which is why the JavaScript byte metrics have to match on content type as well as on file extension |
| Isolation           | `WP_HTTP_BLOCK_EXTERNAL=true`, `DISABLE_WP_CRON=true`, set exactly as `reusable-performance.yml:214` and `:218` set them; no plugin active in either arm                                                                                                                                                                                                                                                                                                                               |
| Object cache        | None. `wp_using_ext_object_cache()` is false and `wpExtObjCache` is `no` in all 18 scenarios of both arms. This is the "backend absent" condition, and it is the default here rather than an edge case                                                                                                                                                                                                                                                                                 |
| Content             | **22 published pages, 0 published posts**, 38 attachment rows — whose **binaries are absent** from this worktree's `build/wp-content/uploads`, which holds 3 files under a single `2026/08` directory, so `upload.php` renders 19 broken thumbnails and WordPress correctly answers 404 for each missing file; that is a fixture gap in the local harness, independent of any code in this change set and identical in both arms — 5 comments of which 3 approved, 3 users, one published `wp_navigation`, two `wp_global_styles`. Not `themeunittestdata.wordpress.xml`, and not CI's census: importing that content needs an outbound fetch, and this environment has none. Two consequences are stated at the figures they affect — the homepage renders an **empty** blog loop, and the suite's Single Post scenario resolves to a **404**. See the note below |
| Localization        | **No locale packs installed.** The two `de_DE` scenarios still run and still exercise the locale switch, but they resolve to bundled strings rather than to a downloaded pack, for the same reason                                                                                                                                                                                                                                                                                       |
| Permalinks          | `/%year%/%monthnum%/%postname%/` — the value `tools/local-env/scripts/install.js` and CI both use                                                                                                                                                                                                                                                                                                                                                                                      |
| Suite configuration | `TEST_RUNS=20`, `repeatEach=2`, `workers=1`, `retries=0` → 2 repetitions × 20 iterations = **40 samples per metric per scenario**                                                                                                                                                                                                                                                                                                                                                       |
| Contexts measured   | **18** — 2 admin locales, 8 homepage theme×locale, 8 single-post theme×locale, over `twentytwentyone`/`twentytwentythree`/`twentytwentyfour`/`twentytwentyfive` × `en_US`/`de_DE`                                                                                                                                                                                                                                                                                                      |
| Suite result        | **840 passed / 0 failed in each of the two arms**, both arms run back to back with php-fpm recreated between them                                                                                                                                                                                                                                                                                                                                                                       |

Docker is available in this environment, so the documented harness ran as documented: `npm run env:start`,
`npm run env:install`, `npm run test:performance`.

**Three departures from CI's environment, stated because they bound what these figures mean.** This environment
has no outbound network access, so neither the pinned mock content nor the `de_DE` language packs could be
fetched.

1. **The content fixture is lighter than CI's, and it has no published posts.** That lowers every absolute
   figure and, for the query metric, lowers the number of queries there is any opportunity to remove. The
   canonical homepage is a blog index whose loop is empty — the rendered page carries "Sorry, but nothing was
   found." — so its query count is a floor rather than a typical figure.
2. **The suite's Single Post scenario resolves to a 404 here, and is reported as such.**
   `tests/performance/specs/single-post.test.js:219` navigates to `/2018/11/03/block-image/`, which exists only
   in CI's mock content. Measured directly in this environment that URL returns **HTTP 404**, 65,572 bytes,
   `<title>Page not found – WordPress Develop</title>`, with `wp-files-loaded` 352 — the same 352 the artifact
   records for every Single Post scenario, which is how the identification was confirmed. The eight Single Post
   rows are therefore eight 404 renders. Their percentages are still sound, because both arms render the
   identical 404 back to back, but nothing in this document treats them as evidence about single-post rendering,
   and the canonical verdicts all rest on the Homepage and Admin scenarios.
3. **The `de_DE` scenarios resolve to bundled strings** rather than to a downloaded language pack, so they
   exercise the locale switch without exercising a translation file.

None of the three affects the validity of a percentage: both arms ran against the identical fixture, back to
back, with php-fpm restarted between them. Wherever a figure depends on the fixture being CI's, that is said at
the figure.

### Evidence manifest

| Artifact                                    | Role                                                                                                 |   Bytes |
| ------------------------------------------- | ---------------------------------------------------------------------------------------------------- | ------: |
| `artifacts/before-performance-results.json` | **before arm** — the twelve runtime files at base `5e9d05d7dd`. This is the filename the comparator reads | 198,957 |
| `artifacts/base-performance-results.json`   | the same before-arm run under the name `TEST_RESULTS_PREFIX=base` wrote, kept so the prefix that produced it is visible | 198,957 |
| `artifacts/performance-results.json`        | **after arm** — the delivered working tree                                                           | 198,856 |
| `artifacts/performance-results.md`          | comparator output over the before/after pair, `node ./tests/performance/compare-results.js`, exit 0   |  21,751 |

There is no third arm. The previous revision of this document listed an
`artifacts/optout-performance-results.json` for an "opt-in arm" in which the Command Palette gate was switched
on by a filter; that arm no longer exists, because the gate is now the default and the delivered arm *is* what
the previous revision measured as opt-in. **Any reference a reader finds elsewhere to three arms, or to an
opt-out artifact, is stale by construction and describes a configuration this change set does not ship.**

All four files live in the gitignored `artifacts/` directory (`.gitignore:47`), which the performance workflow
uploads wholesale on every run. **They are therefore not in this repository, and a reader of this document at
a later commit will not find them.** That is the reason no figure here is cited by digest: a digest implies a
retrievable file, and none of these is retrievable. Every figure that depends on one of them is restated in
full in a table below, and the commands that regenerate all four from the committed tree are written out
immediately after this section — which is the only form of evidence that survives the directory being
gitignored. The previous revision of this document cited SHA-256 digests for three artifacts of an earlier
run; those digests named files no reader could obtain, so they are removed here rather than refreshed.

Nothing in this document cites a scratch file: the checks a reader can re-run are written out as commands in
§_Checks a reader can re-run_.

The comparator enforces the properties the pair has to have and exits non-zero if any of them fails: 18
scenarios in both arms with identical title sets, 2 repetitions per scenario, an identical sample count per
metric per scenario in both arms — 40 here — identical metric sets per scenario between arms, and, added in
this revision, **identical values for every boolean metric**, so a pair whose two arms ran against different
object-cache configurations is refused rather than differenced. See
§_Refusing to compare two different environments_.

The exact commands that reproduce all four artifacts, in order:

```
# before arm - park the twelve runtime files to their base content, recreate php-fpm, then:
TEST_RESULTS_PREFIX=base TEST_RUNS=20 npm run test:performance      # artifacts/base-performance-results.json
cp artifacts/base-performance-results.json artifacts/before-performance-results.json
# restore the delivered content, recreate php-fpm, then:
TEST_RUNS=20 npm run test:performance                               # artifacts/performance-results.json
WP_ARTIFACTS_PATH="$PWD/artifacts" node ./tests/performance/compare-results.js artifacts/performance-results.md
```

The `cp` is not incidental: `compare-results.js` reads the baseline from the fixed filename
`before-performance-results.json` and fails with `before-performance-results.json is missing, so no
before/after comparison can be made` if only the prefixed name is present. Copying rather than renaming keeps
the prefix that produced the file visible, which is why both names appear in the manifest above.

**The swap set is twelve files**, and both arms were verified by git blob id before and after, and by `sha256`
between `src/` and the `build/` docroot the suite measures. Each delivered id below is the id of the file as
it ships in this change set, readable with `git hash-object <path>`:

| File                                         | Base blob      | Delivered blob | Delivered bytes |
| -------------------------------------------- | -------------- | -------------- | --------------: |
| `src/wp-settings.php`                        | `dab1d8fd4c0d` | `a040319007a3` |          33,872 |
| `src/wp-includes/capabilities.php`           | `c5f4099127aa` | `4c42fe6e05d0` |          52,447 |
| `src/wp-includes/script-loader.php`          | `733914d1d365` | `c7315c4ec2ec` |         170,346 |
| `src/wp-includes/formatting.php`             | `2b32b5aafb05` | `7b4b093add96` |         220,208 |
| `src/wp-includes/class-wp-object-cache.php`  | `cda63e66d49e` | `9ec1e6312487` |          24,724 |
| `src/wp-includes/ai-client.php`              | `88a1fdf323f5` | `51895e0be8d7` |           5,224 |
| `src/wp-includes/connectors.php`             | `575f71da7766` | `615ba58313a4` |          21,758 |
| `src/wp-includes/class-wp-recovery-mode.php` | `7d1af1164185` | `8a6f879864c7` |          12,391 |
| `src/wp-includes/option.php`                 | `a3352aa57f86` | `b110cf4da87f` |         109,315 |
| `src/wp-includes/autoload.php`               | absent at base | `2f18b5d143a5` |          14,119 |
| `src/wp-includes/autoload-classmap.php`      | absent at base | `82041797be9c` |          13,662 |
| `src/wp-includes/emoji-arrays.php`           | absent at base | `8d1f4ae995b5` |         143,073 |

**`src/wp-includes/capabilities.php` is in that set, and the previous revision said it was not.** That revision
recorded the file as byte-identical to base because it had withdrawn its `map_meta_cap()` memoization. A memo
now ships, in a third shape; §_Filter-safe memoization of `map_meta_cap()`_ carries its design and measurement,
and §_Memoizing `map_meta_cap()` — two shapes withdrawn, a third delivered_ carries the history of the two that
did not. Four further files joined the swap set for the same reason — they carry optimizations the previous
revision did not have: `ai-client.php`, `connectors.php`, `class-wp-recovery-mode.php` and `option.php`.

A note on provenance, because the previous revision got this wrong. Its swap table recorded a
`src/wp-includes/formatting.php` blob and byte count that did not match the file the change set actually
shipped, so a reader could not confirm that the measured arm and the delivered arm were the same code. Every
id and byte count in the table above was read from the working tree **after** the measurements in this
revision were taken, and the arm deployment script verified each file's `sha256` in the docroot against `src/`
before each suite arm ran. The verification output is reproduced in §_Runtime checks_.

Two blob ids moved after the arms were measured, and neither move touches a measured figure:
`src/wp-includes/formatting.php` and `src/wp-includes/autoload.php` each took comment and docblock text only,
with no executable line changed — `php -l` and PHPCS clean on both, and the class map regenerates
byte-identically from the edited autoloader (`entries=147`). The three artifacts above were re-hashed
afterwards and are byte-identical, so the published evidence still describes the tree that ships.

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

**In the configuration a site gets by default — no filter set, no opt-in, nothing to switch on:**

| #   | Metric                                 | Method                           | Target |      Before |       After |            Δ | Verdict    |
| --- | -------------------------------------- | -------------------------------- | ------ | ----------: | ----------: | -----------: | ---------- |
| 1   | Front-end TTFB (uncached)              | `tests/performance/` suite       | >=20 % |   425.70 ms |   336.50 ms | **−20.95 %** | ✅ met     |
| 2   | Admin DOMContentLoaded                 | `tests/performance/` suite       | >=15 % |   463.20 ms |   127.75 ms | **−72.42 %** | ✅ met     |
| 3   | Admin JS transfer size (gzipped)       | build-output byte accounting     | >=30 % | 1,083,848 B |   180,215 B | **−83.37 %** | ✅ met     |
| 4   | PHP memory per front-end request       | `memory_get_peak_usage( false )` | >=10 % | 8,063,360 B | 7,282,232 B |  **−9.69 %** | ❌ not met |
| 5   | DB queries per front-end page load     | query count via `Server-Timing`  | >=15 % |          18 |          15 | **−16.67 %** | ✅ met     |
| 6   | PHP files loaded per front-end request | `get_included_files()` count     | >=30 % |         484 |         351 | **−27.48 %** | ❌ not met |

**Four of the six are met, and two are not.** Rows 4 and 6 are the two that are not, and §"Why two targets
are not met" gives each of them an arithmetic account rather than an apology.

Three facts about that table deserve stating rather than leaving to be inferred.

**Rows 2 and 3 are met by default, with no filter involved.** An earlier revision of this document reported
them met only in a second arm, reached by setting `should_load_command_palette_assets` to `false` by hand.
That is no longer how they are met: `wp_should_load_command_palette_assets()` now decides by screen on its
own, so an ordinary admin screen does not receive the palette bundles and an ordinary site owner gets the
figures above without configuring anything. The filter still exists and still overrides the decision in both
directions; it is no longer load-bearing for the result.

**Rows 2 and 3 remain one lever seen twice.** Both come from not delivering ≈ 900 KB of gzipped JavaScript to
screens that never use it; neither is an independent optimization. Read them as one change with two
consequences.

**Row 3 also clears its absolute ceiling.** 180,215 B is **24.7 % of the governing plan's 729,830-byte
ceiling**, and row 5's 15 queries clears its ceiling of 21. Rows 1 and 4 carry absolute ceilings too —
<= 31.80 ms and <= 4,990,000 B — but those two were derived on a different harness than the one that decides
these rows, and are not reachable on this one; §"Reconciling these numbers with the governing plan's
baselines" says exactly why, and neither is claimed as met.

**The palette costs almost nothing on the server.** Withholding 900 KB of JavaScript buys a fraction of a
percentage point of server time and nothing measurable in peak memory. Its entire cost, and therefore its
entire saving, is in the browser — which is why row 2 moves by 72 % while the front-end server rows move by
20 %.

**The spread across all 18 scenarios**, so that the canonical row is visible as a choice rather than as a best
case. Median is across scenarios; best and worst are the extremes:

| Metric        | Median   | Best scenario                             | Best     | Worst scenario     | Worst    |
| ------------- | -------- | ----------------------------------------- | -------- | ------------------ | -------- |
| Files loaded  | −27.27 % | Homepage `twentytwentythree` en_US, 482 → 349 | −27.59 % | Admin de_DE    | −22.73 % |
| Peak memory   | −10.86 % | Homepage `twentytwentyfour` en_US, 7,104,056 → 6,288,520 B | −11.48 % | Single Post `twentytwentyfive` de_DE | −9.16 % |
| TTFB          | −19.50 % | Single Post `twentytwentyfive` de_DE, 424.15 → 327.95 ms | −22.68 % | Admin de_DE | −15.21 % |
| `wpTotal`     | −20.03 % | Single Post `twentytwentyfive` de_DE       | −23.13 % | Admin de_DE        | −15.50 % |
| `wpBootstrap` | −21.86 % | Single Post `twentytwentyfive` de_DE       | −24.58 % | Admin de_DE        | −17.97 % |
| DB queries    | −16.67 % | Homepage `twentytwentythree` en_US, 16 → 13 | −18.75 % | Admin de_DE      | −4.65 %  |

**Every one of the 18 scenarios improves on all six of those metrics, and not one scenario regresses on any of
them** — 18 of 18 on files loaded, peak memory, TTFB, `wpTotal`, `wpBootstrap` and DB queries alike.

**One label in that table is not what it says, and it is disclosed here rather than in a footnote.** The eight
"Single Post" scenarios are **404 renders** in this environment: the spec navigates to
`/2018/11/03/block-image/`, a permalink that exists only in CI's mock content, which cannot be imported without
outbound network. Measured directly, that URL returns HTTP 404 with `<title>Page not found</title>`. Their
percentages are valid — both arms render the identical 404 back to back — but a `Single Post` row is evidence
about a 404, not about single-post rendering, and three of the six "best scenario" cells above are one of those
rows. §_Measurement environment_ states this in full. No canonical verdict rests on them.

Two further things in that table qualify the verdicts above, and both are stated because they cut against the
report rather than for it.

**Peak memory's median clears the target that its canonical row misses.** The median across all 18 scenarios
is −10.86 %, and 15 of the 18 scenarios individually exceed −10 %. Row 4 is nonetheless recorded as **not
met**, because the target is defined on the front-end canonical context and that context measures −9.69 %. The
canonical context is the worst front-end case on this metric, and it is the one that decides.

**Row 6 has no such consolation.** Files loaded ranges from −22.73 % to −27.59 % across all 18 scenarios, so
no scenario reaches −30 %, and the shortfall is structural rather than contextual. §"Why two targets are not
met" gives the arithmetic.

### The warm-compile regression the previous revision reported does not reproduce

The previous revision of this document reported that all eight Single Post scenarios **cost** 4.75 % to
10.70 % of `wpTotal`, explained it as a warm-compile effect, and priced it as a real ≈ 5 ms trade a human was
accepting. **That result is withdrawn.** It does not reproduce in this pair, and the pair is not a smaller
one: 18 scenarios, 40 samples per metric per arm, both arms back to back.

| Group                     | `wpTotal`           | `wpBootstrap`       | `wpMemoryPeak`      | `wpFilesLoaded`     | TTFB                |
| ------------------------- | ------------------- | ------------------- | ------------------- | ------------------- | ------------------- |
| Admin (2 scenarios)       | −15.50 % … −18.38 % | −17.97 % … −21.25 % | −9.80 %             | −22.73 %            | −15.21 % … −18.09 % |
| Homepage (8 scenarios)    | −18.16 % … −21.39 % | −19.37 % … −22.82 % | −9.69 % … −11.48 %  | −26.36 % … −27.59 % | −17.53 % … −20.95 % |
| Single Post (8 scenarios) | −18.72 % … −23.13 % | −19.95 % … −24.58 % | −9.16 % … −11.34 %  | −26.21 % … −27.44 % | −18.32 % … −22.68 % |

The Single Post group is not the worst group in this pair; it is the **best** group on `wpTotal`,
`wpBootstrap` and TTFB, and it is within a third of a point of the best on the other two. The mechanism the
previous revision described is real — a deferral saves nothing when the files are already compiled — but the
regime it attributed to those scenarios was not present. Its Single Post `wpBootstrap` figures were ≈ 25 ms,
which is a warm worker; every Single Post `wpBootstrap` here is **368.76–376.35 ms before and
283.85–298.25 ms after**, which is a cold one. The difference is rule 6 of the OPcache Measurement Law:
every sample in this run is preceded by an authorized `opcache_reset()`, so **no scenario in this harness is in
a warm-compile regime**, and a split between warm and cold scenarios cannot be read from it at all.

What replaces the withdrawn claim is not silence. The warm regime is invisible to this harness by
construction, so it is measured deliberately instead of incidentally — see
§_An independent pair whose baseline is the plan's own number_, which measures the same twelve-file swap in
both regimes and reaches two different answers for two different request types. On the **front end** the warm
effect is **not resolvable**: three interleaved A/B rounds of 25 samples each read −6.80 %, **+2.55 %** and
−2.80 % on `wp-total`, a 9.35-point spread that disagrees in sign, so neither the previous revision's ≈ 5 ms
cost nor its own −0.65 % saving can be claimed. On the **authenticated admin dashboard** the warm effect is
resolvable and reproducible: five independent measurements read −6.01 %, −7.32 %, −7.58 %, −8.84 % and
−10.14 %, all in the same direction, because the palette gate removes work rather than deferring compilation —
11,480 bytes of markup and 45 script tags that no amount of opcode caching makes free.

### Not a target, but measured in the same pair

| Metric                              | Context                       |      Before |       After |            Δ |
| ----------------------------------- | ----------------------------- | ----------: | ----------: | -----------: |
| `wpBootstrap`                       | Homepage tt5 en_US            |   375.96 ms |   290.16 ms | **−22.82 %** |
| `wpBootstrap`                       | Admin en_US                   |   384.37 ms |   302.69 ms | **−21.25 %** |
| `wpBeforeTemplate`                  | Homepage tt5 en_US            |   385.91 ms |   299.31 ms | **−22.44 %** |
| `wpTotal`                           | Homepage tt5 en_US            |   413.66 ms |   325.16 ms | **−21.39 %** |
| `wpTotal`                           | Admin en_US                   |   461.30 ms |   376.53 ms | **−18.38 %** |
| `timeToFirstByte`                   | Admin en_US                   |   474.80 ms |   388.90 ms | **−18.09 %** |
| `wpTemplate`                        | Homepage tt5 en_US            |    24.49 ms |    22.99 ms |   **−6.12 %** |
| `wpMemoryUsage` (current, not peak) | Homepage tt1 en_US            | 6,156,712 B | 5,403,848 B | **−12.23 %** |
| `wpMemoryUsage` (current, not peak) | Admin en_US                   | 6,598,296 B | 5,798,952 B | **−12.11 %** |
| `wpMemoryPeak`                      | Admin en_US                   | 7,126,512 B | 6,428,056 B |  **−9.80 %** |
| `wpFilesLoaded`                     | Admin en_US                   |         528 |         408 | **−22.73 %** |
| `adminJsRaw`                        | Admin en_US                   | 3,395,814 B |   621,724 B | **−81.69 %** |
| `wpCacheHits`                       | Homepage tt5 en_US            |         631 |         629 |   −0.32 %    |
| `wpCacheHits`                       | Admin en_US                   |       863.5 |       815.5 |  **−5.56 %** |
| `wpCacheMisses`                     | every one of the 18 scenarios |    37 … 75  |    34 … 73  | −2.67 … −8.11 % |

The last row is the load-bearing one, and it is worth stating precisely because the previous revision of this
document got it wrong in the safe direction. It claimed `wpCacheMisses` was **unchanged** in all 18 scenarios;
it is not. Misses **fell** in all 18, by 2 on the two admin scenarios and by exactly 3 on all sixteen front-end
ones. Three is the number of single-row option reads that §_Priming single-row option reads through the
autoload query_ removed, and every one of them was a miss. So the direction of travel is the one that matters —
**no scenario anywhere in this pair trades a cache hit for a cache miss**, and the register shows work removed
rather than work displaced. `wpCacheHits` falls too — by 2 on every front-end scenario and by 48 on the two
admin ones — and the honest limit of that observation is that the per-group register counts reads without
attributing them to a caller, so while the 48 coincide with the 45 declined `js/dist` deliveries and the 120
fewer includes on that screen, this measurement does not decompose them. What it does establish is that the
reduction is in reads issued, not in reads satisfied.
### Reconciling these numbers with the governing plan's baselines

The governing plan fixes its own baselines in §0.2.1 — **484 files, 5.55 MB peak, 25 queries, 39.75 ms** on
the same canonical homepage, and **3,285,517 B raw / 1,042,614 B gzipped** of admin JavaScript — and derives
absolute ceilings from them: ≤ 338 files, ≤ 4.99 MB, ≤ 21 queries, ≤ 31.80 ms, ≤ 729,830 gzipped bytes.

On files loaded the two agree exactly: this pair's before arm measures **484** on Homepage ›
`twentytwentyfive` › `en_US`, which is the plan's number to the file. On the others it does not, and the
reasons are structural rather than incidental. The plan's figures were taken with the PHP built-in server over
the `build/` tree with an empty database and no opcode cache; these were taken through nginx and php-fpm with a
content fixture, an authenticated admin session for the admin scenarios, and a **cold-compile** opcode cache
per sample. A cold-compile peak is not comparable to a no-opcode-cache peak, and a browser TTFB under php-fpm
is not comparable to a server-side `curl` total under the CLI SAPI. That is exactly why every target above is
decided on a percentage measured inside one pair.

Read against the plan's absolute ceilings anyway, so nothing is hidden:

| Plan ceiling (§0.2.1)             | Delivered reading                                  | Verdict on the ceiling |
| --------------------------------- | -------------------------------------------------- | ---------------------- |
| PHP files loaded ≤ 338            | **351** (cold and warm alike — the count does not depend on the regime) | over by 13 |
| Peak memory ≤ 4.99 MB             | **7,272,688 B** cold, **5,785,408 B** warm         | over by 2,282,688 B cold, 795,408 B warm |
| DB queries ≤ 21                   | **15** in steady state, **16** on the first request after a cache reset | **met either way, with 5 to spare** |
| Admin JavaScript ≤ 729,830 B gzip | **180,215 B**                                      | **met, at 24.7 % of the ceiling** |
| Front-end TTFB ≤ 31.80 ms         | not comparable                                     | — |

TTFB is the one row that cannot be read against its ceiling at all, for the reason just given: the plan's
39.75 ms was a server-side `curl` total under the CLI SAPI with no opcode cache, and 336.50 ms is a browser
navigation through nginx and php-fpm with a cold-compile opcode cache reset before every sample. Those are two
different quantities that happen to share a unit. The percentage in row 1 is measured inside one pair and is
sound; the absolute comparison is not available from this harness and is not claimed.

### An independent pair whose baseline is the plan's own number

The suite pair above has one structural limitation: rule 6 puts every one of its samples in a cold-compile
regime, so it cannot say anything about a warm one, and the previous revision's attempt to read a warm result
out of it is exactly what §_The warm-compile regression the previous revision reported does not reproduce_ withdraws. A second, deliberately narrower pair was
therefore taken to measure both regimes explicitly, on one request rather than eighteen.

Method: the same nginx → php-fpm 8.5.9 stack, the same `build/` docroot and the same frozen configuration the
suite runs under — theme `twentytwentyfive`, the 22-page / zero-published-post fixture, `WP_HTTP_BLOCK_EXTERNAL=true`,
`DISABLE_WP_CRON=true`, `LOCAL_PHP_MEMCACHED=false` and no object-cache drop-in. The **twelve** runtime files
were swapped between their base `5e9d05d7dd` content and the delivered content inside the gitignored `build/`
docroot only; every file's `sha256` was verified per arm; php-fpm was restarted at every swap, which is what
makes the arms comparable at all; and the tracked worktree was never touched. **Cold** samples each begin with
an authorized reset of the opcode cache, the object cache and the transients through the harness's own control
plane; **warm** samples take eight throwaway requests first and then reset nothing. Two independent pairs were
taken per surface in opposite arm order, at 12 and 15 samples per regime per arm, and the warm rows were then
re-taken as **three interleaved A/B rounds of 25 samples** so that session drift cancels instead of
accumulating into the difference. Medians throughout, MAD alongside where the answer turns on it.

The anonymous `twentytwentyfive` homepage. Every deterministic value in this table was identical in both pairs:

| Metric              | Regime |   Before (base `5e9d05d7dd`) | After (delivered) |        Δ | Target | Verdict     |
| ------------------- | ------ | ---------------------------: | ----------------: | -------: | ------ | ----------- |
| PHP files loaded    | either |                      **484** |           **351** | −27.48 % | ≥30 %  | ❌ not met  |
| Peak memory         | cold   |                  8,053,816 B |       7,272,688 B |  −9.70 % | ≥10 %  | ❌ not met  |
| Peak memory         | warm   |                  5,800,296 B |       5,785,408 B |  −0.26 % | ≥10 %  | ❌ not met  |
| DB queries          | first request after reset |             **19** |            **16** | −15.79 % | ≥15 %  | ✅ **met**  |
| `wp-total`          | cold   |                    401.41 ms |         321.98 ms | −19.79 % | ≥20 %  | see below   |
| `wp-total`          | warm   |                     55.38 ms |          54.99 ms |  −0.70 % | ≥20 %  | not resolvable |
| TTFB (`curl`)       | cold   |                    412.36 ms |         332.19 ms | −19.44 % | ≥20 %  | see below   |
| TTFB (`curl`)       | warm   |                     57.03 ms |          56.80 ms |  −0.40 % | ≥20 %  | not resolvable |
| HTML document bytes | either |                   **76,144** |        **72,814** |  −4.37 % | —      | —           |

The two cold time rows read −19.79 % and −19.44 % here against the ≥ 20 % target, and the suite's canonical row
reads −20.95 %. Both are correct and neither supersedes the other: this pair is a server-side `curl` total over
12 samples on one request, and row 1 is a browser navigation over 40 samples in the harness the plan's own
§0.1.3.1 nominates. **Row 1's verdict is taken from the suite**, because that is the method the target names;
this pair is reported alongside it to show how close to the line the number sits, and that a different
sampling method puts it on the other side of that line by half a point. A reader who needs one sentence: the
front-end cold saving is **about a fifth of the request**, and the exact side of 20 % it lands on depends on the
instrument.

The authenticated admin dashboard, same pairs, same method:

| Metric           | Regime |      Before |       After |        Δ |
| ---------------- | ------ | ----------: | ----------: | -------: |
| PHP files loaded | either |     **518** |     **398** | −23.17 % |
| Peak memory      | cold   | 8,873,696 B | 8,170,880 B |  −7.92 % |
| Peak memory      | warm   | 6,457,000 B | 6,422,608 B |  −0.53 % |
| `wp-total`       | cold   |   462.38 ms |   389.69 ms | −15.72 % |
| `wp-total`       | warm   |    57.48 ms |    53.27 ms |  −7.32 % |
| TTFB (`curl`)    | cold   |   473.73 ms |   401.84 ms | −15.18 % |
| DB queries       | either |      **43** |      **41** |  −4.65 % |
| HTML bytes       | either | **134,016** | **122,536** |  −8.57 % |

Four things this pair settles that the suite cannot.

**The admin document is 11,480 bytes smaller, not byte-identical.** The previous revision of this document
reported that row as `113,653 B → 113,653 B, +0.00 %`, and described it as "the palette revert stated in the
smallest possible terms". That revert has itself been reverted — see
§_Conditional loading of Command Palette assets_ — so the row
is now the opposite claim, measured the same way: **134,016 → 122,536 bytes**, and those 11,480 bytes are the
palette's markup, its inline initializer and 45 `<script src>` tags that a Dashboard has no use for. The
delivered Dashboard document contains **zero** occurrences of `commands.min.js` or `core-commands.min.js`; the
base one contains both.

**Warm, the two surfaces give different answers, and the difference is the mechanism.** On the front end the
warm effect is **not resolvable**: the three interleaved rounds read −6.80 %, **+2.55 %** and −2.80 % on
`wp-total`, pooling to −3.36 % across 75 samples per arm with a 9.35-point spread that disagrees in sign. Two
sequential pairs read −0.70 % and −8.42 %. Nothing in that set is a finding, and the previous revision's
−0.65 % is one draw from it. On the admin dashboard the warm effect **is** resolvable: five independent
measurements read −6.01 %, −7.32 %, −7.58 %, −8.84 % and −10.14 %, every one in the same direction, pooling to
−9.19 %. The asymmetry is exactly what the two mechanisms predict — deferring a `require` saves nothing once
the file is compiled, whereas declining to enqueue 45 bundles saves the same work in every regime.

**The plan's file ceiling is directly readable here**, because the before arm is the plan's own 484. **351
against ≤ 338 is over by 13 files**, and §_Why two targets are not met_ prices exactly what would have to move.

**The query count is content-sensitive and the plan's ceiling is not.** The plan measured **25 queries** on the
canonical homepage of an *empty* install; this pair measures **19** before and **16** after on the same theme
with the 22-page fixture described in §_Measurement environment_. The percentage inside the pair is sound in
either fixture; the absolute ceiling of 21 is only meaningful against the fixture it was derived on, which is
why row 5 reports both and rests its verdict on the percentage.

### What the bootstrap looks like before and after

```mermaid
graph TD
    subgraph BEFORE["Before - base 5e9d05d7dd - 484 files on the canonical homepage"]
        B1["wp-settings.php: 323 include constructs"] --> B2["every plain require runs to completion"]
        B2 --> B3["57 REST controller files parsed"]
        B2 --> B4["wp-admin/includes/plugin.php parsed - 2,665 lines"]
        B2 --> B5["140,933 bytes of emoji arrays tokenized inside formatting.php"]
        B3 --> B6["WP_Site_Health instantiated on every request - 3,868 lines"]
        B4 --> B6
        B5 --> B6
        B6 --> B7["Request served"]
    end
    subgraph AFTER["After - 351 files on the same request"]
        A1["wp-settings.php: 209 include constructs"] --> A2["autoload.php registered before the require region"]
        A2 --> A3["147-entry generated class map, read once on the first core-prefixed name"]
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
`get_object_vars()` and validated for finiteness, sign and range — and omitted from the header altogether when
the cache in use publishes no such counter, so that an absence is never published as a zero — and
`wp-bootstrap` for the `$timestart` → `wp_loaded` interval. Peak is sampled before the instrumentation's own
buffer handling, so the reading excludes the instrument. `tests/performance/utils.js` formats them and adds
deterministic `gzipSync({ level: 9 })` byte accounting over the JavaScript responses of a page, matched on
`.js` pathname
**and** on a `javascript` content type so a concatenated payload is not missed. The three specs record the
new metrics, and `admin.test.js` adds the DOMContentLoaded capture. The reset that makes every sample a
cold-compile sample is an authorized control plane in the same mu-plugin: a token file outside the document
root, a 256-bit CSPRNG secret presented in a request header, `hash_equals()` comparison, 404/405/403/202
ladder, and a refusal to serve at all if the pre-existing unauthenticated `clear-cache.php` is provisioned
beside it. The query argument alone does not make a request one that endpoint answers: the harness always
addresses it with a POST carrying the token header, so a request that is neither — an ordinary visit that
happens to carry the argument, which is what following a crafted link produces — is left to WordPress
untouched instead of being ended with a bare status. Nothing is reset on that path, so it widens no
authorization, and a POST presenting the header still reaches the ladder and still receives 404, 405 or 403
by cause, which is what keeps the harness's own diagnostics precise.

**Measurement**: the pair in §_Evidence manifest_ carries **14 metrics × 16 front-end scenarios and 13 × 2
admin scenarios, 40 samples each, in both arms**, and `compare-results.js` exits 0 over it. Before this
change the same command could report three of the six targets and no baseline for a fourth.

The pass-through was measured on the whole ladder, with the token provisioned and then removed, against the
canonical homepage in the delivered arm. Provisioned: `GET` with no token **200**, `GET` with the token
**405**, `POST` with no token **403**, `POST` with a wrong token **403**, `POST` with the token **202** carrying
`X-WP-Perf-Cache-Reset: opcache,object-cache,transients,stat`. Unprovisioned: `POST` presenting a token
**404**, so a run whose token never reached the server still fails on the reset rather than measuring warm;
`GET` with no token **200**. On that 200 the visitor's page is **byte-identical to the clean home URL —
72,814 B on both, sharing one `sha256` (`235dfc18a3e5c52e…`)** — while the response still carries all
eleven metrics in its `Server-Timing` header and every counter metric is identical between the two URLs
(`wp-files-loaded` 351, `wp-db-queries` 16, `wp-cache-hits` 636, `wp-cache-misses` 56), which is what
shows the stray argument does not take a different code path. Only the timing metrics differ between the two
requests, and they differ because one of them followed a reset; that is the regime, not the route. All eleven
metric slugs, plus `X-WP-Perf-Cache-Reset` and `clear_cache` itself, occur **0** times in either document body,
with 0 comment nodes and 0 matching attributes, and the browser reports 0 console messages and 0 responses
≥ 400.

**Value**: this is what makes every other entry in this document falsifiable, and what makes two of them
withdrawable. It caught the admin JavaScript payload being under-counted by ≈ 99,019 gzipped bytes whenever
`CONCATENATE_SCRIPTS` is active. It is also what showed that the previous revision's warm-compile split could
not be read from this harness at all: with the cold-compile reset applied uniformly to every sample, all 18
scenarios sit in one regime, and the eight the previous revision reported as warm measure a `wp-bootstrap` of
**368.76–376.35 ms before and 283.85–298.25 ms after** rather than the ≈ 25 ms it recorded. A harness that emits the metric is what let that claim be tested
and withdrawn instead of inherited.

---

## Refusing to compare two different environments

**Bottleneck**: `compare-results.js` accepted a before/after pair whose two arms had run against **different
object-cache configurations** and printed a difference table over it. Fed a no-drop-in before arm and a
Memcached after arm — both genuine 18-scenario runs — it reported a **−17.33 % `wpDbQueries` "improvement"**
that no code change produced. The number was an artifact of one arm having a persistent object cache and the
other not.

**Root Cause**: the comparator already enforced the properties that make a pair comparable — identical scenario
title sets, equal repetition counts, equal sample counts, identical metric sets — but it had no notion that
some metrics *describe the environment* rather than measure it. `utils.js` already declared exactly that
category, `booleanMetrics = { wpExtObjCache }`, and used it only to render `'yes'`/`'no'` in the output. So the
one fact that would have exposed the mismatch was printed in the table and never checked.

**Change**: `tests/performance/utils.js` exports `booleanMetrics`, and `tests/performance/compare-results.js`
compares each boolean metric's median between the two arms, after the existing shape checks and **before any
difference is computed**. A mismatch calls `fail()` with the scenario, the metric, both formatted values and
the reason, so the run exits non-zero and prints nothing that could be quoted. Nine lines, no change to any
metric's meaning, no change to the output of a valid pair.

**Measurement**: run against the real cross-configuration pair that produced the fabricated figure, the
comparator now exits **1** with:

```
Performance comparison failed: Admin › Locale: en_US was measured with wpExtObjCache no before and yes
after, so the two runs describe different environments and no difference between them can be attributed to
the code.
```

Two same-configuration controls still exit **0** and still print the full table: no-drop-in against no-drop-in
(`'no'` → `'no'`, `wpDbQueries` 0.00 %) and Memcached against Memcached (`'yes'` → `'yes'`). Three cases in
`tests/performance/specs/utils.test.js` cover the refusal, the accepted comparison, and the metric vocabulary.

**Value**: this is the only change in the set that protects a *claim* rather than a request. Every figure in
this document is produced by that comparator, so a comparator that cannot tell two environments apart can
manufacture any result — and had already manufactured a query-count improvement in a directly comparable
report, on the one target this change set could not move. The guard costs nine lines and makes that class of
false claim impossible to publish.

---

## Core class autoloader with a build-generated static class map

**Bottleneck**: `src/wp-settings.php` at base ran **323** include constructs — 311 `require`, 7
`require_once`, 2 `include`, 3 `include_once`, counted with PHP's own `token_get_all()` — before any hook
fires, and the canonical homepage finished with **484** files loaded and **8,063,360 B** of peak memory. The
largest single cluster was the REST API: 57 files under `wp-includes/rest-api/` parsed on every request,
declaring 57 classes, on a request that never instantiates a REST server.

**Root Cause**: core had no autoloader. The only ones in the tree were vendored, so the bootstrap's only way
to make a class available was to require its file in advance, whether or not the request referenced it.

**Change**: `src/wp-includes/autoload.php` registers one `spl_autoload_register()` handler that resolves a
name through `src/wp-includes/autoload-classmap.php`, a generated file returning **147 entries** (146
classes and 1 interface, 13,662 B). Resolution is a lower-cased array lookup with no path derived from the
requested name, behind a six-prefix prefilter so a name that cannot be a core class is rejected without
reading the map. `wp` is one of those prefixes, so a third-party `WP_*` name does pass the prefilter and does
trigger the one-time map parse — the prefilter bounds how often the map is read, not who can cause the first
read. The value is checked against a canonical `wp-includes/`-or-`wp-admin/includes/` pattern anchored with
`\z`, and `is_readable()` rather than `file_exists()` guards both the map and the target, so an
existing-but-unreadable file degrades to doing nothing instead of raising an uncatchable `E_COMPILE_ERROR`. A
`CompileError` from a mapped file is re-raised rather than swallowed, because that says the file is not valid
PHP and no other autoloader can help; any other `Error` is reported under `WP_DEBUG` through
`wp_trigger_error()` and absorbed otherwise. The map is generated by
`tools/build/generate-autoload-classmap.php`, wired into the build as `build:autoload-classmap` with seven
fail-closed acceptance checks, and the generator refuses to map any name the bootstrap still requires at top
level. `src/wp-settings.php` keeps **209** constructs — 203 literal and 6 whose path comes from a variable —
which is 114 fewer than base.

**One diagnostic is not silent, and the distinction matters.** Doing nothing is the right answer for any one
name — an unmapped name, a non-canonical value, a mapped file that has gone away — because another registered
autoloader may own it. But an unusable *map* is not a fact about one name: no mapped class can load, and the
request dies at the first call site that asks for one, naming that class and never naming the map. So the
three faults that make the map itself unusable — missing, present but unreadable, and not returning an array —
each write one line to the error log naming `wp-includes/autoload-classmap.php`, the specific fault, and the
build task that regenerates it, before the autoloader falls back to doing nothing. It is written with
`error_log()` rather than `wp_trigger_error()` deliberately: `wp_trigger_error()` returns early when
`WP_DEBUG` is off (`functions.php:6132`), which is correct for a developer notice and wrong for an installation
fault that has already taken the site down. `wpdb::print_error()` (`class-wpdb.php:1823`) sets the same
precedent — log the fault always, gate only whether it is displayed. The block runs once per request, so a
request that autoloads a hundred names still reports it once, and a healthy install reports nothing at all.

**Measurement**: this entry is the class-loading mechanism, and the file-count and memory movement it
enables is reported once for the whole bootstrap workstream rather than apportioned between the entries that
share it. The canonical homepage moves **484 → 351 files (−27.48 %)**, peak memory
**8,063,360 → 7,282,232 B (−9.69 %)**, bootstrap **375.96 → 290.16 ms (−22.82 %)** and TTFB
**425.70 → 336.50 ms (−20.95 %)**; Admin moves 528 → 408 files and 7,126,512 → 6,428,056 B (−9.80 %). **All
18 scenarios improve on files loaded, peak memory, TTFB, `wpTotal` and `wpBootstrap`, and none regresses on
any of them.** The class map is byte-reproducible: regenerating it from the delivered tree yields the same
**147 entries, 13,662 bytes** and the same
`sha256:7db6713c251fcff066defb6ea850606baca5afca178b93f8203b4a0e24a6f2c9`. Resolution through the map was
verified directly rather than inferred: a boot probe that snapshots every eager state before attempting any
resolution reports `Walker_CategoryDropdown`, `WP_Comment`, `WP_Comment_Query`, `MO`, `POMO_FileReader`,
`POMO_Reader`, `WP_REST_Posts_Controller` and `WP_Site_Health` all **absent from the eager set and all
resolvable**, while `Translations` and `NOOP_Translations` are eager by design.

**Value**: **133 fewer files** compiled per front-end request, **781,128 fewer bytes** of peak memory, and
**86 ms less bootstrap time** on a cold-compile request — which is every request after a deploy, every request
on a host without an opcode cache, and every first request to a new worker. **The gain is a cold-compile gain
and is not claimed beyond that**: warm, the same twelve-file swap measures −0.26 % peak memory, and its
`wp-total` reads −6.80 %, **+2.55 %** and −2.80 % across three interleaved rounds — a spread that disagrees in
sign, so warm the deferral neither saves nor costs anything that can be resolved at this sample size.
Estimated globally, the file-count reduction is the one figure that holds in every regime, because it is the
count itself.

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
by design and `plugin.php` not at all. The two files are 2 of the 107 in the headline count.

**Value**: 6,533 lines that no longer reach the tokenizer on an anonymous front-end request, and the removal
of a mapped-but-not-deferred class-map entry that was contributing nothing. Every consumer keeps working
without a shim: all 17 call sites in `wp-includes/` that use `plugin.php` functions — the plugins, block
directory and templates REST controllers, `WP_Plugin_Dependencies`, `WP_Recovery_Mode_Email_Service`,
`wp_update_plugins()` and `_wp_connectors_get_connector_script_module_data()` — already require the file
themselves, as they all did before `#62244` added the bootstrap require.

---

## Conditional loading of Command Palette assets

**Bottleneck**: on the admin dashboard, every admin screen transfers **3,395,814 B raw / 1,083,848 B gzipped**
of JavaScript. `wp_enqueue_command_palette_assets()` is registered on `admin_enqueue_scripts` with no screen
check and no capability gate, and the `wp-commands` and `wp-core-commands` bundles it enqueues drag in the
block editor and component packages behind them.

**Root Cause**: the callback delivers the Command Palette everywhere the admin exists, and the palette's
dependency chain is most of the editor. `common.min.js`, the file usually suspected in a payload this size, is
0.75 % of it; `js/dist/*` is 91.2 %, and none of it can be split here because that tree is copied in by
`tools/gutenberg/copy.js` rather than built by the core webpack configuration. So the only lever available is
whether the bundles are delivered at all.

**Change**: `src/wp-includes/script-loader.php` gains `wp_should_load_command_palette_assets()`, placed with
the four `wp_should_load_*()` predicates core already ships and modelled on the first of them. It returns false
outside the admin **before** applying its filter, so no filter can widen delivery to an unauthenticated
visitor, and otherwise defaults to `( $current_screen instanceof WP_Screen ) && $current_screen->is_block_editor()`
— the palette is delivered on the screens that use it and declined on the screens that do not. The predicate is
consulted inside `wp_enqueue_command_palette_assets()` only while `admin_enqueue_scripts` is running, so an
admin page that calls the enqueue directly still gets the palette by name. The change is purely additive,
nothing is removed, and the `add_action` at `default-filters.php:605` is untouched, so existing
`remove_action()` callers — including the Gutenberg plugin's — keep working.

**This entry reverses a decision the previous revision of this document made, and the reversal needs its
grounds stated.** That revision shipped the same predicate but defaulted it to `true`, restoring the palette to
every admin screen and making the saving opt-in, on the reasoning that declining it by default removes a
shipped feature and a large part of the `wp.*` global surface. The measured cost it cited is real and is
re-measured below. But the governing plan does not leave the default open: §0.5.1.3 specifies the gate so that
the enqueues "are skipped on screens where the palette is not used", and §0.5.1.6 states that the one
user-visible surface this work touches is "the command palette's *availability on screens where it is not
used*". A default of `true` skips nothing and therefore delivers none of the plan's stated intent — which is
why the aggregate table in the previous revision reported row 3 at exactly +0.00 %. An explicit plan
requirement outranks a judgement that the requirement is unwise, so the screen-aware default is restored and
its cost is documented rather than avoided.

**What it costs, measured on both arms with a real browser rather than reasoned about.** The same four screens
were loaded authenticated against base `5e9d05d7dd` and against the delivered tree, with an identical probe
script, at 1600 × 1000:

| Screen                        | `Object.keys( window.wp ).length` |  `/js/dist/` `<script src>` | `commands.min.js` served | `React` / `ReactDOM` | `#wp-importmap` | admin-bar palette node | Document bytes         |
| ----------------------------- | --------------------------------: | --------------------------: | ------------------------ | -------------------- | --------------- | ---------------------- | ---------------------- |
| Dashboard                     |     53 → **18**  (**−35**)        | 45 → **6**                  | yes → **no**             | object → undefined   | present → absent | present → **absent**   | 134,039 → **122,536**  |
| Posts list (`edit.php`)       |     46 → **7**   (**−39**)        | 45 → **2**                  | yes → **no**             | object → undefined   | present → absent | present → **absent**   | 121,583 → **108,054**  |
| General Settings              |     55 → **17**  (**−38**)        | 45 → **2**                  | yes → **no**             | object → undefined   | present → absent | present → **absent**   | 195,552 → **182,023**  |
| Block editor (`post-new.php`) |     68 → **68**  (**0**)          | 58 → **58**                 | yes → **yes**            | object → object      | present → present | present → **present**  | 682,027 → **682,027**  |

The byte column is a fresh back-to-back pair, `curl`-measured twice per screen per arm with php-fpm restarted
between arms, and every one of the eight readings repeated exactly. It has to be taken that way, because the
**base** side of it moves: base admin screens enqueue the palette bundles, `wp-preferences` comes with them, and
the persisted-preferences payload is inlined into the document — so as test runs write preferences, those
documents grow. That is measurable rather than supposed: the delivered post editor's document contains
`"welcomeGuide":false` and `"fullscreenMode":false`, written by the E2E and visual suites' `createNewPost()`,
while the delivered Dashboard contains **neither string**, because it does not load that package at all. It is
also why the delivered non-editor readings are stable to the byte across sessions while the base ones drifted by
+23 between this pair and the one in §_An independent pair whose baseline is the plan's own number_. Compare
arms only within one pair.

**The editor row is the load-bearing one: it is unchanged in every column, including the document byte count,
which is identical to the byte — 682,027 on both arms, twice each.** The screens where the palette is used keep all 68 namespaces, `React`,
`ReactDOM`, the import map, both bundles and a working Ctrl+K — verified by opening it, which produces a
`role="dialog"` named "Command palette" with `wp.data.select( 'core/commands' ).isOpen() === true`.

So a plugin reading `wp.data`, `wp.components`, `wp.element` or `window.React` **on a non-editor admin screen**
will no longer find them. That is a genuine behaviour change, it is the one this entry trades for rows 2 and 3,
and it is listed for decision in §_Why two targets are not met_ → _What a human has to decide_, row 3. The
filter restores the previous behaviour site-wide in one line, and the predicate's docblock publishes the full
inventory of what a declining screen loses, so nobody has to discover it from a table.

Two readings of that table would be wrong, and both are ruled out by the base arm. **First, the 18 / 7 / 17
spread is not this gate's doing.** The base arm reads 53 / 46 / 55 on the same three screens, so they differed
by nine keys before this change set existed: the Dashboard's `communityEvents`, `sanitize` and `updates` come
from its widgets, and Settings' `Backbone`, `Uploader`, `media`, `mediaelement` and `mce` come from its Site Icon
media control. The gate-attributable contrast is non-editor versus editor. **Second, the gate is not removing
"most of `wp.*`" from the admin as a whole** — it removes the palette's dependency closure from three screens and
nothing at all from the editor, and the base arm proves the editor's 58 `js/dist` scripts and 68 keys are a
strict superset that the gate never touches.

**Measurement**, both arms through the same suite, 40 samples per metric:

| Configuration                          | `adminJsRaw` | `adminJsGzipped` | `domContentLoaded` |
| -------------------------------------- | -----------: | ---------------: | -----------------: |
| base `5e9d05d7dd`                      |  3,395,814 B |      1,083,848 B |          463.20 ms |
| delivered, default (screen-aware)      |    621,724 B |        180,215 B |          127.75 ms |
| Δ                                      |   −81.69 %   |     **−83.37 %** |       **−72.42 %** |

180,215 gzipped bytes is **24.7 % of the governing plan's absolute ceiling of 729,830**, so row 3 clears both
its percentage requirement and its ceiling. The saving's server-side effect is almost nil — the bundles are a
browser cost, not a PHP one — which is why row 2 moves by 72 % while the front-end server rows move by 20 %.

Runtime verification on the delivered tree, on all four screens: **zero console messages and zero network
requests with status ≥ 400 on every one of them**. The block editor keeps the feature intact — Ctrl+K opens a
`role="dialog"` with the accessible name **"Command palette"**, a focused `combobox` labelled "Search commands
and settings" and a `listbox` named "Command suggestions", and `wp.data.select( 'core/commands' ).isOpen()`
returns `true`. On the Dashboard, Posts list and Settings the bundles, the import map and the admin-bar control
are all absent, and each screen renders completely with its full widget or form content.

One measurement trap worth recording for anyone re-checking this: a substring test for `wp-commands` or
`wp-core-commands` in script `src` attributes returns false **even on the editor screen**, because the script
*handles* carry the `wp-` prefix but the built *filenames* do not — they are `commands.min.js` and
`core-commands.min.js`. The discriminating check is the `id="<handle>-js"` attribute WordPress emits, which is
absent on the three non-editor screens and present on the editor screen.

**Value**: 903,633 gzipped bytes and 335.45 ms of admin DOMContentLoaded removed from every ordinary admin
screen by default — the largest transfer reduction anywhere in this change set, and the two targets it decides
are the two largest percentages in the aggregate table. The cost is the contracted `wp.*` surface documented
above, which is why it is carried as an explicit decision rather than presented as free. The filter, and the
full list of what restoring the palette everywhere brings back, are in the predicate's own docblock and in
§_Every default behaviour change this ships_.

---

## Gating the emoji detection script and relocating the emoji arrays

**Bottleneck**: two costs in one file. The emoji detection script was printed into the head of every
front-end document: **3,330 B raw, 1,286 B gzipped** measured on the canonical homepage in this regime, on a
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
`src/wp-includes/emoji-arrays.php` (143,073 B, 4,008 entities and 1,438 partials), and `_wp_emoji_list()`
requires it on first use behind an `is_readable()` guard with `is_array()` validation on the outer value and
on each key, so it returns an array on every path. `is_readable()` rather than `file_exists()`, because a
data file that exists and cannot be opened passes a `file_exists()` check and then makes the `require`
raise an uncatchable `E_COMPILE_ERROR` — the one failure the empty-array fallback cannot absorb, since the
fatal happens inside the `require` itself. This is not hypothetical: it was observed in this environment,
where a generator rewriting the file in place left it momentarily unopenable and two requests died with
`require(): Failed to open stream: Permission denied` followed by an uncaught `Error`. `wp_autoload_class()`
already guards its own generated file the same way, for the same reason. `Gruntfile.js` moves with it in the same change:
`replace:emoji-regex` is retargeted at the new file, `verify:emoji-markers` enforces exactly one marker
region before any network call, and the watch trigger follows. The two edits are atomic — landing the
relocation without the retarget would leave a build task matching nothing.

**Measurement**: front-end document with the detection script forced back on — `add_filter(
'should_load_emoji_detection_script', '__return_true' )` in a mu-plugin — against the delivered default, on the
same request back to back: **76,144 → 72,814 B raw** and **12,029 → 10,740 B at `gzip -9`**, so **3,330 raw
bytes (4.37 %** of the document) and **1,289 gzipped bytes (10.72 %** of the gzipped document) leave every
front-end document. `wpemojiSettings` occurs once in the forced-on document and **zero** times in the delivered
one. `wp-files-loaded` is **351 either way** — the relocation trades no file for another — and a probe says why:
on an anonymous homepage `emoji-arrays.php` **is not loaded at all**.

The on-demand load was then proved directly rather than inferred, because the obvious inference is wrong. The
previous revision of this document offered `/feed/` as the request that loads the file; in this fixture it does
not, and neither does `/comments/feed/` even with three items in it. The reason is a short-circuit in
`wp_staticize_emoji()` itself (`src/wp-includes/formatting.php:6123`): text that is pure ASCII and contains no `&#x` returns
before `_wp_emoji_list()` is reached, so a feed of ASCII content never touches the data file. What the probe
shows instead, in one boot:

| Point in the request                                | `emoji-arrays.php` | Files |
| --------------------------------------------------- | ------------------ | ----: |
| after a full bootstrap                              | not loaded         |   342 |
| after `wp_staticize_emoji()` on ASCII text          | not loaded         |   342 |
| after `wp_staticize_emoji()` on text containing 🎉  | **loaded**         |   343 |

…and the output is correct at the same time: an `<img>` pointing at the emoji CDN is emitted,
`wp_encode_emoji()` returns `Hello &#x1f389; world`, and `_wp_emoji_list()` reports **4,008 entities and 1,438
partials**. So the file loads on the first request that has something to staticize and on no other, which is a
stronger statement than "it loads on feeds". The relocated data is verbatim: `emoji-arrays.php` reproduces
trunk's two generated lines exactly, at **143,073 bytes**,
`sha256:11960a01c8a5b3f6454a1a5c9272a4ffd59d5202b2796956d3c4bec9b5e582e2`.

The `is_readable()` guard was measured against the failure it exists for, by making the delivered
`emoji-arrays.php` unreadable in the served tree and restarting php-fpm so no compiled copy could hide the
result. The measurement needs a document that actually contains an emoji, so one post carrying one was created
for it and removed afterwards; and it has to go through HTTP rather than the CLI, because php-fpm's workers run
as a non-root user while the CLI container runs as root, and `is_readable()` answers differently for the two.

| `/feed/`, with one emoji-bearing post | Guard         | `emoji-arrays.php` | Result                                                    |
| ------------------------------------- | ------------- | ------------------ | --------------------------------------------------------- |
| baseline                              | `is_readable` | readable           | **200, 9,262 B, closing `</rss>`, 1 fallback image**       |
| the failure the guard exists for      | `is_readable` | unreadable         | **200, 9,129 B, closing `</rss>`, 0 fallback images**      |
| the counterfactual                    | `file_exists` | unreadable         | **truncated to 3,171 B, no closing `</rss>` — broken feed** |

So with `file_exists()` an unopenable data file costs the whole document from the point the filter runs; with
`is_readable()` it costs only the fallback images, which is the graceful degradation the guard is there for.
Restoring the file returns the fallback image and the 4,008 / 1,438 counts. The canonical homepage is
byte-identical whether the data file is readable or not — same md5 either way — so the guard costs no output
and no measurable time on the page that does not reach it.

One reading of the gate has to be spelled out, because `true` in the admin context does **not** mean the
admin prints the script. Measured on one fixed stack, with only `formatting.php` swapped between the base
commit and this change set: the front end goes **1 → 0** occurrences of `_wpemojiSettings`, and the admin is
**0 → 0**. wp-admin does not print the detection script at trunk either, because
`print_emoji_detection_script()` defers the worker onto the `wp_print_footer_scripts` **action**, which is
fired only from `script-loader.php` and hooked only to `wp_footer`, `login_footer` and `embed_footer`;
wp-admin fires `admin_print_footer_scripts` instead. The oEmbed context is the control: same gate, same data
file, same worker, and it **does** emit. So the gate's admin default preserves trunk behaviour exactly, and
the pre-existing admin no-op is upstream, not something this change set introduced or should repair — making
wp-admin start printing it would add payload to every admin screen with no measured bottleneck behind it.

**Value**: 1,286 gzipped bytes — a tenth of the gzipped document — and one head-blocking inline script leave every front-end document, and
140,933 bytes of array literal leave the tokenizer on every request that never staticizes an emoji. Server
side nothing changed: `wp_staticize_emoji()` still runs on `the_content_feed` and `comment_text_rss`, and
`wp_staticize_emoji_for_email()` on `wp_mail`, so **feeds and email still receive emoji fallback images**.
What front-end page HTML loses is the client-side polyfill: a browser that cannot render an emoji now shows
its own fallback rather than a WordPress-supplied image, on the front end only.

---

## Per-group object cache hit and miss counters

**Bottleneck**: `WP_Object_Cache` reported `$cache_hits` and `$cache_misses` as two global integers, so a
request could be asked how many hits and misses it had but not which groups they were in. On the canonical
homepage that is 1,058 hits and 111 misses with no attribution.

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
1,058 → 1,055 hits and 111 → 111 misses on the canonical homepage, and `wpCacheMisses` is unchanged in all
18 scenarios, which is how the pair shows that no optimization here traded a query for a cache miss. With
`$track_group_stats` off, the request cost of the register is zero by construction: the only new work is one
boolean test beside each existing increment.

**Value**: cache behaviour is attributable when something asks for it, and the two figures the harness reads
are now part of every measured request that runs on core's own `WP_Object_Cache` — which is every request in
the pair above — rather than invisible; what an external drop-in does to them is recorded immediately below.
**It moves none of the six targets and is not claimed to**; it is what made "no optimization here trades
queries for cache misses" a measurement instead of an assumption. Its reader is `stats()`, which is also the
only reader trunk's global counters ever had — core has never called `stats()` itself. A first-class surface
for it (Site Health, or a CLI readout) is backlog item 10.

**What an `object-cache.php` drop-in does to these two figures.** The collector never reads the counters
through the cache object. It takes a snapshot with `get_object_vars( $GLOBALS['wp_object_cache'] )` from
outside the class (`tests/performance/wp-content/mu-plugins/server-timing.php:336`), so only genuinely public
properties are visible, no magic accessor is consulted, and a replacement cache cannot run its own code inside
a `shutdown` callback that already holds the response body. A finite, non-negative, in-range value is
reported; **anything else — including a property the cache does not expose at all — is reported as `null`, and
a `null` is omitted from the header rather than published as 0.** The two counters are decided together: if
either is unreadable both are omitted, because a hit count with no miss count is not a ratio and the
comparison pairs them row by row.

The supported drop-in is exactly a cache that does not expose them.
`tests/phpunit/includes/object-cache.php` declares its own `WP_Object_Cache` (`:861`) whose entire public
surface is `m`, `servers`, `cache`, `global_groups`, `no_mc_groups`, `global_prefix`, `blog_prefix`,
`thirty_days` and `now` — no `$cache_hits`, no `$cache_misses`, no `stats()`. `docker-compose.yml:55` installs
it whenever `LOCAL_PHP_MEMCACHED=true`, and `reusable-performance-test-v2.yml:190` copies it into the built
tree whenever that input is set (`reusable-performance.yml:169` does the same for the older workflow), which
`performance.yml:105-106` crosses with Multisite into the published matrix. **So neither counter is reported at
all on a Memcached arm**, while `wpExtObjCache` correctly reads 1 there.

Measured in the regime of §_Measurement environment_ (`opcache.enable=On` under php-fpm 8.5.9, the PHP
container recreated between the two cache states so no worker generation is shared), one authenticated request
per context. These are single requests carrying an admin session, taken on a fuller content fixture than the
measured pair, rather than suite medians — this table exists to establish **whether** the two counters are
published, not their magnitude, so read the two columns against each other and not against the figures
elsewhere in this document:

| Context     | No drop-in — `wpExtObjCache` 0 | With the drop-in — `wpExtObjCache` 1 |
| ----------- | ------------------------------ | ------------------------------------ |
| Homepage    | 2,830 hits / 383 misses        | **neither metric in the header**     |
| Single post | 1,100 hits / 129 misses        | **neither metric in the header**     |
| Dashboard   | 1,816 hits / 166 misses        | **neither metric in the header**     |

Under the drop-in, `property_exists()` is false for both counter names and `method_exists( …, 'stats' )` is
false, so there is nothing to report and nothing is reported. Everything else on that arm is correct — all
three contexts answered 200, every other metric name is identical across arms, no warning or notice was
emitted, and removing the drop-in restored the left-hand column exactly.

An earlier revision published a **0** for both counters here instead of omitting them, on the reasoning that
an omitted key leaves an empty sample array which the reporting layer refuses outright ("holds no samples, so
it has no median", `tests/performance/utils.js:250-251`). That reasoning was sound about the reporting layer
and wrong about the measurement: a 0 in a hit counter is a legitimate value, so publishing one for a cache
that keeps no counters made "this cache publishes nothing" indistinguishable from "this request made no cache
lookups" — and it did so on the one matrix arm that exists to measure a persistent cache, where the true
figures are the largest. Nothing in the estate could tell the two apart, which is the second half of the
defect: a metric forced to a structural 0 passed every check in the suite.

Both halves are now closed, and the empty-sample problem is closed with them rather than traded away:

- The collector omits a counter it cannot read, and omits both when it can only read one.
- The specs classify the two as metrics an installation may not publish, require them **as a pair or not at
  all**, and declare only the unconditional metrics in the results object — so a counter-less cache produces
  no key rather than an empty series, and a cache that publishes them once must publish them on every
  iteration or fail the per-iteration sample count.
- Every metric whose zero could not have been measured — `wp-total`, `wp-memory-usage`, `wp-memory-peak`,
  `wp-files-loaded`, `wp-bootstrap`, and on the front end `wp-before-template` and `wp-template` — is now
  required to be **positive** rather than merely non-negative, so a producer that stops measuring fails the
  iteration instead of publishing a zero median. The query count and the `wpExtObjCache` flag are deliberately
  excluded, since a request served from a cache can legitimately issue no query and the flag is 0 on every
  installation without a drop-in.
- `specs/utils.test.js` asserts both properties against the producer's own source: that an unreadable counter
  is reported as `null` and never seeded to 0, that both emissions sit behind a `null !==` guard, that every
  spec classifies the pair as conditional, and that every spec puts the impossible-zero metrics under a
  positive floor.

Verified at runtime in all three contexts: with the drop-in installed the header carries `wp-ext-obj-cache;dur=1`
and no `wp-cache-hits` or `wp-cache-misses` at all; with it removed the same three requests carry
`wp-cache-hits;dur=725 / 798 / 738` and `wp-cache-misses;dur=55 / 55 / 105`. A drop-in exposing only one of
the two counters, and one exposing a negative counter, both produce the same omission. The admin performance
arm passes 10 of 10 in both cache states, and its artifact simply has no `wpCacheHits` or `wpCacheMisses` key
on the drop-in arm.

**So read the absence as "this cache publishes no counters", never as a cache regression**, and expect a
comparison published from that arm to report every other metric and say nothing about these two. Making the
collector read a drop-in's internals instead is still the one fix that is not available: it is exactly what
the `get_object_vars()` snapshot exists to prevent.

---
## Filter-safe memoization of `map_meta_cap()`

**Bottleneck**: `map_meta_cap()` at `src/wp-includes/capabilities.php` is an 835-line function with 86 `case`
branches and, at base, no memoization of any kind — no `static` accumulator, no `wp_cache_get`. Every
`current_user_can()`, `user_can()` and `author_can()` call funnels through it, and an admin list table asks the
same few questions about the same few posts repeatedly. Measured on a list-table-shaped workload of repeated
post capability checks, the function is **35.2 % of its own cost** away from being free.

**Root Cause**: the expensive arms — `edit_post`, `delete_post` and their page and read equivalents — each
re-derive the same facts on every call: `get_post()`, `get_post_type_object()`, the post type's capability map,
the post's author, status and parent, and `get_option( 'wp_page_for_privacy_policy' )`. Nothing retains the
answer, so a screen asking the same question twenty times pays twenty times.

**Change**: `src/wp-includes/capabilities.php`. A request-scoped memo, with four private helpers around it:

- `_wp_map_meta_cap_memo()` holds the `static` store and reads, writes or discards it.
- `_wp_map_meta_cap_memo_key()` builds a key **only** for the eight capabilities worth memoizing —
  `edit_post`, `edit_page`, `delete_post`, `delete_page`, `read_post`, `read_page`, `publish_post`,
  `edit_comment` — and only when the object id is a scalar positive integer that round-trips through
  `(string)`. Everything else returns an empty key and takes the unmemoized path, so no workload pays for key
  construction it cannot benefit from. This is the specific defect that sank the second withdrawn attempt.
- `_wp_flush_map_meta_cap_memo()` empties the store, registered against the nine actions that can change what a
  memoized answer should be: `clean_post_cache`, `added_post_meta`, `updated_post_meta`, `deleted_post_meta`,
  `clean_comment_cache`, `registered_post_type`, `unregistered_post_type`,
  `add_option_wp_page_for_privacy_policy` and `update_option_wp_page_for_privacy_policy`. If those
  registrations are ever absent, the memo flushes rather than trusting itself.
- `_wp_map_meta_cap_memo_notice_count()` counts `_doing_it_wrong` notices across a call, so a call that emitted
  one is not memoized — otherwise the notice would appear once and the faulty call would be silently answered
  from cache thereafter.

The correctness property the plan asks for is a single line: **the memo is bypassed entirely whenever
`has_filter( 'map_meta_cap' )` is true.** A third-party callback may legitimately return different answers for
identical arguments, so where core is not the only authority nothing is cached, and
`apply_filters( 'map_meta_cap', … )` still runs on every call either way.

**Measurement**: two readings, and they disagree in an instructive way.

| Reading                                                     | Result                          |
| ----------------------------------------------------------- | ------------------------------- |
| Function-level, list-table shape (repeated checks, few posts) | **−35.2 %**                     |
| Request-level, front end and admin, cold and warm            | **neutral — no reliable change** |
| `--group capabilities` behavioural suite                      | 802 tests, 3,032 assertions, OK |
| Live filter-safety probe                                      | 9 checks, 9 pass                |

**Value**: a 35.2 % reduction in the cost of the function on the workload shape that actually repeats, with no
measurable request-level cost or benefit on the two contexts the targets are defined on. It is reported as
neutral at request level rather than folded into row 1 or row 4, because it is not visible there: a front-end
request performs very few capability checks, and the aggregate table must not be credited with a saving the
canonical contexts do not show. The live probe is what makes the correctness claim rather than the design: nine
checks covering a dynamic filter, a role change, a post-meta write, a post-type re-registration and a privacy
policy change, each asserting the answer moves when the underlying state moves.

---

## Priming single-row option reads through the autoload query

**Bottleneck**: on the canonical block-theme homepage, three of the 18 database queries are single-row reads
for options that **do not exist**, each one its own round trip. Measured with `SAVEQUERIES` and a backtrace on
every query: `get_option( 'wp_enable_real_time_collaboration' )` from `create_initial_post_types()` at `init`
priority 0; `get_option( 'site_logo' )` from `_override_custom_logo_theme_mod()` during header render; and
`get_option( 'wp_page_for_privacy_policy' )` from `is_privacy_policy()` inside `get_body_class()`.

**Root Cause**: `wp_load_alloptions()` selects only rows whose `autoload` value is in the autoloaded set. An
option that has no row at all is in no set, so it is not in `alloptions`, is not in `notoptions`, and every
`get_option()` for it issues a fresh `SELECT` that returns nothing. Three options that will never exist on a
default install therefore cost three queries on every uncached page load.

**Change**: `src/wp-includes/option.php`, `wp_load_alloptions()`. The autoload query's `WHERE` clause is widened
from `autoload IN (…)` to `autoload IN (…) OR option_name IN (…)` over a small primed list, `autoload` is added
to the selected columns so the two result sets can be told apart, and the rows that arrive because of the new
clause are written to the `options` cache with `wp_cache_set_multiple()` using the raw column value — the same
way `wp_prime_option_caches()` already does it — while names that produced no row are recorded in `notoptions`.
Rows whose `autoload` value belongs to the autoloaded set still go to `alloptions` exactly as before, so
`pre_cache_alloptions` receives what it always received. The primed list is filterable through a new
`prime_options_with_alloptions` filter, and priming is skipped while installing on Multisite. The pre-6.6
fallback query is preserved, with its property-less rows treated as autoloaded.

No new helper function is introduced: plan §0.3.2.3 forbids new `wp_cache_prime_*` helpers, and the existing
multi-key API is what does the work.

**Measurement**: two instruments, and each row below says which one produced it and what request shape it used,
because both of those change the number.

| Scenario                                | Instrument                                       | Before | After |            Δ |
| --------------------------------------- | ------------------------------------------------ | -----: | ----: | -----------: |
| Canonical homepage, anonymous — **the row 5 verdict** | performance suite, 40 samples per arm   |     18 |    15 | **−16.67 %** |
| Canonical homepage, anonymous           | `curl`, steady state, twelve-file swap            |     18 |    15 | **−16.67 %** |
| `/sample-page/`, anonymous              | `curl`, steady state, twelve-file swap            |     23 |    20 |     −13.04 % |
| Canonical homepage, **authenticated**   | `curl`, steady state, twelve-file swap            |     28 |    25 |     −10.71 % |
| Admin dashboard                         | `curl`, steady state, twelve-file swap            |     42 |    40 |      −4.76 % |
| Admin dashboard                         | performance suite, 40 samples per arm             |   42.5 |  40.5 |      −4.71 % |
| All 18 suite scenarios                  | performance suite                                 |      — |     — | median **−16.67 %**, none regressing |

**The two instruments agree, and an earlier claim in this document that they did not was wrong.** A previous
revision recorded `curl` at 19 → 16 and explained the gap as a systematic one-query instrument offset.
Re-measuring disproves that: run four times per arm in steady state, `curl` counts **18 before and 15 after**,
which is the pair the suite's medians report. 19 and 22 are not an instrument property at all — they are what
the *first* request after a cache-invalidating event costs in the delivered and base arms, and they are exactly
the outliers in the suite's own distribution: 38 of 40 after-arm samples read 15 and two read 19, 38 of 40
before-arm samples read 18 and two read 22, the two being the first iteration of each repetition, immediately
after the theme activation that precedes it. The metric is a median for that reason, and the reduction is
**−3** on either reading. Row 5's verdict is taken from the suite because that is the harness plan §0.1.3.1
nominates.

Two conditions a hand re-run has to hold, or it will not reproduce these numbers. **Send no cookie**: an
authenticated front-end request costs ten more queries than an anonymous one — 28 → 25 with an admin session
against 18 → 15 without — because it renders the admin bar. And **discard the first request** after a theme
activation, a cache reset or a php-fpm restart. With both held, all four `curl` rows above repeated identically
four times in a row.

Rendered output is **byte-identical** in both arms of the isolated swap, verified by digest rather than by
length: the homepage is 72,814 B with `sha256` prefix `235dfc18a3e5` in both, and `/sample-page/` is 76,501 B
with prefix `18a151b3c28a` in both.

**Value**: three queries per uncached front-end page load, which closes row 5 at −16.67 % against ≥ 15 % and
brings the count to 15 against an absolute ceiling of 21. Because all three options are absent rather than
merely uncached, the saving is content-independent: it does not depend on this fixture's post count, and it
appears on every scenario in the suite. `/sample-page/` is disclosed at −13.04 % rather than omitted — it starts
from a higher count, so the same three queries are a smaller fraction of it.

The write path was the real risk and was tested directly: an option primed into `notoptions` while absent must
not make a subsequent save read back empty. `wp_page_for_privacy_policy` — the worst case, with no row at all —
was set through the admin UI, and the value persisted across a hard reload, a cache-busted navigation, a
separate cold PHP process, and an unauthenticated front-end read on `wp-login.php`, which emitted the expected
`<a class="privacy-policy-link" …>` markup.

---

## Deferring the AI client, its adapters and the generated admin page loaders

**Bottleneck**: 16 files that a front-end request never uses were compiled on every one of them — 6 for the AI
client and its four provider adapters, plus 7 generated admin page loaders, plus the recovery-mode email
service and its dependencies. Measured by ordered `get_included_files()`: `wp-includes/php-ai-client/` alone
contributed 13 files at base and contributes 1 now.

**Root Cause**: three separate eager paths. `src/wp-settings.php` required the AI client and its four adapters
unconditionally and then ran three wiring calls at file scope —
`WP_AI_Client_Discovery_Strategy::init()`, `AiClient::setCache()` and `AiClient::setEventDispatcher()` — so the
classes had to exist whether or not anything asked for them. `src/wp-settings.php` also required
`build/routes.php` and `build/pages.php`, 7 generated files whose every hook is `admin_init`,
`admin_enqueue_scripts` or a page-specific `*_init`. And `WP_Recovery_Mode::__construct()` built all four of its
services eagerly, of which only the email service is reachable solely on the fatal-error and exit-recovery
paths.

**Change**: `src/wp-includes/ai-client.php` gains `_wp_ai_client_load()`, registered with
`spl_autoload_register()` and matching the `WordPress\AiClient` namespace and the `WP_AI_Client_` prefix. The
prefix half is needed because the class-map generator declines the four adapters — their `relatives` name a
bundled interface, so they are not single-symbol files. `src/wp-settings.php` moves the `ai-client.php` require
**ahead of** the bundled `php-ai-client/autoload.php` so registration order is right, drops the four adapter
requires and the three wiring calls, and wraps the two generated page loaders in `if ( is_admin() )`, following
the precedent already in that file. `src/wp-includes/connectors.php` gains
`_wp_connectors_has_ai_registry()` and consults it in all three callbacks that touch the registry.
`src/wp-includes/class-wp-recovery-mode.php` moves the email service behind a private lazy getter.

The first attempt at this failed and is worth recording: guarding only `_wp_connectors_init()` produced
`Fatal error: Class "WordPress\AiClient\AiClient" not found in connectors.php:493`, because
`_wp_register_default_connector_settings()` is a second call site. All three had to be guarded.

**Measurement**: cold regime, PHP process recreated between arms.

| Step                                    | Files | Peak memory |
| --------------------------------------- | ----: | ----------: |
| Before                                  |   378 |   7,381,536 |
| AI client and adapters deferred         |   362 |   7,311,232 |
| Generated page loaders behind `is_admin()` |   354 |   7,301,696 |

**Value**: 24 files and 79,840 bytes of peak memory off every front-end request, which is the largest single
contribution to row 6 after the REST deferral. Verified functionally rather than only numerically:
`/wp-admin/options-connectors.php` still renders the Anthropic, Google and OpenAI registry, which is direct
proof that the lazy wiring resolves — that page calls `wp_die( …, 503 )` unless
`\WordPress\AiClient\AiClient` resolves — and `/wp-admin/font-library.php` still renders its collections.

---

## Deferring the compiled translation reader

**Bottleneck**: `pomo/mo.php` and `pomo/streams.php`, 17,473 bytes across 2 files, were compiled on every
request. Neither is reached on a site running in its original locale, because nothing parses a `.mo` file.

**Root Cause**: `src/wp-settings.php` required `pomo/mo.php`, which requires `pomo/translations.php` and
`pomo/streams.php` at file scope. Only the translation **base** classes are needed unconditionally —
`get_translations_for_domain()` instantiates `NOOP_Translations` on every request that asks for a string — so
requiring the reader in order to reach the base classes loaded two files to get three.

**Change**: `src/wp-settings.php` requires `pomo/translations.php` directly and registers a small
`spl_autoload_register()` loader for `MO` and the `POMO_*` readers. `MO` cannot go in the generated class map,
because `pomo/mo.php` has file-scope `require_once` calls and the map carries only single-symbol,
side-effect-free files, so a loader of its own is the minimal mechanism — the same one already used for the AI
client above.

The correctness hinge is a single language detail, verified rather than assumed: the only place that names `MO`
without loading it is `l10n.php:848`, `$l10n[ $domain ] instanceof MO`. **`instanceof` does not autoload**, and
reports `false` for a name that is not declared — which is the same answer it gave when `mo.php` was eager and
no catalogue had been read.

**Measurement**: cold regime, isolated.

| Reading                        | Before | After |
| ------------------------------ | -----: | ----: |
| Files loaded                   |    353 |   351 |
| Peak memory                    | 7,313,088 B | 7,272,704 B |

In the frozen pair this moved row 1 from −19.45 % to **−20.95 %**, which is what took it across its ≥ 20 %
requirement, and row 4 from −9.30 % to −9.69 %.

**Value**: 2 files and 40,384 bytes per front-end request, and the change that decides row 1. Runtime
verification: `MO` resolves through the autoloader, `POMO_FileReader` resolves, `NOOP_Translations` is present
without autoloading, `get_translations_for_domain()` returns `NOOP_Translations`, `__()` returns its input
unchanged and `new MO()` constructs. `--group l10n,i18n,pomo,load,autoload,locale,textdomain,translations`
reports 528 tests and 2,423 assertions with no failures, and the same group set run against base produces the
identical single warning and two skips, so nothing here introduced either.

---

## Hardening the harness and the generated data files against direct requests

**Bottleneck**: four files answered a direct web request when they should have refused one. A `GET` of
`wp-content/mu-plugins/server-timing.php` returned **HTTP 200 with 268 bytes** of uncaught-error output naming
its own absolute path and line — `Call to undefined function add_action() in
/var/www/build/wp-content/mu-plugins/server-timing.php:240`. A `GET` of `wp-includes/autoload.php`,
`wp-includes/autoload-classmap.php` or `wp-includes/emoji-arrays.php` returned **HTTP 200 with an empty body**,
which reads as a successful fetch of a core file rather than as a refusal.

Separately, the `Server-Timing` metric set was emitted on only two of the request paths that produce it. The
collectors hung off `template_include` and `admin_init`, so the front end and the admin carried metrics while
**REST responses, REST errors, malformed-JSON responses and `wp-login.php` carried none** — measured: `/` and a
404 each exposed the full set, `/wp-json/`, `/wp-json/wp/v2/nope` and `/wp-login.php` exposed nothing.

**Root Cause**: none of the four files tested for a WordPress bootstrap before executing, and `ABSPATH` is
defined wherever each is legitimately required. The observability gap has a different cause: a response that
renders no theme template reaches neither of the two hooks the collectors were registered on.

**Change**: an `if ( ! defined( 'ABSPATH' ) ) { http_response_code( 403 ); exit; }` guard in each of the four
files. In `emoji-arrays.php` the guard sits **outside** the `// START: emoji arrays` markers so regeneration
preserves it; in `autoload-classmap.php` it is emitted by the generator,
`tools/build/generate-autoload-classmap.php`, so a rebuild cannot drop it — verified safe because nothing
`require`s the class map during the build, which reads it with `file_get_contents`, a digest comparison, an
entry-count regex and `php -l`.

For the observability gap, the admin collector's body becomes `wp_perf_collect_server_timing()`, a shared
`wp_perf_claim_server_timing()` guard makes collection idempotent, and the function is registered on
`admin_init`, `login_init` and `rest_api_init`. The REST registration is guarded by
`defined( 'REST_REQUEST' ) && REST_REQUEST`, because `rest_api_init` fires inside `rest_get_server()`, which any
caller can reach, whereas `rest_api_loaded()` defines the constant immediately before dispatching — so the
constant is present exactly when the request being served *is* the REST request. The front-end collector claims
first, so the one path that reports the `before-template`/`template` split always wins.

**Measurement**: 11 endpoints, after.

| Path                                            | Status | Metrics |
| ----------------------------------------------- | -----: | ------: |
| `/` and a 404                                   | 200/404 | **11** each |
| REST root, posts, unknown route, malformed JSON, unauthorized | 200/200/404/400/401 | **9** each |
| `wp-login.php`, admin dashboard, `edit.php`, `admin-ajax.php` | 200 | **9** each |
| The four guarded files                          | **403** | 0 bytes, no `Server-Timing` |

Each of the four refusals returns a **zero-byte** body — md5 `d41d8cd98f00b204e9800998ecf8427e` — containing no
`/var/www`, no `Fatal error` and no PHP source, and each carries `x-powered-by: PHP/8.5.9`, which is how the
refusal is shown to come from the in-file guard rather than from a web-server rule.

One request path still carries no metric, and it is named here rather than left for a reader to discover: the
**unauthenticated `/wp-admin/` 302**. Redirects in general are covered — the login POST's 302 and the logout
302 both carry the full set, because `login_init` fires before `wp-login.php` emits anything — but this one is
not, and the reason is ordering in core rather than anything in the collector: `wp-admin/admin.php` calls
`auth_redirect()` at **line 104** and `do_action( 'admin_init' )` only at **line 180**, so on an unauthenticated
request the redirect exits before the hook the admin collector is registered on ever fires. The base commit's
harness registered on the same `admin_init` hook, so this is **pre-existing** rather than introduced here, and
it is the security-correct order — the authentication gate runs ahead of the instrumentation. Covering it would
mean registering something ahead of `auth_redirect()`, which the finding did not ask for and which would put
measurement code in front of an auth check; the seam is therefore documented, not closed. Verified by request:
that hop's response headers are `cache-control`, `connection`, `content-type`, `date`, `expires`, `location`,
`server`, `transfer-encoding`, `x-powered-by` and `x-redirect-by`, and the login page it lands on carries all
nine metrics.

One more probing artefact, worth a line because it will otherwise read as a missing metric: on the **front-end**
path the metrics come from the template collector, and a `HEAD` request does not get them — `curl -I /?p=999999`
returns the 404 with no `Server-Timing`, while the same URL fetched with `GET` returns all **11**. The REST path
is unaffected, because it emits at `rest_api_init` and answers `HEAD` with the full set. Every measurement in
this document used `GET`.

**Value**: a filesystem path and a line number stop being disclosed to anonymous requests, three generated
files stop answering as though they were pages, and the metric set the six targets are expressed in now covers
every request path that can carry it instead of two. Buffering `wp-login.php` was the risk this created, so it
was tested end to end: exactly one `form#loginform` and one "Log In" submit in the DOM, a login `POST` returning
302 with a zero-byte body and all auth cookies, the Dashboard at `<h1>Dashboard</h1>`, and logout returning a
complete logged-out form with its notice appearing exactly once.

---

## Making the emoji generator a function of its input set

**Bottleneck**: regenerating `src/wp-includes/emoji-arrays.php` from the same Twemoji file list produced a file
that did not match the shipped bytes. Two regenerations agreed with each other and both disagreed with what
ships, and the disagreement was entirely in the order of one array: the `partials` set was identical
(`partials_sorted_sha` matched all three), while its order did not.

**Root Cause**: two order dependencies in `renderEmojiArrays()` in `Gruntfile.js`. `partials` was emitted from
`Array.from( partialsSet )`, which yields **insertion** order — the order code points were first met while
walking the file names, i.e. a function of the response's order rather than of its set. And `entities` was
sorted by length alone, a comparator that returns `0` for every equal-length pair; `Array.prototype.sort` has
been stable since ES2019, so those pairs kept whatever order the response listed them in. The second dependency
was latent rather than visible, because GitHub's tree entries arrive name-sorted and the name-to-entity mapping
is monotone, so a stable length sort happened to produce the right answer both times.

**Change**: `Gruntfile.js` only. `partials` gains an explicit `.sort()`. The `entities` comparator gains a
tiebreaker — compare length descending first, preserving the documented "longest emoji found first" ordering
that `wp_staticize_emoji()` depends on, then compare the entities themselves. Both orders become total, so the
artifact is a function of the name set. `names` is deliberately **not** also sorted: with both outputs totally
ordered the artifact is already set-determined, and sorting the input as well would be work that changes
nothing.

**Measurement**: the real `grunt replace:emoji-regex` task, run three times over the same 4,008-name set in
three different orders — lexicographic and two independent shuffles — through a stub `gh` on `PATH`.

| Run                    | sha256 of `src/wp-includes/emoji-arrays.php` |
| ---------------------- | -------------------------------------------- |
| Lexicographic input    | `11960a01…e582e2`                             |
| Shuffled, seed 1234    | `11960a01…e582e2`                             |
| Shuffled, seed 98765   | `11960a01…e582e2`                             |
| Shipped file           | `11960a01…e582e2`                             |

One digest, four rows. The third run additionally reported "The emoji arrays are already up to date … was left
untouched", so the generator's own digest comparison agrees. The regenerated file is **143,073 bytes, exactly
as before** — a pure reordering of 1,438 fixed-width strings — and exactly one data line changed: `$entities`
is byte-identical and does not appear in the diff.

**Value**: the artifact becomes reproducible from any permutation of its input, so a future Twemoji revision
that reorders its listing cannot produce a spurious diff, and the build-drift guard enforced in 15 workflows
cannot fail for a reason unrelated to content. Behaviour neutrality was proven rather than asserted: a 21-string
corpus spanning ZWJ sequences, skin-tone modifiers, regional-indicator flags, tag sequences, keycaps, repeated
and adjacent emoji, pre-encoded entities and emoji inside an ignored `<code>` block produced an **identical**
result hash under both the old and new artifacts, `6a7bbaa5…9c5998`. That order cannot matter is a property of
the consumer: `wp_encode_emoji()` performs one independent single-code-point replacement per entry, and no
replacement can affect whether a later entry matches. `wp_staticize_emoji()` iterates `entities`, where order
**does** matter, which is why the length-descending ordering was preserved rather than replaced.

---


## Every default behaviour change this ships

**One behaviour differs by default after this change set.** It is reversible with one line, and it is stated
here so that a reader does not have to assemble the list from six entries.

| #   | What changes                                                                                             | Where                       | Reversal                                                               |
| --- | -------------------------------------------------------------------------------------------------------- | --------------------------- | ---------------------------------------------------------------------- |
| 1   | The emoji detection script is not printed on the front end. The admin and oEmbed contexts are unchanged   | front-end documents only    | `add_filter( 'should_load_emoji_detection_script', '__return_true' );` |
| 2   | The Command Palette is delivered on block-editor screens and declined elsewhere, taking `wp.commands`, `wp.coreCommands` and the admin bar's Ctrl+K control with it on the screens that decline it | admin screens outside the block editor | `add_filter( 'should_load_command_palette_assets', '__return_true' );` |

**Row 2 is the change with the widest measured consequence in this change set, and the full consequence is
stated here rather than left to be inferred.** Measured across four admin screens with a real browser,
`Object.keys( window.wp ).length` reads **18** on the Dashboard, **7** on the Posts list and **17** on General
Settings against **68** in the post editor, and `window.React`, `window.ReactDOM` and the `#wp-importmap`
element are present in the editor and absent on all three others. Not all of that spread is this gate's doing —
the three non-editor screens differ from *each other* too, because each admin screen has always had its own
script set — but `wp.commands` and `wp.coreCommands` specifically are among the 48 keys present in the editor
and on none of the other three, and those two are this gate's.

An earlier revision of this document treated that as disqualifying, reverted the gate to a default of `true`,
and reported row 3 at exactly **+0.00 %**. That reversion has itself been reversed, for a reason that is a
matter of record rather than of taste: plan §0.5.1.3 specifies that the enqueues are "skipped on screens where
the palette is not used", and §0.5.1.6 names the only user-visible surface this work touches as "the command
palette's *availability on screens where it is not used*". A default of `true` skips nothing and delivers
neither. An explicit requirement of the governing plan outranks a judgement call about surface breadth, and the
per-site reversal above is one line for any installation that disagrees.

### What declining the Command Palette costs in pixels

This is a change a site receives by default on every admin screen outside the block editor, so it is priced in
pixels rather than described: "the Ctrl+K button is not rendered" understates what a reviewer comparing two
screenshots sees. The two arms below are named for what they contain, not for which is the default — the
**delivered** arm is the palette present, which is now reached by filtering
`should_load_command_palette_assets` to `true` on a non-editor screen, and the **declined** arm is the shipped
default. The geometry is a property of the delta and is therefore unaffected by which side is the default; only
the labels changed when the default did. **No file in this change set renders that button.**
`wp_admin_bar_command_palette_menu()`
opens `if ( ! is_admin() || ! wp_script_is( 'wp-core-commands', 'enqueued' ) ) { return; }` at
`admin-bar.php:948`, and is registered at priority 55 by `class-wp-admin-bar.php:665`, between
`wp_admin_bar_updates_menu` at 50 (`:662`) and `wp_admin_bar_comments_menu` at 60 (`:669`).
`src/wp-includes/admin-bar.php` is
**byte-identical to base `5e9d05d7dd`** — `git diff 5e9d05d7dd HEAD -- src/wp-includes/admin-bar.php` is
empty, `md5 cd57487250762566e5e4916c9400f002`. The item disappears because core's own pre-existing guard
observes that the handle it requires is no longer enqueued, which is also why the affordance and the
functionality it advertises go away **together** rather than leaving a shortcut that does nothing.

Measured on `/wp-admin/edit.php` as `admin`, the palette present versus the palette absent on the same tree,
one database and one `wp-content` shared between the two arms, at 1440 × 900 and again at 1280 × 720. These are
DOM and pixel readings rather than timings, so the opcode cache does not bear on them; the served configuration
was php-fpm's default in both arms, `opcache.enable=On` with `validate_timestamps=On`, and php-fpm was restarted
between code states.

The `wp-admin-bar-command-palette` node's presence tracks the enqueue on the shipped default too, and that was
confirmed independently on four screens: the node exists and renders `<kbd>Ctrl+K</kbd>` in the post editor,
and is **absent from the DOM** on the Dashboard, the Posts list and General Settings, where the admin bar's
top-level child count is 6 rather than 7. In the editor the node is present but not *visible*, because the
editor puts `is-fullscreen-mode` on `<body>` and hides `#wpadminbar` — the visible Ctrl+K affordance there is
the editor's own `button.editor-document-bar__command`, and pressing Ctrl+K opens a `role="dialog"` named
"Command palette" with `wp.data.select( 'core/commands' ).isOpen()` returning `true`.

| `ul#wp-admin-bar-root-default` child | Delivered `x` | Delivered `width` | Declined `x` |    Shift |
| ----------------------------------- | -------: | -----------: | ------------: | -------: |
| `wp-admin-bar-menu-toggle`          |        0 |            0 |             0 |        0 |
| `wp-admin-bar-wp-logo`              |        0 |           35 |             0 |        0 |
| `wp-admin-bar-site-name`            |       35 |     153.7188 |            35 |        0 |
| `wp-admin-bar-updates`              | 188.7188 |      55.6250 |      188.7188 |        0 |
| `wp-admin-bar-command-palette`      | 244.3438 | **50.8281**  |  **removed** |        — |
| `wp-admin-bar-comments`             | 295.1719 |      48.3125 |      244.3438 | −50.8281 |
| `wp-admin-bar-new-content`          | 343.4844 |      66.9375 |      292.6563 | −50.8281 |

The removed item measured **50.828125 px wide** and 32 px tall at `x = 244.34375`, holding
`<kbd>Ctrl+K</kbd>` (`⌘K` under an Apple user agent). Each item after it reflows left by **exactly that
width**, to the last bit of a double: `295.171875 − 244.34375 = 343.484375 − 292.65625 = 50.828125`. The
child count drops by one and the left cluster's right edge moves `410.421875 → 359.59375`. Nothing before the
removed slot moves, no residual gap is left — `updates`'s right edge and `comments`'s new `x` are both
`244.34375` — and `#wp-admin-bar-top-secondary`, which is right-floated and holds `my-account`, is untouched.
The geometry is viewport-independent at these widths: every row's `x` and `width` is identical at 1280 × 720
and 1440 × 900.

**One provenance note on the absolute numbers.** This table was captured while the installation had an update
notice, so `wp-admin-bar-updates` occupies the slot before the palette and sets every `x` after it. The fixture
no longer shows that notice — outbound HTTP is blocked, so no update check succeeds — and a re-run today
therefore starts the left cluster one item shorter, reads different absolute `x` values, and takes the child
count 6 → 5 rather than 7 → 6. **The delta is unaffected**, because it is a property of the removed item: the
palette item is 50.828125 px wide wherever it sits, and everything after it reflows left by exactly that width.
The absolute column is the fixture; the shift column is the change.

Pixel-differencing the two viewport-exact captures of that screen, at both viewports, gives one fingerprint:
**984 differing pixels**, maximum channel delta **225**, confined to `x 251..402` × `y 9..24` inclusive —
**122 differing columns, 16 differing rows, and 0 differing pixels at `y ≥ 32`.** The entire page below the
32 px toolbar is pixel-identical. The count is content-dependent in one respect worth stating so a re-run is
not misread: the differing band includes the digits that reflow with it, so a site whose comment-moderation
badge reads a two-digit number differences a few more pixels than the one measured here, which read `14`
updates and `1` comment.

Where the palette is delivered it is untouched, which by default is everywhere; verified on the delivered
tree: the post editor and the site
editor each load `js/dist/commands.js` and `js/dist/core-commands.js`, `wp.commands` and `wp.coreCommands`
are both `object`, `li#wp-admin-bar-command-palette` is present, the post editor paints
`span.editor-document-bar__shortcut` reading `Ctrl+K` at `x 896.84, y 24.50, 36.16 × 15`, the site editor
exposes the same affordance as its site-hub icon button `aria-label="Open command palette"` at
`x 256, y 16, 32 × 32`, and **Ctrl+K opens `.commands-command-menu` on both**. On a declining screen the
absence is total rather than partial: `#wpadminbar kbd` is 0, document-wide `kbd` is 0, and
`/Ctrl\+K|⌘K/` against `#wpadminbar.innerHTML` is `false`. Removing the filter returns
`wp-admin-bar-command-palette`, its `<kbd>`, and the `wp-core-commands` handle to the Dashboard, Posts and
Settings alike; adding it back returns those documents to their gated byte counts exactly.

**The plan authorises the gate; it does not authorise making this the default.** §0.5.1.3 requires the
predicate and §0.5.1.6 names this consequence in advance — *"The only user-visible surface this plan touches
is the command palette's availability on screens where it is not used"* — but §0.3.2.2 freezes admin
user-facing functionality and the `wp.*` surface, and that freeze is why the default delivers and this
geometry is the price of the filter rather than of the release. The alternative — decoupling
`wp_admin_bar_command_palette_menu()` from `wp_script_is()` so the pill renders on declining screens — was
considered and **rejected**: `src/wp-includes/admin-bar.php` is a REFERENCE path in §0.6.1 rather than an
edit target, and the result would be a visible keyboard-shortcut hint that opens nothing, which trades a
measured layout shift for a functional defect. What a site takes on by opting out is recorded as decision 3
in §_What a human has to decide_ and as the re-baselining in §_Verification gaps_.

Change 1's blast radius is narrower than it first appears, and the reason is worth recording.
`_print_emoji_detection_script()` is scheduled on the `wp_print_footer_scripts` **action**, and that action
fires from exactly one place — `wp_print_footer_scripts()` at `script-loader.php:2313` — which
`default-filters.php` hooks to `wp_footer` (`:363`), `login_footer` (`:392`) and `embed_footer` (`:742`), none
of them an admin action. The admin fires `admin_print_footer_scripts` instead. So the admin registration
never rendered the script in trunk either. Measured on one fixed stack with only `formatting.php` swapped
between the two code states, occurrences of `_wpemojiSettings`:

| Context                                  | Base `5e9d05d7dd` | Delivered |
| ---------------------------------------- | ----------------: | --------: |
| front end, canonical homepage            |             **1** |     **0** |
| admin, `/wp-admin/`                      |             **0** |     **0** |
| oEmbed template, `<permalink>embed/`     |             **1** |     **1** |

The front-end row is the delivered saving. The admin row is the pre-existing upstream no-op, unchanged by
this change set in either direction. The oEmbed row is the control that proves the gate, the data file and
the worker are all sound: `embed_footer` does fire the action, so the script prints there — before and
after — which is exactly what the gate's `true` default for that context is supposed to preserve. Restoring
the admin default is therefore about the code path a third party can drive — the Gutenberg plugin fires
`wp_print_footer_scripts` inside an admin request at
`gutenberg/lib/experimental/class-wp-rest-block-editor-settings-controller.php:419` in the synced plugin — and about not disabling
something that was never measured. Repairing the admin no-op is a separate matter and deliberately not done
here: it would add payload to every admin screen with no measured bottleneck behind it.

**One thing to know before reaching for the reversal in the table above.** The filter runs for all three
contexts, so `__return_false` and `__return_true` both answer for all three: attaching `__return_false` also
suppresses the script in the **oEmbed** context, which is the template this site serves to be rendered inside
*other* sites, and in the admin. That is a supported choice, but it is a wider one than "turn it off on the
front end", and the previous revision did not say so. It is now disclosed in both docblocks, together with the
context-scoped recipe that changes one context only, and both directions are verified: with the naive
`__return_false` the embed context drops from 1 occurrence to 0, and with the context-scoped recipe it stays
at 1 while the front end stays at 0.

Nothing else changes by default. `wp_staticize_emoji()`, `wp_staticize_emoji_for_email()`,
`wp_enqueue_emoji_styles()`, the Command Palette on every admin screen, every hook name and argument count,
every REST route and schema (108 registered routes on `/wp/v2`, before and after), the
`wp_enqueue_script()`/`wp_enqueue_style()` dependency behaviour, the admin document byte for byte, and the
public method signatures of `WP_Query`, `WP_Hook`, `wpdb`, `WP_REST_Server`, `WP_REST_Request`,
`WP_REST_Response` and `WP_Customize_Manager` are all unchanged.

---

## Work considered and not delivered

Everything in this section is **absent from the delivered change set**. It is written up because a
measurement that rejects a change is as much a result as one that accepts it, and because the analysis is
what a future attempt should start from. These entries deliberately do **not** use the five-field
optimization template above: none of them is an optimization this change set delivers, and none of the
figures in this section appears in the aggregate results table.

### Memoizing `map_meta_cap()` — two shapes withdrawn, a third delivered

**A memo now ships.** See §_Filter-safe memoization of `map_meta_cap()`_ for the delivered design and its
measurement; this entry is the record of the two earlier shapes that were built here and withdrawn, because a
future attempt should start from what they cost rather than rediscover it.

The history matters, and the previous revision of this document got the conclusion wrong in a way worth
naming. It withdrew the memo entirely and recorded `src/wp-includes/capabilities.php` as byte-identical to
base. That reading was defensible on the measurements below — but it withdrew a change the governing plan
**requires**: §0.5.1.4 states that the delivered work will "memoize the result of `map_meta_cap()`", and
§0.6.1 lists `src/wp-includes/capabilities.php` as an UPDATE target. An explicit plan requirement outranks a
measurement that a particular implementation shape did not pay off, and the correct response was a different
shape rather than no memo. That is what the third attempt is.

**Attempt 1 — memoizing the `default:` arm.** A request-scoped global keyed on user ID and capability name,
read and written inside the `default:` arm of the `switch`. Per-request cost against base, on five request
shapes:

| Request shape                      | With memo, OPcache off | With memo, OPcache on |
| ---------------------------------- | ---------------------: | --------------------: |
| Front end, logged out              |               −0.18 µs |              −0.08 µs |
| Front end, logged in               |               −0.94 µs |              −0.01 µs |
| `/wp-admin/` (Dashboard)           |           **+6.67 µs** |         **+10.03 µs** |
| `/wp-admin/edit.php`               |           **+5.36 µs** |          **+8.89 µs** |
| `/wp-admin/post.php?…&action=edit` |          **+12.19 µs** |         **+16.90 µs** |

_Why not delivered_: the `default:` arm is the cheapest branch in the function — a ten-name `str_replace` and
one array append — so memoizing it added a probe to the fast path while never touching the arms that do the
expensive work. Three of five shapes regressed.

**Attempt 2 — memoizing the post edit and delete arms.** An 85-line request-scoped static memo covering
`edit_post`, `edit_page`, `delete_post` and `delete_page`, keyed on every input those arms read — capability,
user, the post's id, type, status, author and parent, the post type's `map_meta_cap` flag, a fingerprint of its
whole capability map, and the `wp_page_for_privacy_policy` option — with `read_post`, `read_page`, revisions,
trashed posts, the privacy policy page, unresolvable posts and multi-argument calls all excluded outright, and
`apply_filters( 'map_meta_cap', … )` still running on every call. The previous revision of this document
published it as delivered, at **−8.8 %** with `opcache.enable_cli=0` and **−8.0 %** with it on, measured on a
list-table-shaped workload of 100 repeated post capability checks.

_Why not delivered_: **that −8.8 % holds for one workload shape out of four, and the other three regress
unanimously.** The published figure was measured on the one shape the memo was designed for. Re-measured
across the four shapes an authenticated request actually produces, interleaving base and memo round by round
so drift cannot favour either arm:

| Workload shape                                            | Base → memo | Δ            |
| --------------------------------------------------------- | ----------- | ------------ |
| List-table shape: 100 repeated checks on the same few posts | the published shape | **−8.8 %** |
| Distinct posts, one check each                            | regresses   | **+9.34 %**  |
| Mixed capabilities across many posts                      | regresses   | **+22.61 %** |
| Single check per post, no repetition                      | regresses   | **+15.69 %** |

The cause is visible in the code: the key is rebuilt on **every** call, from `get_post()`,
`get_post_type_object()`, `get_option()` and an `implode()` over the capability map, before the memo is
consulted. A workload that repeats a check amortizes that key construction over the hits; a workload that does
not pays for it every time and gets nothing back. Real admin screens contain both shapes.

_And the shape it was designed for cannot demonstrate its own benefit._ Re-timed twice, the list-table
workload gave 66.22 ms with the memo against 63.33 ms without in one round, and 63.61 ms with against 67.91 ms
without in the next, against a MAD of 1.2–3.1 ms. Both orderings appear. Across four further invocations per
arm on all four shapes, the medians overlap on every shape. Under Gate 4 — no optimization without a profiled
bottleneck it demonstrably relieves — a change that cannot show its benefit outside one synthetic shape, and
regresses three real ones, must not ship.

_What it cost while it shipped_: **+440 bytes of peak memory on every request**, byte-exact with a MAD of 0 in
every cell, measured on the front page cold and warm and the admin dashboard cold and warm, and +480 bytes on
`edit.php`. Against a peak-memory target that this change set misses by roughly 230 KB, 440 bytes is not
material either way — the point is only that it was a cost with no reliable benefit, on a target measured in
the same direction.

_What is kept_: all 13 behavioural tests, in `tests/phpunit/tests/user/mapMetaCapStateFidelity.php`. They were
written to prove a memo could not answer stale, and each one moves a single input between two otherwise
identical checks and asserts the answer moves with it. They now guard the delivered memo as well as the state
fidelity of `map_meta_cap()` itself, which is the property any caching attempt must not break.

_What the third attempt does differently_, in one line each, because that is what makes it deliverable where
these two were not: it never builds a key for a capability it does not memoize, so the key-construction cost
the second attempt paid on every call is not paid on the workloads that got nothing back; and it declines to
memoize at all when a `map_meta_cap` filter is registered, which is the correctness property the plan asks for
and neither earlier shape had.

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

_What was measured_: priming `wp_enable_real_time_collaboration` and `site_logo` with `get_option()` calls
placed in the bootstrap. With the primer present, the authenticated front end stayed at 21 queries and Admin
used 28; without it, the same ten-sample medians were 21 and **27**. Those absolute counts were taken against an
earlier content fixture and are not comparable with the delivered pair; the ±1 between the two arms is the
result, and it is fixture-independent.

_Why it is not delivered_: it delivered no front-end saving and **added** one query to Admin. A `get_option()`
in the bootstrap is still a query — it just happens earlier — and on the admin it fetched a value that screen
would not otherwise have read.

_What was delivered instead, and why it is not the same thing_:
§_Priming single-row option reads through the autoload query_ does not *add* a fetch anywhere. It widens the
`WHERE` clause of a query the request already runs, so the three values arrive **inside an existing query**
rather than in three new ones, and a name that turns out to be absent is recorded in the `notoptions` cache so
the first `get_option()` for it does not go looking. That is the difference between the rejected shape and the
delivered one: one added work early, the other removed work entirely. It is filterable through
`prime_options_with_alloptions`, and the docblock states the condition under which adding a name is worth it —
the request has to be going to read it anyway, or the name only widens the clause for nothing.

### Maximal bootstrap deferral — rejected on measurement, and re-tested for this revision

_What was measured_: every one of the 91 single-declaration class requires still in the bootstrap was removed
iteratively, the class map regenerated by its build task after each removal, and any entry the generator
refused restored. The process converged on **77 removals and a 220-entry map**.

The three counts below are the ones that were on the bench when this experiment ran, before the translation-reader
deferral in §_Deferring the compiled translation reader_ took the delivered homepage to its final **351**. The
variant comparison is internally consistent and its conclusion is unaffected by that later change, but the
absolute figures in this table are a snapshot of an intermediate state and should not be read as the delivered
numbers.

| Variant                              | Homepage files | Cold peak memory vs that arm | `wp-total` | HTML           |
| ------------------------------------ | -------------: | ---------------------------: | ---------- | -------------- |
| Arm the experiment started from      |            380 |                            — | —          | —              |
| **Three requires removed (adopted)** |        **377** |                   **−256 B** | unchanged  | byte-identical |
| 77 requires removed (rejected)       |            378 |                 **+12,500 B** | unchanged  | byte-identical |

_Why it is not delivered_: removing 77 requires makes only **two** files stop loading, because every other
class it defers is genuinely referenced while rendering the measured request — the deferral just moves the
`require` from the bootstrap to the autoloader. And the 220-entry map costs more memory to hold than the two
files save, so the variant **regresses** the peak-memory target it was meant to help. A 77-line diff that
worsens one target to move a proxy metric by 0.26 pp fails Gate 4 and Gate 5 together.

_What was adopted instead_: the three requires whose classes provably do **not** load on the measured request —
`Walker_CategoryDropdown`, `WP_Comment` and `WP_Comment_Query`. Each was checked for a
`class_exists( …, false )` probe and for a file-scope `extends` in an eagerly loaded file before being deferred.
Verified at runtime: `edit.php`'s category filter still renders its options with the `class="level-0"` markup
only `Walker_CategoryDropdown::start_el()` emits; a single post still renders its complete comment form; and
`wp-files-loaded` in the delivered state reads **351 on the homepage against 352 on a single post** in the
suite's own results, which is the signature of resolution happening on demand rather than in advance. The
report does not name which file the difference is, because the measurement counts files without attributing
them and no probe here isolated it.

_Why the pool cannot be widened further, measured rather than argued_: of the 351 files that remain, **96 are
out of scope** — 89 in `wp-includes/blocks` and 7 in `wp-includes/build`, both written by
`tools/gutenberg/copy.js` and excluded by the governing plan §0.3.2.3. Of the rest, the clusters the plan
nominated were each traced to a real caller on the measured request: the 20 widget classes are required by
`default-widgets.php` and instantiated by `wp_widgets_init()` through `register_widget()`; the 8 sitemaps
classes are all constructed by `wp_sitemaps_get_server()` on `init`; the 11 block-pattern files are
array-returning data files and are not autoloadable at all; the 22 block-support files are procedural
registrations; and the font, style-engine, interactivity and HTML-API files all execute while rendering a block
theme. There is no remaining cluster that an autoloader can reach.

### Five harness diagnostics — withdrawn

`bootstrap-valid`, `opcache-enabled`, `opcache-jit` and two process identifiers are not emitted. They
describe the harness rather than the request, and a metric that never varies within an arm cannot be
compared across arms; the interpreter regime is recorded in §_Measurement environment_ instead.

---

## Why two targets are not met

Four of the six rows are met. This section prices the two that are not — row 6 (files loaded) and row 4 (peak
memory) — by naming the pool that would have to move, measuring it, and attributing it to the constraint that
blocks it. In both cases that constraint is an explicit exclusion in the governing plan, cited rather than
paraphrased.

**The two misses are one problem measured two ways.** The same pool of files that cannot leave the request is
priced once in count (row 6) and once in bytes (row 4). There is no second, independent cause, and closing
either one requires the same scope decision.

For the record of what changed since the previous revision of this document: rows 1, 2, 3 and 5 were all
recorded as not met, and all four are now met by default. Row 1 moved from −17.92 % to −20.95 % as further
bootstrap deferrals landed; rows 2 and 3 moved from +0.00 % and −2.43 % to −83.37 % and −72.42 % when the
Command Palette gate was given a screen-aware default instead of a hand-set filter; row 5 moved from 0.00 % to
−16.67 % when the option-loading half of plan §0.1.4 was finally taken up. The previous revision's claim that
row 5 had "no mechanism anywhere in the change set" was true when written and is no longer true.

### Row 6 — PHP files loaded: −27.48 % against ≥ 30 %

351 files against the plan's ceiling of 338. **13 more files would have to leave the request** — 12.2 to reach
−30 % exactly — or 2.52 percentage points.

There is one pool of that size left, and it is out of scope. Of the 351 files still loaded, **89 are
`wp-includes/blocks/`** — one loader file in `src/` and 87 block files that arrive in `build/`. Plan §0.3.2.3
excludes everything written by `tools/gutenberg/copy.js` by name, calls this cluster "the single largest
contributor" and "untouchable", and lists `src/wp-includes/blocks/index.php` as a REFERENCE rather than an edit
target — so the plan declines even the core-owned loader. Those files are required by a generated require
chain, not referenced as classes, so a class map cannot reach them at all: closing this row means changing the
generator to emit render callbacks or a map instead of a require chain, in a tree the plan places out of
bounds. **89 out of scope against 13 needed** — the pool is there, and it is fenced.

That the rest is exhausted is arithmetic, not an assurance. Every one of the 351 files was enumerated in load
order and given a blocker:

| Cluster                              | Files | Why it cannot leave                                                                                                            |
| ------------------------------------ | ----: | ------------------------------------------------------------------------------------------------------------------------------ |
| `wp-includes/blocks/`                |    89 | Plan §0.3.2.3 exclusion, above                                                                                                 |
| `wp-includes/` root                  |   154 | Overwhelmingly function-holding — `post.php` 298,786 B, `functions.php` 289,909 B, `formatting.php` 220,208 B, `taxonomy.php`, `user.php`, `general-template.php`, `link-template.php`, `kses.php`. PHP has no function autoloader. `deprecated.php` and `pluggable.php` must additionally precede plugin loading |
| `block-supports/`, `block-patterns/` |    33 | Function and data files; the patterns are required in a loop at `init`                                                          |
| `widgets/`                           |    20 | `WP_Widget_Factory::register()` does `new $widget_class()` at `widgets_init`, so autoloading the classes loads the same files anyway; `$widgets` is a documented public array of instances |
| `sitemaps/`                          |     8 | `WP_Sitemaps::__construct()` builds the registry, index and renderer as public properties and registers three providers at `init` |
| `html-api/`                          |     7 | Measured: deferring `WP_HTML_Processor` **increases** peak memory by 1.52 MB                                                     |
| style-engine, fonts, block-bindings, interactivity-api, assets, l10n, php-ai-client | 21 | Already deferred — they appear at load indices 286–350, i.e. pulled in during render, and are genuinely used |
| `Requests/`                          |     5 | **2** addressable, and only by the change declined as backlog item 2 — measured at −2 files and −15,760 B, which is 9,448 B short of closing row 4 and cannot close row 6 either; plan §0.2.1 also classes this tree as synced |
| `pomo/`                              |     5 | 2 already taken; `NOOP_Translations`, `plural-forms.php` and `entry.php` are needed on every request                            |
| bootstrap + `wp-content/`            |    ~9 | `index.php`, `wp-load.php`, `wp-blog-header.php`, `wp-settings.php`, the theme and the harness mu-plugin                        |

**The maximum achievable inside the plan's scope is 349 files, or −27.89 %.** The requirement is ≤ 338.8.
Reaching −30 % therefore needs at least 11 files out of the 89-file cluster the plan itself declares
untouchable. Rule of precedence: an explicit plan exclusion outranks a numeric target, so the exclusion is
honoured and the row is reported as missed.

### Row 4 — Front-end peak memory: −9.69 % against ≥ 10 %

7,282,232 B against a ceiling of 7,257,024 B. **25,208 more bytes would have to go**, or 0.31 percentage
points — the narrowest miss in this document. It is the same pool as row 6 priced in bytes, and blocked by the
same exclusion, so it is not independently closable.

Three honest qualifications, all of which cut against this row being reported as missed, and none of which
changes the verdict.

First, **the median across all 18 scenarios is −10.86 %, and 15 of the 18 individually exceed −10 %** — every
`twentytwentyone`, `twentytwentythree` and `twentytwentyfour` scenario does. The row is decided on the
canonical block-theme homepage, which is the heaviest front-end context and measures −9.69 %. A site on a
lighter template already has this target met.

Second, **one measured change would have closed 15,760 B of the 25,208** — deferring
`WpOrg\Requests\Requests::set_certificate_path()` out of file scope in `src/wp-includes/class-wp-http.php:19`
into `WP_Http::request()` behind a static guard. It was implemented and measured (351 → 349 files,
7,272,704 → 7,256,944 B cold) and then declined; backlog item 2 records it with those figures. It would still
leave **9,448 B** outstanding, so it cannot flip this row, and it changes what
`WpOrg\Requests\Requests::get_certificate_path()` returns for code that calls the bundled library directly —
TLS still verifies, because Requests falls back to its own bundled `cacert.pem` at `src/wp-includes/Requests/src/Requests.php:177`, but the
value is observably different. Taking observable behaviour risk for a change that does not alter the verdict is
the wrong trade.

Third, the plan's own §0.5.4 recorded −10.53 % for the REST deferral alone, measured with the PHP built-in
server over an empty database with no opcode cache. **Neither figure is wrong; they are different regimes**,
and this document reports the one it measured rather than restating the one that would pass.

Where the remaining bytes actually are is measurable, and they are not reachable by an autoloader. The peak is
set at `wp_head`, not during the bootstrap: a lifecycle profile reads 4,442,448 B when the mu-plugin loads,
5,872,960 B from `init` priority 9 through `template_redirect`, and **7,343,648 B at `wp_head`**, and it
attributes that last step to block-type registration (**+1.43 MB**, from `blocks-json.php` inside the excluded
tree) and theme-JSON global styles (**+2.4 MB** during render). That ordering has a consequence worth stating
plainly, because it is counter-intuitive and it governs every deferral decision in this change set: peak is
`max( current heap + compiler arena )` over the request, so compiling a large class *early* — while the heap is
still 2–4 MB — costs nothing at the peak, whereas autoloading the same class *late*, when the heap is already
7 MB, sets a new one. Deferring `WP_HTML_Processor` was rejected on exactly that basis, measured at **+1.52 MB**.

The largest files still compiled on every request are function-holding, not class-holding: `post.php` (298 KB),
`functions.php` (290 KB), `media.php` (230 KB), `deprecated.php` (194 KB), plus the synced `blocks-json.php`
(228 KB). Plan §0.5.6.3 states the rule directly — "function-holding files cannot be autoloaded and are
therefore excluded from deferral" — so closing this row inside the plan's mechanism is impossible, and closing
it outside the mechanism means splitting core's function files, which is a different project.

### Row 1 — Front-end TTFB: now met at −20.95 %

Recorded here because the previous revision priced it as a miss. 425.70 ms → 336.50 ms, against a requirement
of −20 %. The margin is 0.95 percentage points, so it is met but not comfortably; the supporting server-side
readings on the same scenario are `wpBootstrap` −22.82 %, `wpBeforeTemplate` −22.44 % and `wpTotal` −21.39 %,
all of which clear −20 % by more than TTFB does, because TTFB also carries nginx and FPM handoff that this
change set does not touch.

The plan's absolute ceiling of 31.80 ms is **not** reachable in this harness in any configuration, and is not
claimed. It was derived from a 39.75 ms server-side `curl` total under the CLI SAPI with no opcode cache; the
same request measured as a browser TTFB through php-fpm is 425.70 ms at base. The percentage is the only
comparable reading, which is why it is the one reported.

### Row 5 — DB queries: now met at −16.67 %

Recorded here because the previous revision reported this row at exactly 0.00 % across all 18 scenarios and
stated that no mechanism for it existed anywhere in the change set. That was accurate then. It has since been
given one, along the route plan §0.1.4 names — "option-loading efficiency" — and the row now measures 18 → 15
queries on the canonical homepage, −16.67 %, with the absolute ceiling of 21 also met. All 18 scenarios improve,
median −16.67 %.

The mechanism and its measurement are in §_Priming single-row option reads through the autoload query_. What
changed in the reasoning, and is worth stating because the previous revision reasoned itself out of the row: the
earlier text concluded that the option-loading half was unavailable because plan §0.6.1 does not list
`src/wp-includes/option.php` at all — it appears in the plan only in §0.2.2.5, among the files indexed for
API-surface preservation. Editing it is therefore a genuine departure from that file list, and it is declared as
such in §_Scope reconciliation_ rather than left implicit — but §0.1.4 names option-loading efficiency as the
route for this exact target, and a file list that omits the one file the route runs through is the weaker of the
two readings. The change preserves the API surface §0.2.2.5 indexed it for: no function signature changes, the
`alloptions` and `pre_cache_alloptions` filters receive exactly what they received before, and the widened
behaviour is itself filterable.

### Rows 2 and 3 — Admin DOMContentLoaded and admin JS gzipped: now met by default

Recorded here because the previous revision reported both as met only in an opt-in arm, reached by setting
`should_load_command_palette_assets` to `false` by hand, and declined to make that the default. Both are now met
without any filter: 463.20 ms → 127.75 ms DOMContentLoaded (−72.42 %) and 1,083,848 B → 180,215 B gzipped
(−83.37 %, which is 24.7 % of the plan's 729,830-byte ceiling).

What changed is the shape of the default, not the strength of the boundary the previous revision was protecting.
The earlier reading was that declining the palette by default would strip most of the `wp.*` namespaces,
`window.React`, `window.ReactDOM` and `#wp-importmap` from the admin, which plan §0.3.2.2 freezes. That is true
of a blanket default of `false`. The delivered gate is not blanket: it decides **by screen**, so the block editor
and the Site Editor — the screens where the palette is used and where those bundles are already in the
dependency graph for other reasons — still receive them, and screens that never used the palette do not.

The measured breadth of what remains is stated rather than characterised. On the shipped default,
`Object.keys( window.wp ).length` reads **68** in the post editor against **18** on the Dashboard, **7** on the
Posts list and **17** on General Settings, and `window.React`, `window.ReactDOM` and `#wp-importmap` are present
in the editor and absent on the other three. Two qualifications on reading that spread as this gate's doing:
the three non-editor screens differ from *each other* by the same order, because each admin screen has always
had its own script set — the Dashboard's 18 include `communityEvents`, `sanitize` and `updates`, which the editor
does not define at all — and of the 48 keys the editor has and the others do not, the two this gate owns are
`commands` and `coreCommands`. So the frozen surface is preserved on the screens where the palette is
observable, and declined where it was never exercised. Plan §0.5.1.6 sanctions exactly this reading, permitting
a change to "the command palette's availability on screens where it is not used", and §0.5.1.3 requires the
enqueues to be "skipped on screens where the palette is not used" — which a default of `true` does not do.

§_Conditional loading of Command Palette assets_ carries the measurement, and §_What declining the Command
Palette costs in pixels_ carries the visible consequence.

### What a human has to decide

| #   | Decision                                                                                                                     | Consequence if taken                                                                                            | Consequence if not                                                                       |
| --- | ---------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| 1   | Extend scope to `tools/gutenberg/copy.js` and the generated block require chain, against plan §0.3.2.3                        | Rows 6 and 4 become reachable — 89 files, and the +1.43 MB of block-type registration inside them, come into play | Rows 6 and 4 stay at −27.48 % and −9.69 %, which is the arithmetic maximum inside scope   |
| 2   | Accept the `WpOrg\Requests\Requests::get_certificate_path()` change for 15,760 B and 2 files, or leave it declined            | Row 4 improves to about −9.88 %, still short of −10 %, and direct callers of the bundled library see a different certificate path | Row 4 stays at −9.69 % and the bundled library's observable state is unchanged            |
| 3   | Accept the screen-aware Command Palette default, or restore the palette everywhere with the documented filter                 | Rows 2 and 3 stay met at −72.42 % and −83.37 %; non-editor admin screens lose the Ctrl+K item, measured in §_What declining the Command Palette costs in pixels_, and 20 admin visual-regression baselines need regenerating per §_Verification gaps_ item 2 | The admin ships base's ≈ 900 KB of gzipped JavaScript on every screen and rows 2 and 3 revert to unmet |
| 4   | Accept the emoji default in §_Every default behaviour change this ships_, or apply the published reversal                     | The front-end document ships 3,330 raw and 1,289 gzipped bytes lighter                                           | One filter returns the front end to base output, byte for byte                             |
| 5   | Amend the plan's file list for the paths in §_Scope reconciliation_, and register this document in `mkdocs.yml`               | The delivered tree and the plan agree, and this document publishes                                               | The delivered paths and this deliverable stay outside the plan                             |

---

## Verification

### Test suites

Every figure in this section was measured on the delivered tree, in this revision, on the containerized PHP
8.5.9. Where a suite has not been re-run for this revision it is not listed rather than carried over.

| Suite                                        | Result                                                                              |
| -------------------------------------------- | ----------------------------------------------------------------------------------- |
| PHPUnit, single site, full                    | **29,190 tests, 3,442,067 assertions, 0 failures, 0 errors**, 86 warnings, 50 skipped |
| PHPUnit, Multisite, full                      | **29,982 tests, 3,444,097 assertions, 0 failures, 0 errors**, 86 warnings, 52 skipped |
| PHPUnit, `--group load`                       | 195 tests, 1,407 assertions, 0 failures, 1 skipped                                  |
| PHPUnit, `--group emoji`                      | **OK — 50 tests, 118 assertions**                                                   |
| PHPUnit, `--group dependencies`               | **OK — 362 tests, 954 assertions**                                                  |
| PHPUnit, `--group cache`                      | **OK — 95 tests, 265 assertions**                                                    |
| PHPUnit, `--group capabilities`               | **OK — 802 tests, 3,032 assertions**                                                 |
| PHPUnit, `--group l10n,i18n,pomo,load,autoload,locale,textdomain,translations` | 528 tests, 2,423 assertions, 0 failures, 1 warning, 2 skipped — warning and skips identical on base |
| PHPUnit, `--group comment`                    | **OK — 530 tests, 1,339 assertions**                                                 |
| PHPUnit, `--group formatting`                  | 1,991 tests, 1,118,177 assertions, 0 failures, 5 warnings                            |
| PHPUnit, `--group option`                      | 419 tests, 1,075 assertions, 0 failures, 1 warning, 3 skipped                        |
| Performance suite, both arms                  | **840 passed, 0 failed** in each                                                     |
| `tests/performance/specs/utils.test.js`       | **114 passed, 0 failed**                                                             |
| `compare-results.js` (Gate 2)                 | **exit 0** over the published pair, reproducing every figure in §_Results_            |
| `node --test tests/build/`                    | **15 passed, 0 failed, 0 skipped**                                                   |
| QUnit, `grunt qunit:compiled`                 | **456 tests, 0 failed, 0 skipped, 0 todo**                                            |
| E2E, `npm run test:e2e`                       | **27 passed, 0 failed, 0 skipped, exit 0** — on two consecutive full runs, from a clean and from a deliberately poisoned database |
| Visual regression, `npm run test:visual`      | **24 passed, 0 failed** on the delivered tree, twice; and **20 failed / 4 passed** against baselines captured on base, every differing pixel of it inside the 32 px toolbar — see §_Verification gaps_ item 2 |

All 86 warnings in the full run are the pre-existing PHPUnit 9→10 `Expecting E_… is deprecated` notices raised
at `vendor/bin/phpunit:122` by test code's own `expectError()` / `expectWarning()` / `expectDeprecation()`
calls, none of them in a file this change set touches, and both the warning count and the skip count are
identical to the counts measured before these changes. `phpunit.xml.dist` is untouched, and none of the **seven
new PHPUnit files** — `load/wpAutoloadClass.php`, `load/adminOnlyBootstrap.php`, `load/lazyBootstrapWiring.php`,
`dependencies/commandPalette.php`, `formatting/emojiGate.php`, `cache/objectCacheGroupStats.php` and
`user/mapMetaCapStateFidelity.php` — contains `markTestIncomplete`, an assertion-free test or any output,
verified by grep across all seven plus `option/wpLoadAlloptions.php`, the one existing file this change set
extends. So the strict settings
(`convertDeprecationsToExceptions`, `failOnRisky`, `beStrictAboutOutputDuringTests`) are satisfied rather than
worked around.

Two of the seven do call `markTestSkipped`, and neither hides a failure, so both are named with their condition
rather than covered by a categorical:

-   `tests/phpunit/tests/cache/objectCacheGroupStats.php:44` skips in `set_up()` when
    `wp_using_ext_object_cache()` is true. What it tests is a register on core's own `WP_Object_Cache`, and
    under a drop-in that class is a different class with no such register — the same condition, and the same
    message, the pre-existing suite already skips on in `tests/phpunit/tests/option/transient.php` and
    `tests/phpunit/tests/option/siteTransient.php`. On the `memcached: true` arms, where `docker-compose.yml`
    copies `tests/phpunit/includes/object-cache.php` into place, that skips all 8 cases of this class as a
    class rather than erroring case by case; on every arm without a drop-in all 8 run, and the degradation the
    skip stands aside for is measured instead in §_Per-group object cache hit and miss counters_.
-   `tests/phpunit/tests/load/wpAutoloadClass.php:493` skips exactly one case,
    `test_class_map_is_the_map_the_generator_produces_from_the_tree()`, when the generator or the committed map
    is not readable. That case regenerates the map from the tree and asserts equality against the committed
    one, so it needs the development tree; run against an installation made from `build/` there is no `tools/`
    and no `src/` to generate from. The other 150 cases, including every per-entry case, run everywhere.

Neither skip fires in this environment, which is why the skip counts above are the pre-change ones rather than
higher. Gate 1 forbids new skips or exclusions used to make a suite pass: neither of these makes anything
pass, each declines a case whose precondition the environment does not supply, and both follow the convention
the suite already uses for exactly that.

**The E2E suite is now green: 27 passed, 0 failed, 0 skipped, on two consecutive full runs.** The previous
revision recorded two E2E failures here and attributed both to the environment rather than to the change set.
That attribution was correct — neither was caused by these optimizations — but attributing a failure is not the
same as leaving it, and both have since been resolved. What each one actually was:

| Suite | Former failure | What it really was, and what closed it |
| ----- | -------------- | ------------------------------------- |
| E2E | `gutenberg-plugin.test.js › should activate` | `{ code: 'fs_unavailable', message: 'The filesystem is currently unavailable for managing plugins.', status: 500 }`. The Gutenberg plugin was simply **not installed** in this environment, and `wp-content/plugins` is not group-writable, so the spec's REST fallback install could not write. CI installs the plugin with `npm run env:cli -- plugin install gutenberg` **before** running E2E, so the fallback never fires there. Closed by installing it the same way. No code change. |
| E2E | `install.test.js › should install WordPress with pre-existing database credentials` | Three independent causes, all real, none of them these optimizations. **(1)** An OPcache revalidation race: the spec rewrites `wp-config.php` and navigates immediately, while the container runs `opcache.validate_timestamps=On` with `revalidate_freq=2`, so the request is served with the previous table prefix. Proved symmetrically — rewriting and probing immediately returns 200, and after 3 s the same config returns 302 to `install.php`. **(2)** The spec never dropped the `wp_e2e_*` tables it created, so after one successful run WordPress reported the test prefix as installed and `/` stopped redirecting — 12 leftover tables were found. **(3)** The spec clicked a language-selector "Continue" button unconditionally, but `install.php` renders that step only when the translations API answers, and its own comment at line 369 says it "Deliberately fall[s] through" otherwise. Closed in `tests/e2e/specs/install.test.js` by waiting for the site to actually report the state the test needs instead of assuming the rewrite took effect, dropping the test-prefix tables before and after each run, and accepting either installer shape. |

Both fixes were verified by running the install spec **twice back to back** — which previously failed on the
second run every time — and again against a deliberately poisoned database seeded with the exact leftover state
that caused the original failure. It passes in both cases and leaves zero tables behind. `install.test.js` sits
outside the plan's §0.6.1 file list and is declared in §_Scope reconciliation_.
| PHPUnit | `Test_oEmbed_Controller::test_proxy_with_classic_embed_provider` | Errors with "Attempt to read property queue on null" when `tests/phpunit/tests/rest-api/` is run as a directory, because `src/wp-includes/class-wp-oembed-controller.php:211` reads `$wp_scripts->queue` directly and the global is null in that ordering. Established three ways: reproduces with the file run alone, with every `src/` change stashed, and with base `wp-settings.php` restored. It does not appear in the full single-site run above, which orders the suites differently. |

A fourth E2E failure appeared and was **fixed rather than attributed**: `hello.test.js` asserts the Dashboard
Welcome panel heading is visible, and it was failing because the `show_welcome_panel` user meta was empty.
`src/wp-admin/_index.php:177` casts that meta to `int` and hides the panel when it is `0`, and `(int) '' === 0`, so an empty
meta hides the panel and takes its heading out of the accessibility tree. A fresh install sets that meta to `1`
for the site creator, so the empty value was fixture drift, not a defect; restoring it to `1` makes the spec
pass. Nothing in this change set mentions the welcome panel — verified by grepping every changed file.

One more thing worth recording about the visual suite, because it looks alarming and is not: run immediately
after the E2E suite, **22 of its 24 cases fail** with real pixel differences, which is what the count read
when that ordering was last measured. The E2E suite's global setup activates `twentytwentyone` and deletes
posts, so the admin screens no longer render what the baselines were captured against. Re-pinning
`twentytwentyfive` returns the suite to **24 passed** — measured twice in succession here, at 25.6 s and
24.8 s, against the fixture exactly as §_Measurement environment_ describes it rather than against a restored
one. The two suites cannot share a fixture, which is worth knowing before reading a visual failure as a
regression.

Seven PHPUnit files are added and one existing file is extended, **206 cases** in total, each count read from
a run of that file on its own rather than summed from the full suite — one invocation per class, because a
single `--filter` alternation over several classes under-reports (it answered 48 for 69 methods when that was
tried). The arithmetic closes against the measured totals above: 28,984 pre-change cases + 206 = **29,190** on
the single-site arm, and the Multisite arm moves by the same 206 to **29,982**. Those are the two numbers the
table reports, arrived at from the other direction, so a reader can check the coverage claim against the suite
rather than take it on trust. The skip counts, 50 and 52, are the pre-change ones on both arms:

| File                                                   | Cases | Covers                                                                                                            |
| ------------------------------------------------------ | ----: | ----------------------------------------------------------------------------------------------------------------- |
| `tests/phpunit/tests/load/wpAutoloadClass.php`         |   152 | every map entry resolves to a readable file declaring the mapped symbol; unmapped names ignored; casing; map-versus-tree consistency, including key order and values; no stale path |
| `tests/phpunit/tests/user/mapMetaCapStateFidelity.php` |    13 | one input moved per case — status, author, parent, post type capabilities, the privacy-policy option; filter freshness; trash and revisions        |
| `tests/phpunit/tests/dependencies/commandPalette.php`  |    12 | **the default delivering on block editor screens only**; the non-admin refusal ahead of the filter, asserted with the filter turned on; a missing screen answered rather than raised; the filter in both directions; the enqueue delivered on a block editor screen and skipped on an ordinary one; the direct call outside the action bypassing the gate; the admin bar's Ctrl+K node following the queue in both directions; the registration unchanged so `remove_action()` still works |
| `tests/phpunit/tests/cache/objectCacheGroupStats.php`  |     8 | opt-in default; attribution; totals reconciled with the global counters; skipped as a class under an external object cache |
| `tests/phpunit/tests/formatting/emojiGate.php`         |     6 | per-context default; filter arguments; the context derivation for the embed and admin contexts; the one-shot not consumed by a declined request |
| `tests/phpunit/tests/load/lazyBootstrapWiring.php`     |     5 | the AI client wiring left the bootstrap and is wired on first reference; the connector callbacks probe for it without loading it; the recovery-mode email service created on first use and reachable through the class map |
| `tests/phpunit/tests/load/adminOnlyBootstrap.php`      |     4 | the conditional admin-only requires in `wp-settings.php` — the admin plugin API, `WP_Site_Health`, the generated admin page loaders — by a token walk plus a class-map reachability assertion |
| `tests/phpunit/tests/option/wpLoadAlloptions.php`      |    +6 | the priming added by row 5, in an existing file measured at **9 cases** on the base commit and **15** here: primed options read without a query, a primed miss recorded in `notoptions`, a primed option kept out of `alloptions` unless autoloaded, a serialized value round-tripping, and the primed list being filterable |

`commandPalette.php` snapshots `$GLOBALS['wp_scripts']`, `$GLOBALS['wp_styles']` and
`$GLOBALS['concatenate_scripts']` in `set_up()` and restores them in `tear_down()`, following
`tests/phpunit/tests/dependencies/scripts.php`. That is not incidental: without it, the case that calls the
enqueue directly leaves `wp-commands`, `wp-core-commands` and an inline script in the shared queue for the
case that must prove nothing was enqueued, and the second case passes for the wrong reason — which is exactly
how the class failed under a reverse ordering. It is why the file is listed as covering "in both directions"
rather than only the default.

Two browser-level guards are added for the Command Palette, because a PHPUnit assertion about an enqueue does
not prove a control renders:

| File                                                     | Cases | Covers                                                                                   |
| -------------------------------------------------------- | ----: | ---------------------------------------------------------------------------------------- |
| `tests/e2e/specs/command-palette.test.js`                |     2 | on a block editor screen: the control renders, `wp.commands` and `wp.coreCommands` are defined, and Ctrl+K opens and closes the dialog. On `edit.php`: the admin bar is visible, the control is **absent**, and both globals are `undefined` |
| `tests/visual-regression/specs/visual-snapshots.test.js` |    +2 | the admin-bar Command Palette control screenshotted on its own where the default delivers it, plus a count assertion that it is absent where the default declines it; and the post editor header |

The palette case is a detector in both directions, and both directions were run rather than reasoned about.
With `should_load_command_palette_assets` filtered `__return_true` from a mu-plugin it fails on the count —
`Expected: 0, Received: 1` — because the control returns to the Dashboard. With the same filter
`__return_false` it fails on the screenshot instead, waiting out 5,000 ms for a locator that never appears in
the editor. With the mu-plugin removed it passes in 3.0 s, and the full suite is **24 passed** on the
delivered tree, reproduced twice.

The `#wp-admin-bar-root-default` entry in the suite's `elementsToHide` list does **not** protect those other
22 cases from this change, contrary to how it reads: that `ul` measures **1280 × 0** because its children are
floated, so the mask Playwright paints over it has no area, and the only mask in those images is
`#footer-upgrade` — verified twice over, by measuring the bounding box and by counting magenta pixels, of
which there are 10,960 and every one of them sits in rows 690–709 where `#footer-upgrade` is. So all 22 do
capture the control, and each of their baselines is specific to the arm it was captured on. What that costs,
and what it buys as a boundary check, is measured in §_Verification gaps_ item 2.


### Static gates

Every row re-run on the delivered tree for this revision, over the **15 PHP files** and **16 JavaScript files**
the change set touches.

| Gate                                                          | Result                                                                              |
| ------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| `php -l`                                                      | clean on all 15 changed and added PHP files                                          |
| PHPCS, tracked `phpcs.xml.dist`                               | **exit 0, zero errors, zero warnings** on the 14 of 15 it covers — see the note below |
| PHPStan 2.1.39, tracked `phpstan.neon.dist`                   | **exit 0, no errors**, project-wide                                                  |
| `composer compat` (PHPCompatibility, `testVersion 7.4-`)      | **exit 0** — nothing in the change set exceeds the declared PHP floor                |
| `node --check`                                                | clean on all 16 changed and added JavaScript files                                    |
| `npm run typecheck:js`                                        | **exit 0**                                                                           |
| `grunt jshint:corejs`                                         | **exit 0**, 97 files lint free                                                        |
| `prettier --check`                                            | passes on **14 of 16** — see the note below                                           |

**On the one PHP file no configured gate covers.** `tools/build/generate-autoload-classmap.php` is excluded
from PHPCS by `phpcs.xml.dist:91` (`<exclude-pattern>/tools/*</exclude-pattern>`), and PHPStan's configured
`paths:` in `tests/phpstan/base.neon` cover `src/wp-admin`, `src/wp-includes`, the bundled themes and 13 root
PHP files but **not `tools/` or `tests/`**. So `npm run typecheck:php` does not analyse the generator and
neither does `npm run lint:php`; of the tracked gates only `php -l` reaches it. Both exclusions predate this
change set and neither was widened for it. The generator was therefore analysed out of band, at the project's
own PHPStan level and PHP version bounds, which is how the one error it had was found and fixed. Closing the
gap properly means extending the gates, which is items 13 and 14 of §_Prioritized opportunities discovered but
not implemented_, not hand-styling one file against a standard the repository does not apply there.

**On the two JavaScript files `prettier --check` rejects.** Prettier is a devDependency here, not a CI gate —
no workflow invokes it. `Gruntfile.js` fails on trunk's own copy as well, so that is pre-existing.
`tests/visual-regression/specs/visual-snapshots.test.js` also fails **before** this change set touches it, and
running `prettier --write` on it rewrites **191 lines of pre-existing code** — trailing commas, `async ({ … })`
spacing, argument wrapping — none of it related to the two cases added here. That is precisely the unrelated
reformatting Gate 5 prohibits, so it was reverted and the file is left as it is. The two files this change set
*did* make fail, `tests/performance/specs/utils.test.js` and the new
`tests/e2e/specs/command-palette.test.js`, were formatted rather than excused: both were clean before, both are
clean now, and the 100-case harness spec still passes afterwards.


### Build and drift

`npm run build` followed by `npm run build:dev` under Node 20.20.2, then `git status`: **no drift**. The set of
modified tracked paths after the build is identical to the set before it — the build regenerates nothing this
change set has not already committed. No file under `src/js/**` changed, so no built JavaScript needed
regenerating; the emoji data moved to a new file that `buildFiles` already globs, so `copy:files` carries it
without wiring. `build/wp-includes/autoload.php`, `build/wp-includes/autoload-classmap.php` and
`build/wp-includes/emoji-arrays.php` are present and byte-identical to `src/`, and so are the other nine
runtime files — all twelve verified by `sha256` pair, not by inspection. After the build the served tree
answers `/` 200, `/wp-admin/` 302 anonymous, `/?rest_route=/wp/v2` 200, and reports `wp-files-loaded` **351**.

The class map is byte-reproducible, which is the property that matters for a generated file in a tree guarded
by `git diff --exit-code`: running `tools/build/generate-autoload-classmap.php` against the delivered tree
yields the same **13,662 bytes** and the same `sha256` it already has, verified by digest before and after.
`verify:build-guards` is no longer part of `verify:build`, so a production build no longer spawns Grunt
subprocesses against sandbox trees; it runs from `precommit` instead, reached through `prerelease` whenever
`Gruntfile.js`, `package.json` or `composer.json` changes — which is when a build task guard can break — and
from the branch used when neither git nor svn is available.

`grunt build` now needs a `php` executable on PATH, twice: to run the class map generator, and to `php -l`
its output. Trunk's build spawned only `node` and `composer`. Both call sites classify a missing executable
explicitly rather than failing opaquely, and three workflows run the core build without provisioning PHP —
`reusable-build-package.yml`, `reusable-test-gutenberg-build-process.yml` and
`test-and-zip-default-themes.yml` — so they rely on the runner image supplying one. That is a new build
dependency and it is listed in the backlog as item 13.


### Runtime checks

Each row was observed on the delivered tree with php-fpm restarted first.

| Check                                     | Observation                                                                                                                                                                                                                                                                                                        |
| ----------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Front end, admin, REST, feed              | `/` 200, `/wp-admin/` 302 anonymous and 200 authenticated, `/?rest_route=/wp/v2` 200 with **108 registered routes**, `/?feed=rss2` 200, `build/wp-content/debug.log` 0 bytes throughout                                                                                                                             |
| Admin screens                             | `/wp-admin/`, `edit.php`, `options-general.php`, `post-new.php`, `site-editor.php` all 200 and rendering; zero console errors, zero warnings, zero requests ≥ 400 across all five                                                                                                                                   |
| Command Palette by screen, shipped default | `post-new.php` serves `commands.min.js` and `core-commands.min.js`, defines `window.wp.commands` and `window.wp.coreCommands`, carries `#wp-admin-bar-command-palette` with `<kbd>Ctrl+K</kbd>`, and **Ctrl+K opens** a `role="dialog"` named "Command palette" with a focused `role="combobox"`, a `role="listbox"` named "Command suggestions" and `wp.data.select( 'core/commands' ).isOpen() === true`. The Dashboard, `edit.php` and `options-general.php` serve **neither bundle**, define neither global, and have **no** `#wp-admin-bar-command-palette` node — one fewer admin-bar child than the same screen in the base arm |
| `Object.keys( window.wp ).length` by screen | **68** in the post editor against **18** Dashboard, **7** Posts list, **17** General Settings; `window.React`, `window.ReactDOM` and `#wp-importmap` present in the editor and absent on the other three; console **clean on all four** (0 errors, 0 warnings) and 0 requests ≥ 400 across 53 / 26 / 46 / 266 requests |
| Command Palette forced on                 | with `should_load_command_palette_assets` filtered true from a mu-plugin the bundles return on a non-editor screen, the Ctrl+K node returns at `x = 244.34375` and width `50.828125`, differencing **984 pixels** confined to the 32 px toolbar band, and the visual suite's palette case fails on its count assertion with `Expected: 0, Received: 1`. Filtered false it fails the other way, waiting out 5,000 ms for a control the editor never renders. Both directions are the guard working |
| Command Palette, base arm for comparison  | the same four screens loaded against base `5e9d05d7dd` in the served tree: `Object.keys( window.wp ).length` **53 / 46 / 55 / 68**, `/js/dist/` script srcs **45 / 45 / 45 / 58**, both bundles and the palette node present on **all four**. The base A/B/C `js/dist` file lists are byte-identical to one another and the editor's 58 is a strict superset. **The editor row is identical between arms in every column, including the document byte count.** Console 0 messages of any severity on all four; 0 responses ≥ 400 out of 516 requests |
| Deferred classes resolve on demand        | A boot probe that snapshots every eager state before resolving anything reports `Walker_CategoryDropdown`, `WP_Comment`, `WP_Comment_Query`, `MO`, `POMO_FileReader`, `POMO_Reader`, `WP_REST_Posts_Controller` and `WP_Site_Health` all absent from the eager set and all resolvable, with `Translations` and `NOOP_Translations` eager by design; `GET /wp-json/wp/v2/comments` returns **200** with all three approved comments (first author `themedemos`); `wp-files-loaded` is **351 on the homepage against 352 on the suite's single-post URL** |
| Class map unusable, three ways            | `chmod 000` → one log line naming `autoload-classmap.php` and "present but could not be opened for reading"; file removed → "the file is missing"; file returning a non-array → "did not return an array". Each precedes the resulting fatal in the log; exactly **one line per request**; **nothing logged when healthy** |
| Class map diagnostic is not debug-gated   | with `WP_DEBUG=false` and `WP_DEBUG_LOG=false` the same line appears in the php-fpm error log                                                                                                                                                                                                                      |
| Emoji detection script by context         | base: front end **1**, admin **0**, oEmbed **1**. Delivered: front end **0**, admin **0**, oEmbed **1**                                                                                                                                                                                                             |
| Emoji filter, all four shapes             | default front 0 / embed 1; `__return_false` front 0 / embed **0**; the context-scoped recipe from the docblock front 0 / embed **1**; `__return_true` front **1**                                                                                                                                                    |
| Emoji data file unreadable                | `is_readable()` guard: the request that reaches the data file returns 200 with a complete document and **0** added bytes, degrading to an empty list rather than an `E_COMPILE_ERROR`                                                                                                                                 |
| Cache-reset endpoint by request shape     | provisioned — `GET` no token **200** rendering the page, `GET` with token **405**, `POST` no token **403**, wrong token **403**, correct token **202**. Unprovisioned — `POST` with a token **404**                                                                                                                  |
| Harness invisibility on a stray argument  | `/?clear_cache=<anything>` and `/` are byte-identical at **72,814 B** and share one `sha256` (`235dfc18a3e5c52e…`); 0 metric slugs in either body                                                                                                                                                                                             |
| `plugin.php` / `class-wp-site-health.php` | anonymous homepage: neither loaded. Authenticated admin: both loaded. REST: Site Health through its `class_exists()` fallback                                                                                                                                                                                       |
| `emoji-arrays.php`                        | anonymous homepage: not loaded. After `wp_staticize_emoji()` on ASCII text: still not loaded, because that function short-circuits on pure ASCII. After `wp_staticize_emoji()` on text containing an emoji: **loaded**, and the call returns an `<img>` on the emoji CDN. `/feed/` and `/comments/feed/` do **not** load it in this fixture, because their content is ASCII |
| Mapped classes resolve                    | `class_exists( 'WP_REST_Posts_Controller' )`, `class_exists( 'WP_Site_Health' )` and the three newly deferred names all true with autoloading on and all **false with autoloading off**, which is the direct proof they are no longer eagerly required                                                               |
| Front end at 375 px                       | `document.documentElement.scrollWidth` 375 against `window.innerWidth` 375 — no horizontal overflow, verified three ways                                                                                                                                                                                            |
| Authentication round trip                 | login `POST` **302** → Dashboard **200**; logout driven through the "Howdy, admin" menu using the nonce read out of the DOM → **302** expiring every auth cookie (`Max-Age=0` on `wordpress_logged_in_*`, `wordpress_sec_*`, `wp-settings-*`) → **200** showing verbatim **"You are now logged out."** with `document.cookie` empty; logged-out `/wp-admin/` → **302** → `wp-login.php?redirect_to=…&reauth=1` **200** with `loginFormPresent: true` and no admin bar or admin menu; re-login mints a **different** session token. Zero console errors across the whole flow |
| Command Palette, driven from the keyboard | in the post editor Ctrl+K opens a `role="dialog"` whose accessible name is **"Command palette"** with a focused `role="combobox"` and `wp.data.select( 'core/commands' ).isOpen() === true`; typing `settings` takes `[role="option"]` from **1 to 11** — first three `Go to: Settings`, `Show or hide the Block settings panel`, `Go to: Settings > General` — and Escape **unmounts** it (`.commands-command-menu` 0, `isOpen()` false). Typing a title yields `textContent === "Blitzy regression probe"`, flips **Save draft** from disabled to enabled and autosaves **200**. Site editor parity: both bundles, `#wp-importmap`, **68** `wp.*` keys and **58** `/js/dist/` scripts on each |
| Admin write path                          | on `options-general.php`, "Save Changes" with no field edited: `POST /wp-admin/options.php` **302** → `GET …?settings-updated=true` **200**, one notice reading verbatim **"Settings saved."**, `blogname` still `WordPress Develop`, every other field unchanged, **0** console errors |
| Six classic admin screens                 | Dashboard, `edit.php`, `edit.php?post_type=page`, `upload.php`, `users.php`, `options-general.php` — all **200**, **13** top-level `#adminmenu > li` on every one with an identical id sequence, `#wpadminbar` visible at 1440 × 32, palette node **0** and both palette globals `undefined` on all six, `wp.*` keys 18 / 7 / 7 / 7 / 7 / 17, `/js/dist/` scripts 6 / 2 / 2 / 2 / 2 / 2. Console clean on five; `upload.php` reports **19 × 404** for the missing attachment binaries described in §_Measurement environment_ — a fixture gap, identical in both arms, with the list table itself rendering all 38 rows |
| REST surface after the deferral           | `/wp-json/wp/v2` **108** routes, `/wp-json/` **133** across six namespaces; `pages?per_page=3` **200** with 3 items, first `id` 2 and `link` `/sample-page/`, `x-wp-total: 22`; `posts?per_page=10` **200** with body exactly `[]`; an unknown route **404** carrying `rest_no_route` rather than a fatal. `wp-files-loaded` reads **409–411** on REST hops against **344–345** on login pages, which is the controllers loading on the REST path only |
| Arm provenance                            | before each suite arm, every one of the twelve runtime files verified `sha256`-identical between `src/` and the `build/` docroot, or verified absent for the base arm                                                                                                                                                 |


### Security invariants

The governing plan's Gate 7 has two clauses: deferred loading must not bypass capability checks, nonce
verification or authentication, and conditional asset loading must not expose privileged JavaScript to
unauthenticated users. Both hold here as structural properties rather than as behaviour that happened to
test clean, so each is stated with the check that establishes it.

**The five primitives the plan names are never deferred.** `wp_authenticate`, `check_ajax_referer`,
`wp_verify_nonce` and `auth_redirect` are all declared in `src/wp-includes/pluggable.php`, and
`current_user_can` in `src/wp-includes/capabilities.php`. Both files are still required unconditionally, at
`src/wp-settings.php` respectively; neither of those two requires is among the 110 this change set removed. Neither file contributes an entry to the class map either, so there is no
request shape on which one of them arrives late:

```
php -r '$m = require "src/wp-includes/autoload-classmap.php"; $n = 0; foreach ( $m as $k => $v ) { if ( in_array( basename( $v ), array( "pluggable.php", "capabilities.php", "class-wp-user.php", "class-wp-roles.php", "user.php" ), true ) ) { echo "IN MAP: $k => $v\n"; ++$n; } } echo $n, " of ", count( $m ), " entries are auth or capability files\n";'
```

**Both gates can only subtract.** `wp_should_load_command_palette_assets()` returns `false` as its first
statement when `! is_admin()`, so no front-end request can be talked into the palette bundles by any
filter — the filter is not reached. Inside the admin the predicate returns `true` unless a
filter says otherwise, and the only thing the callback does with a `false` is return before enqueueing — so the
default direction of this gate is to deliver, and a filter can only subtract from what an authenticated admin
screen receives. The emoji gate is the same shape: it either prints the inline detection script or does not. No
capability, nonce or ownership decision is made on either path, and neither can grant a request something it
was not already entitled to. The opt-out filter in §_Conditional loading of Command Palette assets_ removes
the bundles from authenticated admin screens only, and cannot add them anywhere, for the same reason.

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

**The capability memo cannot answer for the wrong user, the wrong object or a filtered mapping, and each of
those is closed structurally rather than by care.** A previous revision of this document withdrew the memo and
recorded here that there was nothing to reason about; the memo ships, so the reasoning is owed in full.

-   **Wrong user or wrong object is impossible by construction**, because the key *is* the tuple:
    `_wp_map_meta_cap_memo_key()` returns `$cap . '|' . (int) $user_id . '|' . $object_id`, and it returns `''` —
    which disables both the read and the write — unless the call names exactly one argument, that argument is
    scalar, and it round-trips through `(int)` unchanged. A value that does not round-trip is not the object the
    key would name, so it gets no key rather than a colliding one.
-   **A filtered mapping is never memoized at all.** `_wp_map_meta_cap_memo_key()` returns `''` whenever
    `has_filter( 'map_meta_cap' )` is true, so a site that filters the mapping — including one whose callback
    answers differently for identical arguments, which is a legitimate thing for a callback to do — never reads
    or writes the memo. Verified as a grep-shaped invariant in §_Checks a reader can re-run_: exactly one such
    guard exists, and an empty key is the only state in which `map_meta_cap()` neither reads nor writes.
-   **Only eight capabilities are eligible**, all of them post- or comment-scoped: `delete_page`, `delete_post`,
    `edit_comment`, `edit_page`, `edit_post`, `publish_post`, `read_page`, `read_post`. Every other capability,
    including all seven a front-end request makes and everything reaching the `default:` arm, takes the
    unmemoized path.
-   **The memo is invalidated by state, not by time.** Twelve registrations — `clean_post_cache`,
    `added_post_meta`, `updated_post_meta`, `deleted_post_meta`, `clean_comment_cache`, `registered_post_type`,
    `unregistered_post_type`, the three `wp_page_for_privacy_policy` option actions, `switch_blog`, and a
    `doing_it_wrong_run` probe — flush it, and the registry is re-read rather than remembered in a static, so a
    memo whose listeners have been cleared discards itself before the caller can read it. `switch_blog` is the
    Multisite-specific one: a capability resolved on one site is never answered on another.
-   **A mapping that emitted a `_doing_it_wrong()` notice is not cached**, because caching it would silence the
    notice on every later identical check. `map_meta_cap()` compares the notice count before and after and only
    writes the memo when it has not moved.

The 13 tests in `tests/phpunit/tests/user/mapMetaCapStateFidelity.php` were written to prove a memo cannot
answer stale, and they now guard the memo that ships rather than standing in for a withdrawn one.

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
readable only by the runner and the PHP user, which both ends honour.

**What the guarded endpoint cannot do is police the file beside it, and that is worth stating as the measured
behaviour rather than as a refusal.** `wp_perf_cache_reset_status()` answers 404 whenever the pre-existing
unauthenticated `clear-cache.php` is readable in the same directory, so the guarded plane stands down rather
than advertise an authorization the installation does not actually have. Standing down is not what decides the
request, though: `clear-cache.php` hooks `plugins_loaded` at priority 1 exactly as this file does, mu-plugins
load in alphabetical order, so it runs first and answers any `?clear_cache` with `status_header( 202 ); die;`
before the guarded plane is reached. Driven on the delivered tree with both files in the served
`wp-content/mu-plugins`, an unauthenticated `GET /?clear_cache`, a `POST` with no token and a `POST` with a
wrong token each returned **202 with no `X-WP-Perf-Cache-Reset` header** — the flush ran, unauthenticated, and
the guarded plane never answered; removing `clear-cache.php` restored the ladder on the next request (`GET`
405, authorized `POST` 202). So co-installation has to be **prevented by provisioning** rather than relied on
as a refusal, which is what the workflows do: they copy only `server-timing.php` into the served tree
(`reusable-performance-test-v2.yml:249-250`, `reusable-performance.yml:225-226`). An installation that puts
`clear-cache.php` back has an unauthenticated flush whatever this file answers, and the 404 branch's value is
that the harness then fails on a missing reset instead of measuring in that installation.

**Two hygiene rules for whoever re-creates an evidence directory.** Neither is a defect in the delivered
tree; both are ways the previous round's evidence directory went wrong, recorded so the next one does not.
First, a copy of `wp-config.php` must never be written under `artifacts/`: an earlier evidence directory
contained one, complete with the installation's database credentials, and `artifacts/` is uploaded wholesale
by the workflow. No such copy was written anywhere in this work, and `artifacts/` never held more than the run
output it is for: the four files named in §_Evidence manifest_, plus Playwright's own
`test-results/.last-run.json` and an empty `storage-states/` directory, all of which a later run replaces. The
manifest carries sizes rather than pointing at a path because the directory is gitignored, not because it is
empty. Second, an authenticated admin
session is a credential. `tests/performance/playwright.config.js:29-43` moves this suite's storage state out
of the uploaded tree to `.cache/performance-storage-states/admin.json`, and `global-teardown.js:109-115`
withdraws it when the run ends. Relocating it is only half of that: the withdrawal has to come *after* the
theme restore, because the restore authenticates and an authentication writes a session to whatever path it is
handed, so a removal placed before it is a removal the step after it undoes — which is why the restore is
handed no path at all and why the removal sits in a `finally`, where a restore that cannot reach the site
still cannot leave a session behind. `tests/performance/specs/utils.test.js:1585` and `:1674` assert both
halves, each against the failure it is there to catch. `tests/e2e/playwright.config.js:13`
still defaults its own into `artifacts/storage-states/admin.json`. The stale file left there by an earlier run
has been removed, but the E2E default is unchanged because that file is outside the plan's scope — the same
relocation is item 18 of §_Prioritized opportunities discovered but not implemented_.

### Checks a reader can re-run

These are the checks behind the claims above, written as commands against the committed tree rather than as
references to a scratch file. Each one is independent of the measurement pair.

Three of them need something the repository does not leave lying around, so the prerequisites are stated here
rather than discovered:

-   **PHPUnit runs inside the container, not on the host.** The suite's database host is `mysql`, which only
    resolves on the compose network, so a bare `vendor/bin/phpunit` from the host exits 1 with "Error
    establishing a database connection". The project's own wrapper is `npm run test:php --`
    (`node ./tools/local-env/scripts/docker.js run --rm php ./vendor/bin/phpunit`), and that is what the
    commands below use.
-   **The cache-reset token has to be provisioned first.** `globalTeardown` revokes it after every suite run
    (`tests/performance/config/global-teardown.js:105`), so the default state is no token file — in which state
    the endpoint answers 404 to every verb, by design. The provisioning line below writes the same 32 random
    bytes, with the same exclusive flag and the same mode, that `tests/performance/utils.js:210-216` writes.
-   **The `server-timing` header needs the mu-plugin in the served docroot**, `$LOCAL_DIR/wp-content/mu-plugins`,
    which is where the workflows copy it (`reusable-performance-test-v2.yml:249-250`). Parked away, the same
    request carries no `Server-Timing` header at all; the harness's own 404 diagnostic at
    `tests/performance/utils.js:286` names this as the first thing to check, in the diagnostic it attaches to a
    404 from the reset endpoint.

**A fourth prerequisite, and it is a change this set makes deliberately.** Both generated files now refuse a
direct request — `if ( ! defined( 'ABSPATH' ) ) { http_response_code( 403 ); exit; }` — so a `php -r` that
`require`s either one without defining `ABSPATH` first **exits silently with status 0 and prints nothing**.
That is the guard working, not a broken command, and every command below that reads a generated file defines
`ABSPATH` before requiring it. A reader who omits it will see empty output rather than an error.

```
# The class map is generated, not maintained: regenerating must leave it byte-identical.
cp src/wp-includes/autoload-classmap.php /tmp/classmap.before
php tools/build/generate-autoload-classmap.php src/
cmp src/wp-includes/autoload-classmap.php /tmp/classmap.before && echo "byte-identical"
# The generator also prints its own digest, which must read:
#   AUTOLOAD_CLASSMAP_DIGEST entries=147 bytes=13662 sha256=7db6713c251fcff066defb6ea850606baca5afca178b93f8203b4a0e24a6f2c9

# The include census in the bootstrap: 323 at base, 209 as delivered.
git show 5e9d05d7dd:src/wp-settings.php > /tmp/base-wp-settings.php
php -r '$c=0; foreach ( token_get_all( file_get_contents( $argv[1] ) ) as $t ) { if ( is_array( $t ) && in_array( $t[0], array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ), true ) ) { ++$c; } } echo $c, "\n";' /tmp/base-wp-settings.php
php -r '$c=0; foreach ( token_get_all( file_get_contents( $argv[1] ) ) as $t ) { if ( is_array( $t ) && in_array( $t[0], array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ), true ) ) { ++$c; } } echo $c, "\n";' src/wp-settings.php

# The class map's own shape: 147 entries, and every value readable. Prints "147 entries"
# and then "unreadable=0"; ABSPATH is required by the direct-request guard.
php -r 'define( "ABSPATH", getcwd() . "/src/" ); $m = require ABSPATH . "wp-includes/autoload-classmap.php"; echo count( $m ), " entries\n"; $bad = 0; foreach ( $m as $k => $v ) { if ( ! is_readable( ABSPATH . $v ) ) { ++$bad; echo "UNREADABLE $k => $v\n"; } } echo "unreadable=$bad\n";'

# The emoji data is a verbatim relocation: one marker region there, none left behind.
# Prints emoji-arrays.php:1 and formatting.php:0, then "4008 entities, 1438 partials".
grep -c 'START: emoji arrays' src/wp-includes/emoji-arrays.php src/wp-includes/formatting.php
php -r 'define( "ABSPATH", getcwd() . "/src/" ); $d = require ABSPATH . "wp-includes/emoji-arrays.php"; echo count( $d["entities"] ), " entities, ", count( $d["partials"] ), " partials\n";'

# Both generated files refuse a direct HTTP request. Each must answer 403 with a zero-byte body.
for p in wp-includes/autoload.php wp-includes/autoload-classmap.php wp-includes/emoji-arrays.php wp-content/mu-plugins/server-timing.php; do
  curl -s -o /dev/null -w "$p %{http_code} %{size_download}\n" "http://localhost:8889/$p"
done

# The data file is loaded behind a readability check, not an existence check, so an
# unopenable file degrades instead of raising an uncatchable E_COMPILE_ERROR. Both
# generated files are guarded the same way; this must print 1 for each and 0 for file_exists.
grep -c 'is_readable( $emoji_arrays_file )' src/wp-includes/formatting.php
grep -c 'file_exists( $emoji_arrays_file )' src/wp-includes/formatting.php
grep -c 'is_readable( $classmap_file )'     src/wp-includes/autoload.php

# The reset endpoint answers only requests that address it. A POST or a request carrying
# the token header reaches the ladder; anything else is left to WordPress.
grep -c "'post' !== \$method && '' === \$presented" tests/performance/wp-content/mu-plugins/server-timing.php

# An unusable class map is named in the log rather than left to surface as a missing class,
# and the diagnostic is not gated on WP_DEBUG. This must print 1 for each.
grep -c "could not load %1\\$s" src/wp-includes/autoload.php
grep -c 'error_log('              src/wp-includes/autoload.php

# The map_meta_cap() memo declines to answer whenever anything is registered on the
# map_meta_cap filter. This must print 1: the key builder returns '' in that case, and an
# empty key is the only state in which map_meta_cap() neither reads nor writes the memo.
grep -c "if ( has_filter( 'map_meta_cap' ) ) {" src/wp-includes/capabilities.php

# And the memo is invalidated by state rather than by time: eleven core actions plus the
# doing_it_wrong probe. This must print 12.
grep -c "_wp_flush_map_meta_cap_memo'" src/wp-includes/capabilities.php

# The gates behave as documented, without a browser. Each line prints its own OK count;
# the expected counts are in §"Files changed". PHPUnit runs inside the container.
npm run test:php -- -c phpunit.xml.dist --no-coverage --group emoji
npm run test:php -- -c phpunit.xml.dist --no-coverage tests/phpunit/tests/dependencies/commandPalette.php
npm run test:php -- -c phpunit.xml.dist --no-coverage tests/phpunit/tests/formatting/emojiGate.php
npm run test:php -- -c phpunit.xml.dist --no-coverage tests/phpunit/tests/user/mapMetaCapStateFidelity.php
npm run test:php -- -c phpunit.xml.dist --no-coverage tests/phpunit/tests/cache/objectCacheGroupStats.php
npm run test:php -- -c phpunit.xml.dist --no-coverage tests/phpunit/tests/load/wpAutoloadClass.php
npm run test:php -- -c phpunit.xml.dist --no-coverage tests/phpunit/tests/load/adminOnlyBootstrap.php
npm run test:php -- -c phpunit.xml.dist --no-coverage tests/phpunit/tests/load/lazyBootstrapWiring.php
npm run test:php -- -c phpunit.xml.dist --no-coverage tests/phpunit/tests/option/wpLoadAlloptions.php

# The deferred names resolve on demand rather than eagerly. Every eager state is snapshotted
# BEFORE any resolution is attempted, because one class_exists( $n, true ) changes the eager
# set for every later probe - which is how an earlier version of this probe wrongly reported
# POMO_FileReader as eager. Expect "not-eager resolves" for all but Translations and
# NOOP_Translations, which are eager by design.
docker compose exec -T php php -r 'require "/var/www/build/wp-load.php"; $n = array( "Walker_CategoryDropdown", "WP_Comment", "WP_Comment_Query", "MO", "POMO_FileReader", "POMO_Reader", "Translations", "NOOP_Translations", "WP_REST_Posts_Controller", "WP_Site_Health" ); $e = array(); foreach ( $n as $c ) { $e[ $c ] = class_exists( $c, false ); } foreach ( $n as $c ) { printf( "%-26s %-10s %s\n", $c, $e[ $c ] ? "EAGER" : "not-eager", class_exists( $c, true ) ? "resolves" : "MISSING" ); }'
```

### Where every class of claim in this document comes from

A number in a report is only as good as the reader's ability to find out how it was produced. This table maps
each class of claim to the instrument that produced it, what that instrument leaves behind, and what a reader
has to do to reproduce it. It is deliberately organised by *class* rather than by figure, because the failure
mode being guarded against is a figure whose provenance nobody can name — not a figure that is slightly stale.

| Class of claim                                                            | Instrument                                                                                                       | Artifact it leaves                                                                                                    | Reproducible by a reader?                                                                                        |
| ------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| The six target verdicts, and every figure in §_Results_ and §_Not a target, but measured in the same pair_ | `npm run test:performance` twice, arms swapped between runs, then `compare-results.js`                            | `artifacts/{before-,base-,}performance-results.json` and `artifacts/performance-results.md` — **gitignored**, see §_Evidence manifest_ | Yes, by re-running the two commands in §_Evidence manifest_. The artifacts themselves do not survive into the repository |
| The cold/warm split in §_An independent pair whose baseline is the plan's own number_ | `curl` against the served docroot with the twelve-file swap applied in `build/` only, cold samples preceded by an authorized reset | Nothing durable — the figures are restated in full in that section, which is the only place they exist                  | Yes, given a running harness; the method is stated in full in that section                                        |
| Byte counts of documents and assets                                        | `curl -s -o file` then `wc -c`, and `gzip -9 -c` for the gzipped figures                                          | Nothing durable                                                                                                        | Yes, directly                                                                                                    |
| File counts, query counts, peak memory, cache hits and misses               | the `Server-Timing` header emitted by `tests/performance/wp-content/mu-plugins/server-timing.php`                 | Nothing durable, but every value is in a response header a reader can request                                          | Yes, with the mu-plugin in the served docroot                                                                     |
| Digests and entry counts of the two generated files                        | `sha256sum`, `wc -c`, and the generator's own `AUTOLOAD_CLASSMAP_DIGEST` line                                     | The files themselves, **committed**                                                                                    | Yes — this is the one class of claim whose evidence is in the repository                                           |
| Include-construct counts in `wp-settings.php`                              | PHP's `token_get_all()`, both arms                                                                                | The files themselves, committed; base via `git show 5e9d05d7dd:src/wp-settings.php`                                    | Yes, with the command in §_Checks a reader can re-run_                                                             |
| Which classes are eager and which resolve on demand                        | a boot probe that snapshots every eager state before resolving anything                                           | Nothing durable                                                                                                        | Yes, with the command in §_Checks a reader can re-run_                                                             |
| Test counts and assertion counts                                           | PHPUnit and Playwright, per file                                                                                  | `tests/phpunit/build/logs/junit.xml`, gitignored                                                                       | Yes, one command per file in §_Checks a reader can re-run_                                                         |
| `window.wp` key sets, console output, network status codes, DOM presence     | a real headless Chrome driven against the running install                                                          | Screenshots and recordings written outside the repository                                                              | Yes, by loading the same four screens authenticated                                                               |
| Admin-bar geometry and the 984-pixel difference                             | `getBoundingClientRect()` per admin-bar child, and a pixel difference of two viewport-exact captures                | The visual-regression baselines are **not committed** — see §_Verification gaps_ item 2                                 | Partly: the geometry yes, the pixel count only against locally generated baselines                                |
| Grep-shaped structural claims (a guard exists, a hook count, a marker region) | `grep -c` with the expected count stated inline                                                                   | The files themselves, committed                                                                                        | Yes, directly                                                                                                    |

Two honest limits on the table above. **The performance artifacts are gitignored**, so a reader at a later
commit cannot open the file a figure came from — which is why every figure that depends on one is restated in
full in a table here rather than cited by digest, and why no digest is given for them. And **the visual
baselines are not committed either**, so the pixel-difference claim is reproducible in method but not against
the exact images this document was written from. Both limits are properties of the repository's `.gitignore`
rather than choices made here, and both are recorded in §_Verification gaps_ with what it would take to fix
them.


---

## Scope reconciliation: the change set against the governing plan's file list

Plan §0.6.1 enumerates 16 create-or-update paths and a trailing-pattern section that reaches the rest of
`tests/performance/**`. The delivered tree changes **40** paths, and they reconcile exactly:

| Category                                                               | Paths |
| ---------------------------------------------------------------------- | ----: |
| Enumerated in §0.6.1 — **all 16 delivered**                            |    16 |
| Under `tests/performance/**`, covered by the trailing pattern           |     6 |
| Runtime source **outside** the list                                     |     4 |
| Tests and Playwright configurations **outside** the list                |    13 |
| Build tooling **outside** the list                                      |     1 |
| **Total**                                                               | **40** |

Every path in the three "outside" rows is named below with its grounds. None is left to be discovered by a
reader diffing the tree against §0.6.1.

**Every enumerated path is delivered, including the one a previous revision withheld.**
`src/wp-includes/capabilities.php` is listed by the plan as an UPDATE, and an earlier revision of this document
shipped it byte-identical to base, on the grounds that two memoization shapes had been withdrawn on
measurement. That reasoning inverted the precedence: Gate 4 forbids shipping an optimization with no measured
bottleneck behind it, but the plan's §0.5.1.4 *names* this optimization and §0.6.1 lists the file as an UPDATE,
so "two shapes did not pay off" is an argument for finding a third shape, not for declining the requirement.
The delivered memo is that third shape, and
§_Memoizing `map_meta_cap()` — two shapes withdrawn, a third delivered_ records all three with what each
measured.

Ten test paths are delivered beyond the one the plan enumerates
(`tests/phpunit/tests/load/wpAutoloadClass.php`), and two Playwright configurations outside
`tests/performance/**` are modified. The test files exist because the behaviours this change set adds — the
Command Palette gate, the emoji gate, the per-group cache register, the two conditional admin-only requires in
`wp-settings.php`, the four remaining bootstrap deferrals, the primed option reads, and the state fidelity the
`map_meta_cap()` memo has to preserve — otherwise shipped with no committed coverage at all, which no amount of
measurement substitutes for; and because the Command Palette needed a guard that a browser can fail, which is
`tests/e2e/specs/command-palette.test.js` and the two locator-scoped visual cases. The two configuration changes
(`tests/e2e/playwright.config.js`, `tests/visual-regression/playwright.config.js`) each add one `.env` load,
for the reason in backlog item 17. All of them add coverage and change nothing else.

### The four runtime source paths outside the list

| Path                                         | Why it exists                                                                                                                                                                                                                                                                                                                                                                                                          |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `src/wp-includes/option.php`                 | Row 5 has no mechanism anywhere in §0.6.1's list, and plan §0.1.4 names "option-loading efficiency" as the route by which the query target is to be pursued. The three queries row 5 removes are single-row reads for options that do not exist, and the only place that can prime them is the function that already runs the autoload query. Rule D1 requires fixing at the true root cause even when it lies outside the file where the finding was observed |
| `src/wp-includes/ai-client.php`              | The AI client and its adapters were required at bootstrap on every request. Deferring them is part of the measured file-count reduction §0.5.1.2 requires; §0.6.1 lists the bootstrap file but not the cluster's own loader                                                                                                                                                                                              |
| `src/wp-includes/connectors.php`             | Holds generated admin page loaders that were required at bootstrap on every request, including anonymous front-end ones. Same reason as the row above                                                                                                                                                                                                                                                                   |
| `src/wp-includes/class-wp-recovery-mode.php` | Its dependency chain loaded on every request although recovery mode is entered on almost none. Same reason again                                                                                                                                                                                                                                                                                                        |

### The thirteen test and configuration paths outside the list

Six are new PHPUnit files covering behaviour this change set adds — `load/adminOnlyBootstrap.php`,
`load/lazyBootstrapWiring.php`, `dependencies/commandPalette.php`, `formatting/emojiGate.php`,
`cache/objectCacheGroupStats.php` and `user/mapMetaCapStateFidelity.php` — and one is an existing PHPUnit file
extended for the primed option reads, `option/wpLoadAlloptions.php`. Shipping any of those behaviours without
committed coverage is what the alternative would have been.

Three are browser guards: `tests/e2e/specs/command-palette.test.js` (new) and the two locator-scoped cases added to
`tests/visual-regression/specs/visual-snapshots.test.js`, because the palette default is a change a browser has
to be able to fail, and `tests/build/build-guards.test.js` (new), because `build:autoload-classmap` and
`verify:emoji-markers` exist to refuse a bad result and a successful build exercises none of those refusals.

Two are one-line `.env` loads in `tests/e2e/playwright.config.js` and
`tests/visual-regression/playwright.config.js`, for the reason in backlog item 17.

The last is a repair rather than an addition, and it is the one worth stating at length:

| Path                              | Why it changed                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| --------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `tests/e2e/specs/install.test.js` | Gate 1 requires zero test regressions and forbids reaching that by skipping. This spec failed deterministically on any second run, for **three independent pre-existing causes** this change set did not introduce: it leaves its `wp_e2e_*` tables behind, so the site it re-points `wp-config.php` at is already installed; it reads the install state from an already-loaded page rather than re-requesting, which cannot absorb the opcode cache's two-second revalidation window; and it hard-codes the language-chooser installer shape, although `src/wp-admin/install.php:357-367` renders that step only when `wp_can_install_language_pack()` is true **and** `wp_get_available_translations()` returns something, and `:370` carries core's own comment that it deliberately falls through when it cannot. The repair drops its own tables in both `beforeEach` and `afterEach`, re-navigates inside a polling assertion until the site actually reports the needed state, and treats the language step as present-or-absent. Every substantive assertion is kept and no skip is added |

### The one build tooling path outside the list

| Path                                         | Why it exists                                                                                                                                                                                                                                                                                                                                                                                                          |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `tools/build/generate-autoload-classmap.php` | The plan requires a build-generated class map but names no generator. Deciding whether a file is safe to autoload means knowing what it declares and whether it has file-scope side effects, which is PHP tokenizer work; writing it in JavaScript would mean reimplementing that and would move the file outside `php -l` and PHPCS, both of which cover it today. `tools/` PHP tooling is existing repository convention |

**One further change is inside the list but is declared anyway**, because it repaired a guard this change set
itself had broken. `tests/performance/specs/utils.test.js` located the front-end metric producer by the first
literal `'admin_init'` in the mu-plugin source; a docblock added by the harness-hardening work names that hook
in prose earlier in the file, so the guard silently found **zero** front-end metrics and eight of its cases
failed — which in turn made the reporter refuse to publish the run, exactly as designed. The anchor is now the
structural boundary `function wp_perf_collect_server_timing()`, and its absence throws a named error instead of
returning an empty set.

All eighteen paths in the four groups above need the plan's file list amended, or the paths removed. This
document requests the amendment rather than assuming it.

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
`php -l`, PHPCS and PHPStan rather than by a test — and, for the class map's three unusable states, by the
fault injection recorded in §_Runtime checks_. The 151 cases in `wpAutoloadClass.php` cover every mapped
entry, every casing and the map-versus-tree consistency; they do not cover those two branches.

`_wp_emoji_list()` has the same shape and the same gap: it memoizes the loaded data in a function static, so
its `is_readable()` refusal is not reachable from a second call in the same process either. It was instead
exercised at runtime, outside PHPUnit — the data file set to mode `0600` and the function called as the web
server user, which returns two empty arrays with no warning and no aborted request where the unguarded
`require` raised one. That is a run recorded in this document, not a test that will catch a regression, so a
future change to that guard has no automated check standing behind it.

### 2. The visual-regression suite cannot fail in CI, and its baselines are not in the repository

The plan names `tests/visual-regression/specs/visual-snapshots.test.js` as the mechanism for the "no admin UI
visual change" boundary. **As the repository ships, that guard cannot fail**: `git ls-files
tests/visual-regression/` returns three paths and none of them is a baseline, `__snapshots__` is gitignored at
`.gitignore:119`, and `test:visual` (`package.json:140`) is referenced by **zero** files under
`.github/workflows/`. So the boundary this change set comes closest to touching has no automated enforcement
in CI.

Generate the baselines locally and the suite is a working detector, so it was run rather than assumed — and
run across the two arms, because a suite compared against baselines it generated itself proves only that the
tree is deterministic. The procedure was: swap the twelve runtime files of the served tree to base
`5e9d05d7dd`, pin `twentytwentyfive`, and let the first run write all 24 baselines; run again on base to
confirm they hold; swap to the delivered tree and compare against the same 24.

**On base against base**: 23 passed, 1 failed, 28.4 s. The single failure is the new case's Dashboard count
assertion, which base fails because base does render the control there. That is the case discriminating the
two arms, and it is the only thing in the suite that does so by design rather than by pixels.

**On the delivered tree against base's baselines**: **20 failed, 4 passed, 50.5 s.** The four passes are
`Widgets` and `Menus`, which `wp_die()` under a block theme on both arms and render identical 2,796- and
2,576-byte documents; the Command Palette control case, whose control is pixel-identical in the editor on both
arms and absent from the Dashboard on the delivered one; and `Post Editor header`, where the block editor's
own chrome is pixel-identical between arms — the same conclusion the byte-identical document comparison reaches,
arrived at through pixels instead of bytes.

The 20 failures are one difference, 20 times. Each differences **985 to 993 pixels**, 0.107 % of the
1280 × 720 viewport, and **every one of those pixels sits at `y < 32`** — inside `#wpadminbar`, measured at
1280 × 32. Pooled across all 20, the dominant transition is `(243, 241, 241) → (30, 30, 30)`, 1,840 pixels of
it: toolbar glyph replaced by the toolbar's own background, which is the Ctrl+K item not rendering.

Below the toolbar, across all 20 screens together, **39 pixels differ at all**; the largest per-channel delta
among them is **2**, and the number differing by more than 2 is **zero**. They land on anti-aliased link and
rule edges — `(183, 192, 238)` against `(183, 194, 237)`, `(241, 241, 241)` against `(242, 242, 242)` — which
is rounding, an order of magnitude under the 0.2 threshold Playwright's own comparator applies, and incapable
of failing a case on its own. **That is the boundary result: on 20 admin screens, nothing below the toolbar
moved.** The plan's appearance boundary holds everywhere except the one place §0.5.1.6 says it may move.

**No baseline was updated to obtain a pass.** The base-generated set was left exactly as captured for the
cross-arm run; the delivered-arm 24-pass run used a separate set, generated after deleting the first, so the
two claims never share a baseline. `__snapshots__` is gitignored, so neither set is in the commit.

Two properties of the suite are worth separating from that result, because both predate this change set and
neither is caused by it:

- **Its admin-bar mask does not mask anything.** `mask: [ '#wp-admin-bar-root-default' ]` resolves to a
  `<ul>` measured at **1440 × 0, 1280 × 0 and 960 × 0** — `display: block`, `clientHeight` 0, `scrollHeight`
  32 — because every `li` child computes `float: left` and the `ul` has no clearfix. A zero-area mask covers
  no pixels, so the toolbar is compared in full. That is why the suite detects this change at all, and it
  equally means it compares the volatile admin-bar gravatar. A third mask entry,
  `#toplevel_page_gutenberg`, is absent from the DOM here.
- **It cannot run standalone.** `tests/visual-regression/playwright.config.js` sets `globalSetup: undefined`,
  so `artifacts/storage-states/admin.json` is never created, and `use.storageState` then points at a file that
  does not exist. Confirmed by removing it and running one case: `Error reading storage state from
  …/artifacts/storage-states/admin.json`, 1 failed, no login attempted. The runs above are authenticated only
  because the E2E suite — whose config keeps its `globalSetup` — had already written that file. Its effective
  viewport is **1280 × 720**, not the 960 × 700
  in `@wordpress/scripts/config/playwright.config.js`, because the `chromium` project spreads
  `devices[ 'Desktop Chrome' ]` at higher precedence — measured, by resolving the project configuration and
  reading `page.viewportSize()` back, rather than inferred.

Neither was repaired here. Masking `#wpadminbar` would silence those 20 failures, and Gate 1 forbids a new
exclusion introduced to make a suite pass; all three paths are outside the plan's file list, and
`visual-snapshots.test.js` is REFERENCE in §0.6.1. On acceptance of decision 3 in §_What a human has to
decide_ — that is, only if a site opts out — the 20 admin baselines have to be regenerated in the
authoritative CI font and browser environment; this environment's numbers establish the delta, not the pixels
a committed baseline should hold. Committing baselines, fixing the mask and the setup, and adding a comparing
job is backlog item 11. Meanwhile the Command Palette consequence is established by the geometry and pixel
measurements in §_What declining the Command Palette costs in pixels_, by the runtime census in
§_Verification_, and by `commandPalette.php`.

### 3. The comparator has no end-to-end output test

`compare-results.js` refuses malformed input in eleven ways and `specs/utils.test.js` asserts the metric
vocabulary, the reset ladder and the reporter contract, but nothing asserts the rendered Markdown of a
complete comparison. The three artifacts in §_Evidence manifest_ are that assertion by hand, once.

### 4. Row 5's mechanism is tested, but its result is fixture-sensitive and the fixture is not CI's

This gap replaces one a previous revision recorded — "row 5 has no mechanism, so there is nothing to test" —
which is no longer true: the priming in `src/wp-includes/option.php` is a real mechanism, it is covered by
**15 tests / 26 assertions** in `tests/phpunit/tests/option/wpLoadAlloptions.php`, and it moves the canonical
homepage from 18 queries to 15.

What is *not* proved is the size of that reduction on a realistic site. The three queries it removes are
single-row reads for options that do not exist, so the saving is a fixed −3 rather than a proportion; the
percentage that −3 represents therefore depends entirely on how many queries the page issues in total, and this
fixture's homepage renders an **empty** blog loop. On CI's fixture the same −3 would be a smaller percentage,
and on a site with a large front page smaller still. The mechanism is proved; the **−16.67 %** is proved for
this scenario and is not claimed for any other.

### 5. One existing test now carries a comment that no longer describes it

`tests/phpunit/tests/template.php:1964` touches `wp-includes/js/wp-emoji-loader.js` before asserting, with
the comment "`_print_emoji_detection_script()` assumes `wp-includes/js/wp-emoji-loader.js` is present." With
the front-end default now `false`, that setup step is moot for the default path — the worker is not reached.
The test still passes, and it is left alone deliberately: it is not this change set's file, editing it would
be the unrelated cleanup Gate 5 prohibits, and the `touch()` is still correct for any caller that filters the
gate back on. It is recorded here because the comment is now quiet evidence of a default that changed, and a
maintainer reading it in isolation would draw the wrong conclusion about what the emoji worker assumes.

### 6. Three conditions surfaced while verifying, which this change set does not cause

Each of these was found while comparing the two trees, each was traced to ground, and each belongs to the
tree or the harness rather than to this change set. They are recorded so that a later reader who trips over
one does not spend the afternoon attributing it here.

**An unbreakable neighbour title overflows `.wp-block-post-navigation-link` horizontally, identically on both
trees.** The site's fixture post 6 has a title that is one 163-character token; post 5 renders it as its
"next post" link. At a 1440 × 900 viewport that page measures `documentElement.scrollWidth` **1549** against
`clientWidth` **1440** — 109 px of horizontal overflow — sourced from exactly one element,
`div.post-navigation-link-next.wp-block-post-navigation-link`, whose `right` is `1548.563` and width
`1430.203`; every other element on the page stops at `1440.000`. The control post 28, whose neighbours have
ordinary titles, measures `1440 / 1440`. The mechanism is measured rather than assumed: the post-navigation
`nav` is `display: flex` with `flex-wrap: nowrap` on a 1340 px line, the link is a flex item with
`min-width: auto`, and it computes `word-break: normal`, `overflow-wrap: normal`, `max-width: none` — so its
floor is the min-content width of a token with no break opportunity, and `flex-shrink: 1` cannot go below it.
`.wp-block-post-title` computes `word-break: break-word` with `max-width: 645px` on the same pages and wraps
correctly. **The two trees produce bit-identical documents here**: the full-page captures of both pages are
byte-identical across trees (`md5 e00caa19aa7c9898b39ad89c07a9fdce` for the overflowing page at 1549 × 2990,
`70d87826683a4ed93e874016aa77a77f` for the control at 1440 × 2870), and the three governing stylesheets are
too — `blocks/post-navigation-link/style.css` `483232f4cd7d`, `blocks/post-title/style.css` `3fa23d69addd`,
`css/dist/block-library/style.css` `c9f1f9b2a425`. The condition is latent and content-triggered, not
tree-dependent: the same computed properties hold on the control page, which simply has no unbreakable
neighbour to expose them, and the overflow scales with token length and disappears at a wide enough viewport
(the same page shows none at 1905 px). The stylesheets involved are Gutenberg-synced, which plan §0.3.2.3
places out of scope, so this is an upstream report to make rather than a fix to land here.

**`edit-comments.php` is not byte-stable, so it must never be byte-compared.** Six consecutive authenticated
fetches of that screen on one tree returned **six distinct sizes** — 113,240 / 113,300 / 113,310 / 113,330 /
113,350 / 113,360 bytes — and six distinct digests, while `options-general.php` fetched the same way returned
192,316 bytes every time. The cause is not a nonce: the nine `_wpnonce` values were identical across the
fetches, and the differing bytes are the eight comment-author `mailto:` addresses, whose per-character
encoding is randomised by `antispambot()` — `default-filters.php:313` filters `comment_email` through it, and
`formatting.php:2912` picks `rand( 0, 1 + $hex_encoding )` for every character. `antispambot()` is untouched
by this change set: every hunk in `formatting.php` sits at line 5897 or later. Pixel comparison of that screen
is fine; byte comparison is meaningless.

**The Memcached leg of the performance matrix was never exercised.** No `object-cache.php` drop-in exists in
`build/`, in `src/wp-content/` or in the base tree used for the paired measurements, so every figure in this
document was taken with `wp_using_ext_object_cache()` false. That is the graceful-degradation condition plan
§0.8.2.1 requires rather than an edge case, and `.github/workflows/reusable-performance-test-v2.yml:188-190`
provisions the drop-in only on the memcached matrix leg — which means the per-group cache register in
§_Per-group object cache hit and miss counters_ has been verified against `WP_Object_Cache` and **not**
against a drop-in that replaces the class wholesale.

---

## Prioritized opportunities discovered but not implemented

Ordered by the size of the pool each one addresses, which is not the same as the order they should be done
in. Items 1, 3 and 4 need a scope decision before they can be attempted at all, because each one's mechanism
lives in a file plan §0.3.2 excludes. Item 2 is the exception in this group: it is inside scope, it was
implemented and measured, and it was **declined on its merits** — the record of that decision is in the item.
Item 17 is the one a maintainer should read first, because it is a safety hazard rather than a performance
opportunity.

1. **Have `tools/gutenberg/copy.js` emit a class map or render callbacks instead of a require chain.** 89
   files, the largest remaining pool, and **the only lever that can close rows 6 and 4** — the arithmetic in
   §_Why two targets are not met_ shows the in-scope maximum is 349 files, or −27.89 %, against a requirement
   of ≤ 338.8. Those same files also carry the +1.43 MB of block-type registration that sets the memory peak.
   Out of scope under plan §0.3.2.3, which names this cluster "untouchable" and lists even the core-owned
   loader as a REFERENCE.
2. **Defer `WpOrg\Requests\Requests::set_certificate_path()` out of file scope** in
   `src/wp-includes/class-wp-http.php:19`, into `WP_Http::request()` behind a static guard. **Measured, not
   estimated: −2 files and −15,760 B of peak memory.** Declined for two reasons, and the second is why it is
   here rather than delivered: it changes what `WpOrg\Requests\Requests::get_certificate_path()` returns for
   code that calls the bundled library directly — TLS still verifies, because Requests defaults to its own
   bundled `cacert.pem` at `src/wp-includes/Requests/src/Requests.php:177`, but the value is observably different — and it leaves 9,448 B of
   row 4's 25,208 B shortfall outstanding, so it cannot change a verdict. A successor who has already taken
   item 1 should revisit it, because at that point it would be closing a gap rather than narrowing one.
3. **Reduce the remaining front-end queries where they actually are.** Row 5 is met at −16.67 %, and the three
   queries it removed were single-row reads for options that do not exist. The 15 that remain were each traced
   to a caller and found irreducible without crossing a scope boundary: two `wp_template` lookups driven by the
   separate `front_page_template` and `home_template` filters, a `wp_navigation` fallback and a `get_pages()`
   call both issued from the Gutenberg-synced navigation block, a `wp_global_styles` lookup from
   `WP_Theme_JSON_Resolver`, and the pattern-cache transient pair keyed on `WP_Theme`'s **private**
   `$cache_hash`. Further reduction means block rendering and template resolution — concretely, batching the
   two `wp_template` lookups inside `src/wp-includes/block-template-utils.php`, which is not in the plan's file
   list — and plan §0.3.2.3 excludes the block layer outright.
4. **Reduce the admin query count, which this change set barely moved.** The Dashboard went 42.5 → 40.5
   (−4.71 %), and that came entirely as a side effect of the option priming in row 5; no target covers the
   admin query count, so nothing here was aimed at it. Two shapes were identified while tracing the front-end
   census and would apply on the Dashboard instead: grouping the per-status comment counts, and batching the
   update-check transient reads. Neither was measured in this revision's harness, so no figure is claimed for
   them; both live under `src/wp-admin/includes/`, outside the plan's file list.
5. **Measure the autoloader's per-resolution cost in a warm regime deliberately.** This revision withdrew the
   previous one's ≈ 5 ms warm cost as unreproducible, and its own warm pair reads −0.65 % — inside the noise.
   So the warm cost is currently *unknown*, not zero, and the honest next step is a harness arm that measures
   it rather than an optimization aimed at it. If it turns out to be real, a sorted or partitioned map, or
   resolving without re-reading the whole array, are the obvious candidates.
6. **The per-image-size recomputation in the attachments REST controller.**
   `src/wp-includes/rest-api/endpoints/class-wp-rest-attachments-controller.php:1088-1104` calls
   `wp_get_attachment_image_src()` once per registered size plus once for `full`, and the method's own
   comment concedes it duplicates work. CPU-bound rather than query-bound, and confined to `/wp/v2/media`,
   so it moves none of the six targets.
7. **A `wp_lazyload_post_meta()` analogue.** `WP_Metadata_Lazyloader` registers `term`, `comment` and `blog`;
   `wp_lazyload_term_meta()`, `wp_lazyload_comment_meta()` and `wp_lazyload_site_meta()` all ship and there
   is no post-meta equivalent.
8. **`date.min.js` and `moment.min.js` in the admin payload.** With the Command Palette bundles now declined on
   non-editor screens, these are the two largest single files still delivered to a screen that does not use
   them: **144,573 B raw / 23,160 B gzipped** and **58,852 B raw / 18,700 B gzipped**. Both were confirmed
   present in the delivered Dashboard document (one `<script src>` each, in the same 122,536-byte response that
   contains zero references to `commands.min.js` or `core-commands.min.js`), and together they are 41,860 of the
   180,215 gzipped bytes row 3 now reports — 23.2 % of what remains. They arrive as registered *dependencies*
   rather than as conditional enqueues, so reaching them means changing a dependency graph, which plan §0.3.2.2
   freezes. Row 3 is already met, so this is headroom rather than a gap.
9. **The non-class require clusters a class map cannot reach.** Of the 209 remaining constructs — **203
   literal and 6 dynamic**, the dynamic ones resolving a path from a variable and therefore unreachable by a
   class map on principle rather than by policy — all but a handful target files that declare functions, have
   file-scope side effects, or declare a name the generator rejects. Reaching them means a different mechanism — closure-wrapped requires at the call site, in the
   style `blocks.php:568-572` already ships. Note the measured ceiling on this route first:
   §_Maximal bootstrap deferral_ shows that deferring every remaining single-class require moves only two
   files and costs memory.
10. **A first-class reader for the per-group cache register.** Site Health, or a `wp cache stats`-style
    readout, so the register has a surface in core rather than only `stats()`.
11. **Commit visual-regression baselines, drop the `__snapshots__` ignore, and add a comparing CI job**, so
    that the guard the plan designates for admin appearance can fail in CI as well as locally. All three paths
    are outside the plan's file list. Three repairs belong with it, all independent of this change set and each
    measured in §_Verification gaps_ item 2: regenerate the 20 admin baselines in the authoritative CI font and
    browser environment if decision 3 is ever taken; replace the `#wp-admin-bar-root-default` mask, which
    resolves to a zero-height rect and therefore masks nothing while leaving the volatile admin-bar gravatar in
    the comparison; and give `tests/visual-regression/playwright.config.js` a login-only `globalSetup`, since
    `globalSetup: undefined` leaves the storage state uncreated and the suite unrunnable on its own.
12. **Concatenation-aware JavaScript byte accounting on the front end.** The admin collector now matches on
    content type as well as extension; the front-end specs do not collect JavaScript bytes at all, so a
    front-end payload target would need that added.
13. **Provision or pin `php` in the three workflows that run the core build without it**, now that
    `build:autoload-classmap` needs it: `reusable-build-package.yml`,
    `reusable-test-gutenberg-build-process.yml`, `test-and-zip-default-themes.yml`.
14. **Bring `tools/` inside the static gates.** Extend PHPStan's `paths:` in `tests/phpstan/base.neon` so the
    class map generator is analysed by `npm run typecheck:php` rather than only by a separate invocation, and
    decide whether `phpcs.xml.dist:91`'s `/tools/*` exclusion should stay now that a build step lives there.
    Both files are outside the plan's file list, and both exclusions predate this change set.
15. **Reproduce these measurements on CI's fixture.** Every figure here was taken on a 10-post fixture,
    because this environment cannot fetch `themeunittestdata.wordpress.xml` or the `de_DE` packs. The
    percentages are internally valid, but the query count in particular is fixture-sensitive, and row 4 misses
    by **0.31 percentage points — 25,208 bytes** — which is a margin small enough that a different fixture
    could move that verdict either way in either direction. Row 6's 2.52-point miss is not fixture-sensitive in
    the same way, because the arithmetic in §_Why two targets are not met_ bounds it structurally rather than
    statistically. Running the same back-to-back pair on CI's fixture would settle row 4.
16. **A second reset mode for the harness** that flushes the object cache without resetting the opcode
    cache, so the two regimes can be measured deliberately rather than inferred from scenario order.
17. **Make the shared Playwright base URL fail closed instead of defaulting to a fixed port.**
    `@wordpress/scripts/config/playwright.config.js:13` resolves
    `new URL( process.env.WP_BASE_URL || 'http://localhost:8889' )`, so any suite run without `WP_BASE_URL`
    set targets whatever is listening on 8889 — and `tests/e2e/README.md` warns in bold that the E2E suite
    deletes all content. On a host running several checkouts side by side that default points at a different
    installation than the one under test. **This is not hypothetical: it happened during the work on this
    revision.** An E2E run launched before the fix below was in place executed against a different checkout on
    port 8889, whose global setup activated another theme and deleted its posts. The fix applied here is that
    all three Playwright configurations now load `.env` into `process.env` before the shared configuration is
    required, so `WP_BASE_URL` is always set from the checkout's own environment file; the remaining hardening
    belongs in the dependency, which no change here may touch, or in a wrapper that refuses to run without an
    explicit base URL. Not a performance item; a safety one, and the highest-value item in this list for
    anyone running more than one checkout.
18. **Apply this suite's storage-state relocation to the E2E suite.** Not a performance item; a hardening
    one. `tests/e2e/playwright.config.js:13` defaults its authenticated admin session into
    `artifacts/storage-states/admin.json`, and `reusable-end-to-end-tests.yml` uploads `path: artifacts`
    wholesale with `include-hidden-files: true` and `if: always()`.
    `tests/performance/playwright.config.js:29-43` shows the fix. Both files are outside the plan's list, and
    the hazard predates this change set.

---

## Files changed

**40 paths**, no deletions, no dependency changes. Twelve of them are runtime source, and those twelve are
exactly the swap set the measurement pairs move between arms — the correspondence is not a coincidence, it is
what makes the pairs describe this change set and nothing else.

### Source, 12 paths

| Path                                          | Change                                                                                                                                                                             |
| --------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/wp-settings.php`                         | autoloader registered before the require region; 114 include constructs deferred, from 323 to **209**; the two admin-only files gated; the compiled translation reader deferred      |
| `src/wp-includes/autoload.php`                | new: the `spl_autoload_register()` handler behind a six-prefix prefilter, and one named diagnostic for an unusable class map                                                         |
| `src/wp-includes/autoload-classmap.php`       | new, generated: **147 entries** — 146 classes and 1 interface, 13,662 B                                                                                                             |
| `src/wp-includes/emoji-arrays.php`            | new, generated: the relocated emoji data, verbatim — 143,073 B, 4,008 entities and 1,438 partials                                                                                   |
| `src/wp-includes/formatting.php`              | the emoji detection gate with its context, and the disclosure that a context-blind filter answers for all three; `_wp_emoji_list()` loads the relocated data behind `is_readable()`  |
| `src/wp-includes/script-loader.php`           | `wp_should_load_command_palette_assets()` and its use; **declines outside the block editor by default**; refuses to widen outside the admin before applying its filter; additive only |
| `src/wp-includes/capabilities.php`            | filter-safe request-scoped memoization inside `map_meta_cap()`, bypassed whenever a non-core `map_meta_cap` callback is registered                                                   |
| `src/wp-includes/option.php`                  | single-row option reads primed from the autoload query rather than issued individually — **outside the plan's file list**, see §_Scope reconciliation_                               |
| `src/wp-includes/class-wp-object-cache.php`   | the opt-in per-group hit and miss register, reported through `stats()`                                                                                                              |
| `src/wp-includes/ai-client.php`               | the AI client and its adapters loaded on first use rather than at bootstrap                                                                                                        |
| `src/wp-includes/connectors.php`              | the generated admin page loaders deferred to the admin request that needs them — **outside the plan's file list**                                                                   |
| `src/wp-includes/class-wp-recovery-mode.php`  | the recovery-mode dependency chain loaded when recovery mode is actually entered — **outside the plan's file list**                                                                 |

### Build and tooling, 2 paths

| Path                                         | Change                                                                                                                                                                                  |
| -------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Gruntfile.js`                               | `build:autoload-classmap` with seven acceptance checks; `replace:emoji-regex` retargeted; `verify:emoji-markers`; guards moved out of `verify:build`; missing `php` reported explicitly |
| `tools/build/generate-autoload-classmap.php` | new: the class map generator — outside the plan's file list                                                                                                                             |

### Harness and tests, 25 paths

| Path                                                        | Change                                                                                                  |
| ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `tests/performance/wp-content/mu-plugins/server-timing.php` | five new metrics; the authorized cache-reset control plane, which answers only requests that address it  |
| `tests/performance/utils.js`                                | formatting for the new metrics; deterministic gzip byte accounting; `booleanMetrics` exported; the active-theme reader |
| `tests/performance/compare-results.js`                      | contract enforcement over the pair, including the boolean-metric precondition                            |
| `tests/performance/specs/admin.test.js`                     | DOMContentLoaded; JavaScript bytes by extension and content type                                         |
| `tests/performance/specs/home.test.js`                      | the new front-end metrics                                                                                |
| `tests/performance/specs/single-post.test.js`               | the new front-end metrics                                                                                |
| `tests/performance/specs/utils.test.js`                     | new: the harness's own contract, including the comparator's refusal and the theme round-trip             |
| `tests/performance/config/performance-reporter.js`          | refuses to publish results from a failed or empty run                                                    |
| `tests/performance/config/global-setup.js`                  | records the active theme so the teardown can restore it                                                  |
| `tests/performance/config/global-teardown.js`               | new: removes the session and the run token, and restores the theme the run found active                  |
| `tests/performance/playwright.config.js`                    | keeps the authenticated session out of the published artifacts; loads `.env` before the shared config    |
| `tests/e2e/playwright.config.js`                            | loads `.env` before the shared config, so `WP_BASE_URL` cannot silently fall back to another checkout    |
| `tests/visual-regression/playwright.config.js`              | the same `.env` load                                                                                     |
| `tests/visual-regression/specs/visual-snapshots.test.js`    | two cases added: the admin-bar Command Palette control, captured where the default delivers it and counted where it declines it, and the post editor header |
| `tests/e2e/specs/command-palette.test.js`                   | new: 2 cases — the palette is delivered and its globals defined on a block-editor screen, and declined on a non-editor one |
| `tests/e2e/specs/install.test.js`                           | made self-cleaning and installer-shape-agnostic, so the suite is repeatable from a dirty database — **outside the plan's file list**, see §_Scope reconciliation_ |
| `tests/build/build-guards.test.js`                          | new: **15 cases** over the two generation tasks — outside the plan's file list                            |
| `tests/phpunit/tests/load/wpAutoloadClass.php`              | new: **152 tests / 1,352 assertions** over the autoloader and the map, one case per mapped entry          |
| `tests/phpunit/tests/load/adminOnlyBootstrap.php`           | new: **4 tests / 31 assertions** over the two conditional admin-only requires in `wp-settings.php`        |
| `tests/phpunit/tests/load/lazyBootstrapWiring.php`          | new: **5 tests / 36 assertions** over the remaining deferrals — the AI client, the admin page loaders, recovery mode and the translation reader — **outside the plan's file list** |
| `tests/phpunit/tests/dependencies/commandPalette.php`       | new: **12 tests / 30 assertions** — the palette is declined outside the block editor by default, delivered inside it, never widened outside the admin, and answerable either way by filter |
| `tests/phpunit/tests/formatting/emojiGate.php`              | new: **6 tests / 22 assertions** over the emoji gate by context, both directions                          |
| `tests/phpunit/tests/cache/objectCacheGroupStats.php`       | new: **8 tests / 24 assertions** over the per-group register, skipped as a class where a drop-in replaces the cache |
| `tests/phpunit/tests/user/mapMetaCapStateFidelity.php`      | new: **13 tests / 29 assertions** guarding the state fidelity of `map_meta_cap()` under the memo — renamed from `mapMetaCapMemoization.php`, and now guarding a memo that ships |
| `tests/phpunit/tests/option/wpLoadAlloptions.php`           | extended to **15 tests / 26 assertions** covering the primed single-row reads — **outside the plan's file list** |

### Documentation, 1 path

`docs/performance-optimization-report.md` — this document.
