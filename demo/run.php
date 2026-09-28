#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * tour-feed-pipeline demo - no network, no API keys, no database.
 *
 * Fixture rows for one fictional artist ("Nova Crown") travel the exact
 * path production uses - normalize, merge, freshness filter, disk cache -
 * with the awkward real-world data left in on purpose:
 *
 *   - the same show arrives from two sources (dedupe + field backfill)
 *   - one feed reports the artist's name as the venue (production data quirk)
 *   - one curated date has no year at all ("Mar 15")
 *   - one row carries latitude 999.0, the feeds' "unknown" marker
 *   - one row is already in the past
 */

require __DIR__ . '/../src/tour-feed-pipeline.php';

function fixture(string $ymd, string $city, string $venue, array $extra = []): array
{
    return array_merge([
        'date' => $ymd,
        'city' => $city,
        'venue' => $venue,
        'state' => '',
        'link' => '',
        'source' => '',
    ], $extra);
}

$ymd = static fn (string $modifier): string => date('Y-m-d', strtotime($modifier));

/* Stage the roster the venue-quirk filter reads - on the live site this is
   always present (it is the artist database), so the demo ships it too and
   removes it on exit. */
$rosterPath = dirname(__DIR__) . '/src/djs.json';
file_put_contents($rosterPath, json_encode([['id' => 'nova-crown', 'name' => 'Nova Crown']]));
register_shutdown_function(static function () use ($rosterPath): void {
    if (is_file($rosterPath)) {
        @unlink($rosterPath);
    }
});

/* The three sources, in their native shapes - before normalization. */
$local = [
    fixture('Mar 15', 'Brooklyn', 'Brooklyn Mirage', ['source' => 'local']),
    fixture('2025-01-10', 'Chicago', 'Radius', ['source' => 'local']),
    fixture($ymd('+42 days'), 'Miami', 'Treehouse Miami', [
        'source' => 'local',
        'link' => 'https://tickets.example/nova-crown-miami',
    ]),
];

$bandsintown = [
    [
        'datetime' => $ymd('+18 days') . 'T20:00:00',
        'venue' => [
            'name' => 'Brooklyn Mirage',
            'city' => 'Brooklyn',
            'region' => 'NY',
            'latitude' => '40.7064',
            'longitude' => '-73.9235',
        ],
        'url' => 'https://www.bandsintown.com/e/104-000-001',
    ],
    [
        'datetime' => $ymd('+42 days') . 'T21:00:00',
        'venue' => [
            'name' => 'Nova Crown',
            'city' => 'Miami',
            'region' => 'FL',
            'latitude' => '999',
            'longitude' => '999',
        ],
        'url' => 'https://www.bandsintown.com/e/104-000-002',
    ],
];

$ticketmaster = [
    [
        'date' => $ymd('+18 days'),
        'venue' => 'Brooklyn Mirage',
        'city' => 'Brooklyn',
        'state' => 'NY',
        'link' => 'https://www.ticketmaster.com/nova-crown-tickets',
        'source' => 'ticketmaster',
        'ticket_price' => 45,
        'ticket_price_max' => 90,
        'ticket_currency' => 'USD',
    ],
];

echo "tour-feed-pipeline demo\n";
echo str_repeat('-', 72) . "\n";

/* Parse each feed through the production parsers. */
$bitEvents = tourParseBandsintownEvents($bandsintown);
$tmEvents = array_map(static fn ($row) => tourNormalizeEvent($row, 'ticketmaster'), $ticketmaster);
$localEvents = array_map(static fn ($row) => tourNormalizeEvent(is_array($row) ? $row : []), $local);

echo sprintf(
    "rows in: local %d, bandsintown %d, ticketmaster %d (total %d)\n\n",
    count($localEvents),
    count($bitEvents),
    count($tmEvents),
    count($localEvents) + count($bitEvents) + count($tmEvents)
);

echo "What normalization already fixed:\n";
echo "  - venue \"Nova Crown\" (Miami row) -> blanked: the roster knows that is the artist, not a place\n";
echo "  - latitude 999 -> rejected: that is the feeds' \"unknown location\" sentinel, not the Gulf of Guinea\n";
echo "  - \"Mar 15\" with no year -> rolled to the next occurrence\n";
echo "\n";

/* Merge: normalize happened inside the parsers; this dedupes and filters. */
$merged = tourMergeEventLists($localEvents, $bitEvents, $tmEvents);

echo "The dedupe key that caught the cross-source duplicate:\n";
echo "  " . tourLooseMatchKey($bitEvents[0] ?? []) . "\n";
echo "  (same ymd + city slug + venue slug in both feeds -> one row, fields backfilled)\n\n";

printf("%-11s %-9s %-12s %-20s %-13s %s\n", 'YMD', 'DATE', 'CITY', 'VENUE', 'SOURCE', 'EXTRAS');
foreach ($merged as $row) {
    $extras = [];
    if (isset($row['ticket_price'])) {
        $range = isset($row['ticket_price_max'])
            ? sprintf('%.0f-%.0f %s', $row['ticket_price'], $row['ticket_price_max'], $row['ticket_currency'] ?? 'USD')
            : sprintf('%.0f %s', $row['ticket_price'], $row['ticket_currency'] ?? 'USD');
        $extras[] = 'price ' . $range;
    }
    if (isset($row['lat'])) {
        $extras[] = 'geo';
    }
    if (count(tourTicketProvidersFromEvent($row)) > 1) {
        $extras[] = count(tourTicketProvidersFromEvent($row)) . ' vendors';
    }
    if (!empty($row['sold_out'])) {
        $extras[] = 'SOLD OUT';
    }
    printf(
        "%-11s %-9s %-12s %-20s %-13s %s\n",
        $row['ymd'] ?? '-',
        $row['date'] ?? '-',
        $row['city'] !== '' ? $row['city'] : '-',
        $row['venue'] !== '' ? $row['venue'] : '(TBA)',
        $row['source'] !== '' ? $row['source'] : '-',
        $extras !== [] ? implode(', ', $extras) : '-'
    );
}

printf(
    "\nrows out: %d (one cross-source duplicate merged, one past date pruned)\n",
    count($merged)
);

/* Cache round-trip - versioned files, 12h TTL, stale-while-revalidate reads. */
tourWriteCache('nova-crown', $merged);
$fromCache = tourReadCache('nova-crown');
echo "cache: wrote " . basename(tourCacheFilePath('nova-crown'))
    . ' (' . DJC_TOUR_CACHE_VERSION . ', TTL 12h), read back '
    . count($fromCache ?? []) . " upcoming rows\n";
tourInvalidateCache('nova-crown');
echo "cache: invalidated\n";

echo "\nNo network was touched. In production the same functions sit behind\n";
echo "getMergedTourDatesForDj(), which fetches, merges, caches, and returns.\n";
