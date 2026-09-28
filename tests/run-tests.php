<?php

declare(strict_types=1);

/**
 * tour-feed-pipeline self-test - the same two-direction philosophy as the
 * other repos in this portfolio: the test suite runs the library exactly
 * as the site does, and CI fails if any contract breaks.
 */

require __DIR__ . '/../src/tour-feed-pipeline.php';

$passes = 0;
$failures = 0;

function check(bool $cond, string $label): void
{
    global $passes, $failures;
    if ($cond) {
        $passes++;
        echo "  ok   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}\n";
    }
}

$ymd = static fn (string $modifier): string => date('Y-m-d', strtotime($modifier));

echo "tour-feed-pipeline self-test\n";
echo str_repeat('-', 72) . "\n";

/* --- The venue-quirk filter -------------------------------------------------
   tourNormalizeVenueName() caches the roster statically per request, so this
   must be the FIRST test to touch it. It reads djs.json beside the library -
   stage one, exercise the filter, remove it. */
$rosterPath = dirname(__DIR__) . '/src/djs.json';
file_put_contents($rosterPath, json_encode([['id' => 'nova-crown', 'name' => 'Nova Crown']]));
check(tourNormalizeVenueName('Nova Crown') === '', 'venue that is really the artist name is blanked');
check(tourNormalizeVenueName('Brooklyn Mirage') === 'Brooklyn Mirage', 'real venue names pass through');
unlink($rosterPath);
check(!is_file($rosterPath), 'roster fixture cleaned up');

/* --- Coordinates ------------------------------------------------------------ */
check(tourParseCoord('999') === null, 'sentinel 999 is rejected');
check(tourParseCoord('0.0') === null, 'sentinel 0 is rejected');
check(tourParseCoord('40.7064') === 40.7064, 'real coordinates parse');
check(tourParseCoord('abc') === null, 'non-numeric coordinates are rejected');
check(tourParseCoord(null) === null, 'missing coordinates are null, not zero');

/* --- Location normalization ------------------------------------------------- */
check(tourNormalizeLocationField('TBA') === '', 'TBA is not a location');
check(tourNormalizeLocationField('  Brooklyn  ') === 'Brooklyn', 'location fields are trimmed');

/* --- Dates ------------------------------------------------------------------ */
$ts = tourParseDateToTimestamp('Mar 15');
check($ts > time(), 'yearless date rolls to the next occurrence, never the past');
$e = tourEnsureEventDates(['date' => 'Mar 15']);
check(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($e['ymd'] ?? '')) === 1, 'yearless event gets an explicit ymd');
check(($e['date'] ?? '') === 'Mar 15', 'human-curated display dates pass through untouched');
$iso = tourEnsureEventDates(['date' => '2026-11-07']);
check(($iso['date'] ?? '') === 'NOV 7', 'ISO-derived display dates render as the uppercase abbreviated month');
check(tourEventYmd(['date' => '2026-11-07']) === '2026-11-07', 'ISO dates pass through unchanged');
check(tourParseDateToTimestamp('') === 0, 'empty date is zero, not now');

/* --- Freshness -------------------------------------------------------------- */
$out = tourFilterUpcomingEvents([
    ['date' => '2001-02-03', 'city' => 'Chicago', 'venue' => 'Radius'],
]);
check($out === [], 'past events are pruned');
$sorted = tourFilterUpcomingEvents([
    tourNormalizeEvent(['date' => $ymd('+40 days'), 'city' => 'Austin', 'venue' => "Emo's", 'source' => 'local']),
    tourNormalizeEvent(['date' => $ymd('+5 days'), 'city' => 'Boston', 'venue' => 'House of Blues', 'source' => 'local']),
]);
check(count($sorted) === 2 && tourEventYmd($sorted[0]) < tourEventYmd($sorted[1]), 'upcoming events come out soonest-first');

/* --- Merge: the two-source contract ----------------------------------------- */
$bit = tourNormalizeEvent([
    'date' => $ymd('+18 days'),
    'venue' => 'Brooklyn Mirage',
    'city' => 'Brooklyn',
    'state' => 'NY',
    'link' => 'https://www.bandsintown.com/e/999',
    'source' => 'bandsintown',
    'lat' => '40.7064',
    'lng' => '-73.9235',
], 'bandsintown');
$tm = tourNormalizeEvent([
    'date' => $ymd('+18 days'),
    'venue' => 'Brooklyn Mirage',
    'city' => 'Brooklyn',
    'state' => 'NY',
    'link' => 'https://www.ticketmaster.com/example',
    'source' => 'ticketmaster',
    'ticket_price' => 40,
    'ticket_price_max' => 80,
    'ticket_currency' => 'USD',
], 'ticketmaster');

check(tourLooseMatchKey($bit) === tourLooseMatchKey($tm), 'both sources produce the same loose key for the same show');
$merged = tourMergeEventLists([$bit], [$tm]);
check(count($merged) === 1, 'same show from two sources merges to one row');
$row = $merged[0] ?? [];
check(($row['source'] ?? '') === 'ticketmaster', 'source upgrades to the richer feed');
check(abs((float) ($row['ticket_price'] ?? 0) - 40) < 0.001, 'price backfills from the second source');
check(abs((float) ($row['lat'] ?? 0) - 40.7064) < 0.0001, 'coordinates survive the merge');
check(count(tourTicketProvidersFromEvent($row)) === 2, 'both ticket vendors are retained');
check(count(tourMergeEventLists([$bit], [$tm])) === 1, 'merging again is stable (idempotent)');

/* --- Tickets ---------------------------------------------------------------- */
check(tourEventIsSoldOut(['status' => 'sold out']), 'sold-out status variants are recognized');
check(tourEventHasAvailableTickets($row), 'merged row has available tickets');
check(tourEventHasAvailableTickets(['link' => 'https://example.com/t', 'sold_out' => true]) === false, 'sold-out rows report no tickets');

/* --- Cache round-trip -------------------------------------------------------- */
tourWriteCache('nova-crown', [
    ['date' => $ymd('+30 days'), 'city' => 'Philadelphia', 'venue' => 'The Filladelphia', 'source' => 'bandsintown', 'link' => 'https://example.com/t'],
]);
$cached = tourReadCacheBest('nova-crown');
check(is_array($cached) && count($cached) === 1, 'cache round-trip preserves rows');
check(tourReadCache('nova-crown') !== null, 'fresh cache read returns upcoming rows');
tourInvalidateCache('nova-crown');
/* The bulk cache read is per-request by design (one glob per request).
   Invalidate within the same process, then simulate the next request. */
tourBulkLoadCaches(true);
check(tourReadCacheBest('nova-crown') === null, 'invalidation clears the artist cache on the next request');

/* --- Identity --------------------------------------------------------------- */
check(tourDjId(['id' => 'nova-crown']) === 'nova-crown', 'slug ids pass through unchanged');
check(tourDjId(['id' => 'Nova Crown!']) === 'novacrown', 'non-slug characters are stripped from ids');
check(tourDjId(['name' => 'Fred again..']) === 'fredagain', 'name fallback slugifies too');

/* --- Garbage resistance ------------------------------------------------------ */
check(tourMergeEventLists([['nope' => true], 'not-even-an-array', ['city' => '   ', 'venue' => '  ']]) === [], 'rows with no city and no venue are dropped');
check(tourBandsintownResponseIsEventsList(['errorMessage' => 'nope']) === false, 'API error objects are not mistaken for event lists');

echo str_repeat('-', 72) . "\n";
echo "{$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
