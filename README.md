# tour-feed-pipeline

![CI](https://github.com/bryanhamiltondev/tour-feed-pipeline/actions/workflows/ci.yml/badge.svg)
![License](https://img.shields.io/badge/license-MIT-3fb950?style=flat)
![Dependencies](https://img.shields.io/badge/dependencies-0-3fb950?style=flat)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-3fb950?style=flat)

The tour date ingestion pipeline from [The DJ Calendar](https://thedjcalendar.com),
extracted as a standalone, open-source library. Every artist page on the site
shows a clean, sorted list of upcoming shows. Behind that list: two external
feeds, one hand-curated dataset, three very different opinions about what a
date looks like, and this pipeline - which normalizes all of it into one row
shape, dedupes across sources, caches per artist, and prunes the past.

```
djs.json (curated)    Bandsintown API    Ticketmaster Discovery
         \                  |                   /
          +-----> tourNormalizeEvent <------+     one row shape:
                         |                        ymd, sort_ts, venue, city,
                         v                        state, links, lat/lng,
             tourLooseMatchKey:                   price, sold_out
             ymd | city-slug | venue-slug
                         |
                         v
             tourMergeEventLists
             (first wins, later rows fill gaps,
              ticketmaster outranks bandsintown)
                         |
                         v
             tourFilterUpcomingEvents
             (past out, soonest first)
                         |
                         v
             cache/tours/<artist>.v5.json
             (12h TTL, stale-while-revalidate)
                         |
                         v
             artist pages, city pages, next-show radar
```

## Try it

No network, no API keys, no database:

```bash
php demo/run.php          # fixture pipeline run, end to end
php tests/run-tests.php   # the self-test CI runs
```

The demo feeds one fictional artist's shows through the exact production
path with the awkward data left in on purpose: a show that arrives from two
sources, a venue field that is really the artist's name, a curated date with
no year, a latitude of 999.0, and a row already in the past.

To ingest a real artist, the same functions sit behind
`getMergedTourDatesForDj()`, which fetches, merges, caches, and returns.
Point `src/tour_config.php` at your own Bandsintown app_id and the pipeline
is yours.

## The merge contract

Two sources report the same show. They will disagree on everything except
the fact of the show itself. The merge rules, in order:

1. **Every row is normalized first.** One shape: `ymd` + `sort_ts` + display
   date guaranteed, venue/city/state trimmed and validated, ticket links
   split per provider, coordinates parsed, sold-out detected.
2. **The first row seen wins the slot** (keyed `ymd|city-slug|venue-slug`).
3. **Later duplicates fill gaps, never overwrite.** Ticketmaster knows the
   price, Bandsintown knows the coordinates - the merged row knows both.
4. **The source badge upgrades to the richer feed** when Ticketmaster joins
   a Bandsintown row - and both ticket links are retained, so the visitor
   can buy from either.
5. **Rows with no city and no venue are dropped**, as are rows whose key is
   empty. Garbage in, nothing out.

## War stories (the data-quality section)

Every calendar site eventually learns these. This pipeline learned them the
expensive way, so yours can learn them here:

- **The venue that was actually the artist.** Bandsintown sometimes ships a
  row where the venue field contains the DJ's name - not a venue, just the
  artist echoed back. `tourNormalizeVenueName()` checks the site's artist
  roster and blanks a venue that is really a person. Without this check,
  artist pages grew phantom venues named after the artist.
- **The coordinate 999.0.** Both feeds use `999` (and `0`) as an
  "unknown location" sentinel. Those values parse as valid floats - and one
  of them will place your artist's show near the intersection of the prime
  meridian and the equator, in the Gulf of Guinea. `tourParseCoord()`
  treats sentinels as null, and rows without coordinates simply have no
  coordinates instead of wrong ones.
- **The date with no year.** Hand-curated data says "Mar 15" and means the
  next March 15 - which is next year when you read it in September.
  `tourParseDateToTimestamp()` infers the year, rolls the date forward
  past today, and never emits a past date from a yearless string.

## Cache design

- **Versioned filenames** (`<artist>.v5.json`): the row shape evolved five
  times; bumping the version means old files are simply ignored (and lazily
  unlinked) rather than mass-invalidated mid-deploy.
- **One bulk read per request**: all cache files are preloaded in a single
  glob keyed by artist with newest-mtime wins. On shared hosting this turned
  dozens of per-artist stat+read calls into one pass.
- **Stale-while-revalidate**: readers first try fresh cache; on miss they
  can fall back to stale cache before paying for a network fetch. A slow
  Bandsintown day never blanks an artist page.
- **Past dates are pruned on write and on read**, not just at render time -
  and `tourExpirePersistedTourDates()` reports exactly what it removed.

## Design decisions

| Decision | Why |
|---|---|
| Thin endpoint shim, all logic in one library | Transport separated from logic; the pipeline is testable without HTTP (see `docs/endpoint-shim.example.php`) |
| `function_exists` guards around every site function | The library degrades gracefully outside the host site - fallbacks are defined behavior, not fatals |
| First-wins merge with field backfill | Never lose a field any source provided |
| Source upgrade to ticketmaster on merge | The richer feed wins the badge; both vendor links stay |
| Loose key `ymd\|city\|venue` | Sources disagree on venue spelling and timezones; they rarely disagree on the date and city of a show |
| Yearless-date rolling window | Human-curated dates mean the *next* occurrence |
| Sentinel coordinates rejected | Wrong coordinates are worse than missing ones |
| Versioned cache files | Row-shape evolution without mass invalidation |
| Zero dependencies | Composer-free, drop-in, runs on shared hosting with curl *or* streams |

## What changed from production

| In production | In this repo |
|---|---|
| `tour_config.php` with the live app_id | Placeholder `js_yourdomain.example`; set your own |
| `ticketmaster_api.php` (key holder, private) | Adapter stub: no key = Ticketmaster source contributes zero rows |
| `ticketmaster_affiliate.php` (affiliate wrapper, private) | Omitted; links pass through unwrapped |
| `djs.json` + `djs_store.php` (roster, slug, URL helpers) | Absent; guarded fallbacks engage (regex slugs, plain slugs) |
| HTTP user agent names the site | Genericized agent string |
| Rendering layer (rows, share buttons, per-row JSON-LD) | Stays in production; this repo is ingestion only |
| Everything else | Production code verbatim |

Nothing here exposes endpoints, credentials, database columns, or the
private proxy layer. What ships is the pattern and the reasoning behind it.

## Adding a source

The pipeline is source-agnostic: a new feed is one fetch function that
returns rows in any shape, normalized by `tourNormalizeEvent()` into the
canonical row, then merged. No central registry, no interface - just
normalize and merge. The Ticketmaster fetch in `src/` is the reference
implementation (and the adapter block at the top of the file shows exactly
where your key holder plugs in).

## Requirements

- PHP 8.0+ (`str_ends_with`)
- curl *or* the HTTP stream wrapper - the fetch layer detects which exists

## Origin

Extracted from The DJ Calendar (https://thedjcalendar.com), where this
pipeline feeds tour dates to 99 artist pages, city pages, and the
[next-show radar](https://github.com/bryanhamiltondev/next-show-radar)
map. Like everything published under this account, it is a
production-derived pattern: what ships here is the idea, not the
infrastructure.

## License

MIT
