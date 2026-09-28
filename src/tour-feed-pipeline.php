<?php

declare(strict_types=1);

/**
 * tour-feed-pipeline - the tour date ingestion engine from The DJ Calendar.
 *
 * Fetches upcoming shows from Bandsintown (plus Ticketmaster when a key is
 * configured), normalizes every row into one shape, dedupes across sources
 * with a date|city|venue key, caches per artist in versioned JSON files,
 * and prunes past dates on demand.
 *
 * Extracted from the production tour_merger.php. The only deviations from
 * production are the adapters listed in README.md: the Ticketmaster key
 * holder is a stub, the Bandsintown app_id defaults to a placeholder, the
 * affiliate wrapper is omitted, and the HTTP user agent is genericized.
 * Everything else is production code verbatim - including all the
 * function_exists guards that let it degrade gracefully, never fatal,
 * outside the host site.
 *
 * PHP 8.0+ (str_ends_with).
 */

/* ---------------------------------------------------------------------
 * Adapters (the only non-production code in this file)
 *
 * Production keeps the Ticketmaster key and affiliate wrapper in two
 * private files that are not published. Here they are stubs: with no key
 * configured, the Ticketmaster source contributes zero events and the
 * pipeline runs Bandsintown-only - the exact code path production takes
 * when the key is absent. Point these at your own key holder to enable
 * the second source.
 */
if (!function_exists('tourTicketmasterApiConfigured')) {
    function tourTicketmasterApiConfigured(): bool
    {
        return false;
    }
}

if (!function_exists('tourTicketmasterDiscoveryUrl')) {
    /**
     * Builds a Discovery API URL from a path and query params. Production
     * injects the API key here; this stub leaves the params as given.
     */
    function tourTicketmasterDiscoveryUrl(string $path, array $params = []): string
    {
        return 'https://app.ticketmaster.com/discovery/' . ltrim($path, '/')
            . ($params !== [] ? '?' . http_build_query($params) : '');
    }
}

const DJC_TOUR_CACHE_VERSION = 'v5';
const DJC_TOUR_CACHE_TTL = 43200; // 12 hours

function tourConfig(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = [
        // [repo adaptation] Production defaults this to its own registered
        // app_id. Set yours in tour_config.php beside this file.
        'bandsintown_app_id' => 'js_yourdomain.example',
        'cache_ttl_seconds' => DJC_TOUR_CACHE_TTL,
        'http_timeout_seconds' => 12,
    ];

    $file = __DIR__ . '/tour_config.php';
    if (is_readable($file)) {
        $loaded = include $file;
        if (is_array($loaded)) {
            $defaults = array_merge($defaults, $loaded);
        }
    }

    $defaults['bandsintown_app_id'] = tourNormalizeBandsintownAppId(
        (string) ($defaults['bandsintown_app_id'] ?? '')
    );

    $config = $defaults;
    return $config;
}

function tourNormalizeBandsintownAppId(string $appId): string
{
    $appId = trim($appId);
    if ($appId === '') {
        return 'js_yourdomain.example';
    }
    if (preg_match('/^js_/i', $appId)) {
        return $appId;
    }
    $appId = preg_replace('/^https?:\\/\\//', '', $appId);
    $appId = rtrim($appId, '/');

    return 'js_' . $appId;
}

/**
 * @return string[]
 */
function tourBandsintownAppIdCandidates(): array
{
    $cfg = tourConfig();
    $primary = tourNormalizeBandsintownAppId((string) ($cfg['bandsintown_app_id'] ?? ''));

    // [repo adaptation] Production also lists legacy app_id fallbacks here
    // (older registrations of the same site) and tries each in turn.
    // Add your own fallbacks via tour_config.php if you need them.
    return array_values(array_unique(array_filter([$primary])));
}

function tourDjId(array $dj): string
{
    $id = trim((string) ($dj['id'] ?? ''));
    if ($id !== '') {
        return preg_replace('/[^a-z0-9-]/', '', strtolower($id));
    }

    return preg_replace('/[^a-z0-9]/', '', strtolower((string) ($dj['name'] ?? '')));
}

function tourCacheDir(): string
{
    $dir = __DIR__ . '/cache/tours';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

function tourCacheFilePath(string $djId, string $version = DJC_TOUR_CACHE_VERSION): string
{
    $safe = preg_replace('/[^a-z0-9-]/', '', strtolower($djId));

    return tourCacheDir() . '/' . $safe . '.' . $version . '.json';
}

function tourReadCacheFile(string $path): ?array
{
    if (!is_readable($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

function tourReadCache(string $djId): ?array
{
    $data = tourReadCacheBest($djId, true);
    if ($data === null) {
        return null;
    }
    $filtered = tourFilterUpcomingEvents($data);

    return $filtered !== [] ? $filtered : null;
}

function tourReadCacheStale(string $djId): ?array
{
    $best = tourReadCacheBest($djId, false);

    return $best !== null ? tourFilterUpcomingEvents($best) : null;
}

/**
 * Read the newest tour cache file for one artist (single disk read when possible).
 *
 * @return array<int, array<string, mixed>>|null
 */
function tourReadCacheBest(string $djId, bool $respectTtl = true): ?array
{
    $bulk = tourBulkLoadCaches();
    $djId = preg_replace('/[^a-z0-9-]/', '', strtolower($djId));
    if ($djId === '' || !isset($bulk[$djId])) {
        return null;
    }

    $entry = $bulk[$djId];
    if ($respectTtl) {
        $ttl = (int) (tourConfig()['cache_ttl_seconds'] ?? DJC_TOUR_CACHE_TTL);
        if ((time() - (int) ($entry['mtime'] ?? 0)) > $ttl) {
            return null;
        }
    }

    $data = $entry['events'] ?? null;

    return is_array($data) && $data !== [] ? $data : null;
}

/**
 * Preload all cache/tours/*.json once per request (avoids 40x stat/read on shared hosting).
 *
 * @return array<string, array{mtime: int, events: array<int, array<string, mixed>>}>
 */
function tourBulkLoadCaches(bool $forceReload = false): array
{
    static $bulk = null;
    if ($forceReload) {
        $bulk = null;
    }
    if ($bulk !== null) {
        return $bulk;
    }

    $bulk = [];
    $dir = tourCacheDir();
    $paths = glob($dir . '/*.json') ?: [];
    foreach ($paths as $path) {
        if (!preg_match('#/([a-z0-9-]+)\\.(v\\d+)\\.json$#', $path, $m)) {
            continue;
        }
        $djId = (string) $m[1];
        $mtime = (int) (@filemtime($path) ?: 0);
        if ($mtime <= 0) {
            continue;
        }
        if (isset($bulk[$djId]) && $mtime <= (int) ($bulk[$djId]['mtime'] ?? 0)) {
            continue;
        }
        $data = tourReadCacheFile($path);
        if (!is_array($data) || $data === []) {
            continue;
        }
        $bulk[$djId] = [
            'mtime' => $mtime,
            'events' => $data,
        ];
    }

    return $bulk;
}

function tourWriteCache(string $djId, array $events): void
{
    if ($events === []) {
        return;
    }
    $path = tourCacheFilePath($djId, DJC_TOUR_CACHE_VERSION);
    file_put_contents(
        $path,
        json_encode(array_values($events), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    if (function_exists('djsCollectAllUpcomingEventsInvalidateCache')) {
        djsCollectAllUpcomingEventsInvalidateCache();
    }
}

function tourInvalidateCache(string $djId): void
{
    foreach ([DJC_TOUR_CACHE_VERSION, 'v12', 'v11'] as $ver) {
        $path = tourCacheFilePath($djId, $ver);
        if (is_file($path)) {
            @unlink($path);
        }
    }
    $legacy = tourCacheDir() . '/' . preg_replace('/[^a-z0-9-]/', '', strtolower($djId)) . '.v12.json';
    if (is_file($legacy)) {
        @unlink($legacy);
    }
}

function tourHttpGet(string $url): ?string
{
    $timeout = (int) (tourConfig()['http_timeout_seconds'] ?? 12);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
            // [repo adaptation] Production sends its own site-identifying UA.
            CURLOPT_USERAGENT => 'tour-feed-pipeline/1.0 (+https://github.com/bryanhamiltondev/tour-feed-pipeline)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 300) {
            return $body;
        }

        return null;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'header' => "User-Agent: tour-feed-pipeline/1.0\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);

    return ($body !== false && $body !== '') ? $body : null;
}

function tourUpcomingCutoffTs(): int
{
    if (function_exists('djc_ymd_to_sort_ts')) {
        return djc_ymd_to_sort_ts(function_exists('djc_today_ymd') ? djc_today_ymd() : date('Y-m-d'));
    }

    return (int) strtotime(date('Y-m-d') . ' 00:00:00');
}

function tourParseDateToTimestamp($date): int
{
    $raw = trim((string) $date);
    if ($raw === '') {
        return 0;
    }
    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}/', $raw)) {
        $ts = strtotime($raw);

        return $ts !== false ? (int) $ts : 0;
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return 0;
    }
    $cutoff = tourUpcomingCutoffTs();
    if (!preg_match('/\\d{4}/', $raw) && $ts < $cutoff - 86400 * 14) {
        $retry = strtotime($raw . ' ' . date('Y'));
        if ($retry !== false) {
            $ts = $retry;
        }
    }
    if ($ts < $cutoff) {
        $next = strtotime($raw . ' ' . (string) (date('Y') + 1));
        if ($next !== false) {
            $ts = $next;
        }
    }

    return (int) $ts;
}

function tourFormatDisplayDate(int $ts): string
{
    if ($ts <= 0) {
        return '';
    }

    return strtoupper(date('M j', $ts));
}

function tourEventYmd(array $event): string
{
    if (!empty($event['ymd']) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', (string) $event['ymd'])) {
        return (string) $event['ymd'];
    }
    $ts = (int) ($event['sort_ts'] ?? 0);
    if ($ts <= 0) {
        $ts = tourParseDateToTimestamp($event['date'] ?? $event['event_date'] ?? '');
    }
    if ($ts > 0) {
        return date('Y-m-d', $ts);
    }
    $raw = trim((string) ($event['date'] ?? $event['event_date'] ?? ''));
    if (preg_match('/^(\\d{4}-\\d{2}-\\d{2})/', $raw, $m)) {
        return $m[1];
    }

    return '';
}

function tourFilterUpcomingEvents(array $events): array
{
    if (function_exists('djc_filter_upcoming_tour_events_by_ymd')) {
        return djc_filter_upcoming_tour_events_by_ymd($events);
    }

    if (function_exists('djc_bootstrap_site_timezone')) {
        djc_bootstrap_site_timezone();
    }

    $todayYmd = function_exists('djc_today_ymd') ? djc_today_ymd() : date('Y-m-d');
    $out = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $event = tourEnsureEventDates($event);
        $ymd = tourEventYmd($event);
        if ($ymd === '' || $ymd < $todayYmd) {
            continue;
        }
        $out[] = $event;
    }
    usort($out, static function ($a, $b): int {
        $ymdCmp = tourEventYmd($a) <=> tourEventYmd($b);
        if ($ymdCmp !== 0) {
            return $ymdCmp;
        }

        return ((int) ($a['sort_ts'] ?? 0)) <=> ((int) ($b['sort_ts'] ?? 0));
    });

    return array_values($out);
}

/**
 * Guarantee sort_ts, ymd, and human-readable date on every event row.
 *
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function tourEnsureEventDates(array $event): array
{
    if (function_exists('djc_bootstrap_site_timezone')) {
        djc_bootstrap_site_timezone();
    }

    $explicitYmd = '';
    if (!empty($event['ymd']) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', (string) $event['ymd'])) {
        $explicitYmd = (string) $event['ymd'];
    }

    $ts = (int) ($event['sort_ts'] ?? 0);
    if ($ts <= 0) {
        $ts = tourParseDateToTimestamp($event['date'] ?? $event['event_date'] ?? $event['datetime'] ?? '');
    }
    if ($ts <= 0 && $explicitYmd !== '') {
        $ts = function_exists('djc_ymd_to_sort_ts')
            ? djc_ymd_to_sort_ts($explicitYmd)
            : tourParseDateToTimestamp($explicitYmd);
    }

    if ($explicitYmd !== '') {
        $event['ymd'] = $explicitYmd;
        $event['sort_ts'] = function_exists('djc_ymd_to_sort_ts')
            ? djc_ymd_to_sort_ts($explicitYmd)
            : ($ts > 0 ? $ts : 0);
        $display = trim((string) ($event['date'] ?? ''));
        if ($display === '' || preg_match('/^\\d{4}-\\d{2}-\\d{2}/', $display)) {
            $event['date'] = tourFormatDisplayDate((int) $event['sort_ts']);
        }
    } elseif ($ts > 0) {
        $event['sort_ts'] = $ts;
        $event['ymd'] = date('Y-m-d', $ts);
        $display = trim((string) ($event['date'] ?? ''));
        if ($display === '' || preg_match('/^\\d{4}-\\d{2}-\\d{2}/', $display)) {
            $event['date'] = tourFormatDisplayDate($ts);
        }
    } elseif (trim((string) ($event['date'] ?? '')) === '') {
        $event['date'] = 'TBA';
    }

    return $event;
}

function tourParseCoord($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $n = (float) $value;
    if (abs($n - 999) < 0.01 || $n === 0.0) {
        return null;
    }

    return $n;
}

function tourNormalizeLocationField(string $value): string
{
    $value = trim($value);
    if ($value === '' || strtoupper($value) === 'TBA') {
        return '';
    }

    return $value;
}

/**
 * Detect when Bandsintown/Ticketmaster passes a DJ name as the venue field
 * and replace it with the proper event/venue name derived from the event data.
 */
function tourNormalizeVenueName(string $venue): string
{
    $venue = trim($venue);
    if ($venue === '') {
        return '';
    }

    // Known DJ names that sometimes appear as venue fields (Bandsintown data quirk).
    // When the venue is just a DJ name, try to build a meaningful venue name from context.
    static $djNamePatterns = null;
    if ($djNamePatterns === null) {
        $djs = [];
        if (is_readable(__DIR__ . '/djs.json')) {
            $raw = file_get_contents(__DIR__ . '/djs.json');
            $djs = json_decode($raw ?: '[]', true);
        }
        $djNamePatterns = [];
        if (is_array($djs)) {
            foreach ($djs as $dj) {
                $name = trim((string) ($dj['name'] ?? ''));
                if ($name !== '') {
                    $djNamePatterns[$name] = true;
                }
            }
        }
    }

    // If venue matches a known DJ name, treat it as invalid (bare DJ name is not a venue)
    if (isset($djNamePatterns[$venue])) {
        return '';
    }

    return $venue;
}

function tourMatchCitySlug(array $event): string
{
    $city = tourNormalizeLocationField((string) ($event['city'] ?? ''));
    if ($city === '') {
        return '';
    }
    $city = trim((string) (explode(',', $city)[0] ?? $city));
    if (!function_exists('djsCitySlug') && is_readable(__DIR__ . '/djs_store.php')) {
        require_once __DIR__ . '/djs_store.php';
    }

    return function_exists('djsCitySlug')
        ? djsCitySlug($city)
        : strtolower(trim((string) preg_replace('/[^a-z0-9]+/', '-', $city), '-'));
}

function tourMatchVenueSlug(array $event): string
{
    $venue = tourNormalizeLocationField((string) ($event['venue'] ?? ''));
    if ($venue === '') {
        return '';
    }
    if (!function_exists('djsVenueSlug') && is_readable(__DIR__ . '/djs_store.php')) {
        require_once __DIR__ . '/djs_store.php';
    }

    return function_exists('djsVenueSlug')
        ? djsVenueSlug($venue)
        : strtolower(trim((string) preg_replace('/[^a-z0-9]+/', '-', $venue), '-'));
}

function tourLooseMatchKey(array $event): string
{
    $event = tourEnsureEventDates($event);
    $ymd = tourEventYmd($event);
    if ($ymd === '') {
        $ymd = strtolower(trim((string) ($event['date'] ?? 'unknown')));
    }

    return $ymd . '|' . tourMatchCitySlug($event) . '|' . tourMatchVenueSlug($event);
}

/**
 * @param array<int, array<string, mixed>> $events
 * @return array<int, array<string, mixed>>
 */
function tourDedupeEventList(array $events): array
{
    if ($events === []) {
        return [];
    }

    return tourMergeEventLists($events);
}

function tourEventIsSoldOut(array $event): bool
{
    if (!empty($event['sold_out'])) {
        return true;
    }
    $status = strtolower(trim((string) ($event['status'] ?? '')));

    return $status === 'soldout' || $status === 'sold_out' || $status === 'sold out';
}

/** True when the event has at least one ticket vendor URL and is not sold out. */
function tourEventHasAvailableTickets(array $event): bool
{
    if (tourEventIsSoldOut($event)) {
        return false;
    }

    return tourTicketProvidersFromEvent($event) !== [];
}

/** Primary ticket URL for display, or empty when none are available. */
function tourEventAvailableTicketUrl(array $event): string
{
    if (!tourEventHasAvailableTickets($event)) {
        return '';
    }

    $providers = tourTicketProvidersFromEvent($event);
    if ($providers === []) {
        return '';
    }

    return trim((string) ($providers[0]['url'] ?? ''));
}

function tourNormalizeEvent(array $event, string $defaultSource = '', bool $wrapAffiliateLinks = true): array
{
    $ts = tourParseDateToTimestamp($event['date'] ?? $event['event_date'] ?? $event['datetime'] ?? '');
    if ($ts <= 0 && !empty($event['sort_ts'])) {
        $ts = (int) $event['sort_ts'];
    }

    $dateDisplay = trim((string) ($event['date'] ?? ''));
    if ($dateDisplay === '' && $ts > 0) {
        $dateDisplay = tourFormatDisplayDate($ts);
    } elseif ($ts > 0 && preg_match('/^\\d{4}-\\d{2}-\\d{2}/', $dateDisplay)) {
        $dateDisplay = tourFormatDisplayDate($ts);
    }

    $source = strtolower(trim((string) ($event['source'] ?? $defaultSource)));
    $link = trim((string) ($event['link'] ?? $event['ticket_url'] ?? ''));
    $linkTm = trim((string) ($event['link_tm'] ?? ''));
    $linkBit = trim((string) ($event['link_bit'] ?? ''));

    if ($source === 'ticketmaster' && $link !== '' && $linkTm === '') {
        $linkTm = $link;
    }
    if ($source === 'bandsintown' && $link !== '' && $linkBit === '') {
        $linkBit = $link;
    }
    if ($link === '' && $linkTm !== '') {
        $link = $linkTm;
    }
    if ($link === '' && $linkBit !== '') {
        $link = $linkBit;
    }

    if ($wrapAffiliateLinks) {
        if ($link !== '' && function_exists('tmAffiliateWrapUrl') && tmAffiliateIsTicketmasterUrl($link)) {
            $link = tmAffiliateWrapUrl($link);
        }
        if ($linkTm !== '' && function_exists('tmAffiliateWrapUrl')) {
            $linkTm = tmAffiliateWrapUrl($linkTm);
        }
    }

    $norm = [
        'date' => $dateDisplay,
        'sort_ts' => $ts,
        'venue' => tourNormalizeVenueName(trim((string) ($event['venue'] ?? ''))),
        'city' => trim((string) ($event['city'] ?? '')),
        'state' => trim((string) ($event['state'] ?? $event['region'] ?? '')),
        'link' => $link,
        'source' => $source,
    ];

    if ($ts > 0) {
        $norm['ymd'] = date('Y-m-d', $ts);
    }

    $lat = tourParseCoord($event['lat'] ?? $event['latitude'] ?? null);
    $lng = tourParseCoord($event['lng'] ?? $event['longitude'] ?? null);
    if ($lat !== null && $lng !== null) {
        $norm['lat'] = $lat;
        $norm['lng'] = $lng;
    }
    if ($linkTm !== '') {
        $norm['link_tm'] = $linkTm;
    }
    if ($linkBit !== '') {
        $norm['link_bit'] = $linkBit;
    }
    if (tourEventIsSoldOut($event)) {
        $norm['sold_out'] = true;
    }

    foreach (['ticket_price', 'ticket_price_max', 'ticket_currency', 'free', 'is_free'] as $field) {
        if (!isset($event[$field]) || $event[$field] === '' || $event[$field] === null) {
            continue;
        }
        if (in_array($field, ['ticket_price', 'ticket_price_max'], true) && !is_numeric($event[$field])) {
            continue;
        }
        $norm[$field] = $event[$field];
    }

    return tourEnsureEventDates($norm);
}

function tourMergeEventLists(array ...$lists): array
{
    $byKey = [];

    foreach ($lists as $list) {
        foreach ($list as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $event = tourNormalizeEvent($raw, '', false);
            if (trim((string) ($event['city'] ?? '')) === '' && trim((string) ($event['venue'] ?? '')) === '') {
                continue;
            }
            $key = tourLooseMatchKey($event);
            if ($key === '||' || str_ends_with($key, '||')) {
                continue;
            }

            if (!isset($byKey[$key])) {
                $byKey[$key] = $event;
                continue;
            }

            $existing = $byKey[$key];
            foreach (['link_tm', 'link_bit', 'link', 'lat', 'lng', 'state', 'sold_out', 'sort_ts', 'ymd', 'date',
                'ticket_currency', 'free', 'is_free'] as $field) {
                if (!empty($event[$field]) && empty($existing[$field])) {
                    $existing[$field] = $event[$field];
                }
            }
            foreach (['ticket_price', 'ticket_price_max'] as $field) {
                if (isset($event[$field]) && is_numeric($event[$field]) && !isset($existing[$field])) {
                    $existing[$field] = $event[$field];
                }
            }
            if (($existing['source'] ?? '') === 'bandsintown' && ($event['source'] ?? '') === 'ticketmaster') {
                $existing['source'] = 'ticketmaster';
            }
            if (!empty($event['link_tm'])) {
                $existing['link_tm'] = $event['link_tm'];
            }
            if (!empty($event['link_bit'])) {
                $existing['link_bit'] = $event['link_bit'];
            }
            $byKey[$key] = tourEnsureEventDates($existing);
        }
    }

    return tourFilterUpcomingEvents(array_values($byKey));
}

function tourFetchTicketmasterEvents(string $artistName): array
{
    if (!tourTicketmasterApiConfigured()) {
        return [];
    }

    $url = tourTicketmasterDiscoveryUrl('discovery/v2/events.json', [
        'keyword' => $artistName,
        'classificationName' => 'Music',
        'sort' => 'date,asc',
        'size' => '50',
        'countryCode' => 'US',
    ]);

    $raw = tourHttpGet($url);
    if ($raw === null) {
        return [];
    }

    $data = json_decode($raw, true);
    $events = $data['_embedded']['events'] ?? [];
    if (!is_array($events)) {
        return [];
    }

    $out = [];
    foreach ($events as $e) {
        if (!is_array($e)) {
            continue;
        }
        $venue = $e['_embedded']['venues'][0] ?? [];
        $start = $e['dates']['start'] ?? [];
        $dateRaw = $start['localDate'] ?? '';
        if ($dateRaw === '' && !empty($start['dateTime'])) {
            $parsed = strtotime((string) $start['dateTime']);
            if ($parsed !== false) {
                $dateRaw = date('Y-m-d', $parsed);
            }
        }
        if ($dateRaw === '') {
            continue;
        }

        $row = [
            'date' => $dateRaw,
            'venue' => (string) ($venue['name'] ?? 'TBA'),
            'city' => (string) ($venue['city']['name'] ?? ''),
            'state' => (string) ($venue['state']['stateCode'] ?? $venue['state']['name'] ?? ''),
            'link' => (string) ($e['url'] ?? ''),
            'source' => 'ticketmaster',
        ];

        $lat = tourParseCoord($venue['location']['latitude'] ?? null);
        $lng = tourParseCoord($venue['location']['longitude'] ?? null);
        if ($lat !== null && $lng !== null) {
            $row['lat'] = $lat;
            $row['lng'] = $lng;
        }

        $status = strtolower((string) ($e['dates']['status']['code'] ?? ''));
        if ($status === 'offsale' || ($status !== '' && stripos($status, 'cancel') !== false)) {
            $row['sold_out'] = true;
        }

        $priceRanges = $e['priceRanges'] ?? [];
        if (is_array($priceRanges)) {
            $best = null;
            foreach ($priceRanges as $pr) {
                if (!is_array($pr) || !isset($pr['min']) || !is_numeric($pr['min'])) {
                    continue;
                }
                $min = (float) $pr['min'];
                $currency = strtoupper(trim((string) ($pr['currency'] ?? 'USD')));
                if ($currency === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
                    $currency = 'USD';
                }
                if ($best === null || $min < $best['min']) {
                    $best = [
                        'min' => $min,
                        'max' => isset($pr['max']) && is_numeric($pr['max']) ? (float) $pr['max'] : null,
                        'currency' => $currency,
                    ];
                }
            }
            if ($best !== null) {
                $row['ticket_price'] = $best['min'];
                if ($best['max'] !== null && $best['max'] > $best['min']) {
                    $row['ticket_price_max'] = $best['max'];
                }
                $row['ticket_currency'] = $best['currency'];
            }
        }

        $out[] = tourNormalizeEvent($row, 'ticketmaster');
    }

    return $out;
}

function tourBandsintownResponseIsEventsList($data): bool
{
    if (!is_array($data) || $data === []) {
        return false;
    }
    if (isset($data['errorMessage']) || isset($data['Message'])) {
        return false;
    }

    return array_keys($data) === range(0, count($data) - 1);
}

function tourFetchBandsintownEvents(string $artistName, string $djId = ''): array
{
    return tourFetchBandsintown($artistName, $djId);
}

function tourFetchBandsintown(string $artistName, string $djId = ''): array
{
    $artistKeys = array_values(array_unique(array_filter([
        trim($artistName),
        trim($djId),
    ])));

    foreach ($artistKeys as $artistKey) {
        foreach (tourBandsintownAppIdCandidates() as $appId) {
            $url = 'https://rest.bandsintown.com/artists/' . rawurlencode($artistKey)
                . '/events?app_id=' . rawurlencode($appId) . '&date=upcoming';

            $raw = tourHttpGet($url);
            if ($raw === null) {
                continue;
            }

            $data = json_decode($raw, true);
            if (!tourBandsintownResponseIsEventsList($data)) {
                continue;
            }

            $parsed = tourParseBandsintownEvents($data);
            if ($parsed !== []) {
                return $parsed;
            }
        }
    }

    return [];
}

/**
 * @param array<int, mixed> $data
 * @return array<int, array<string, mixed>>
 */
function tourParseBandsintownEvents(array $data): array
{
    $out = [];
    foreach ($data as $e) {
        if (!is_array($e)) {
            continue;
        }
        $datetime = $e['datetime'] ?? '';
        if ($datetime === '') {
            continue;
        }
        $ts = strtotime((string) $datetime);
        if ($ts === false) {
            continue;
        }

        $row = [
            'date' => date('Y-m-d', $ts),
            'datetime' => (string) $datetime,
            'venue' => (string) ($e['venue']['name'] ?? 'TBA'),
            'city' => (string) ($e['venue']['city'] ?? ''),
            'state' => (string) ($e['venue']['region'] ?? ''),
            'link' => (string) ($e['url'] ?? ''),
            'source' => 'bandsintown',
        ];

        $lat = tourParseCoord($e['venue']['latitude'] ?? null);
        $lng = tourParseCoord($e['venue']['longitude'] ?? null);
        if ($lat !== null && $lng !== null) {
            $row['lat'] = $lat;
            $row['lng'] = $lng;
        }
        if (!empty($e['sold_out'])) {
            $row['sold_out'] = true;
        }

        $out[] = tourNormalizeEvent($row, 'bandsintown');
    }

    return $out;
}

function tourStampArtistOnEvent(array $event, array $dj): array
{
    if (trim((string) ($event['artist_id'] ?? '')) === '') {
        $artistId = trim((string) ($dj['id'] ?? ''));
        if ($artistId !== '') {
            $event['artist_id'] = $artistId;
        }
    }
    if (trim((string) ($event['artist_name'] ?? '')) === '') {
        $artistName = trim((string) ($dj['name'] ?? ''));
        if ($artistName !== '') {
            $event['artist_name'] = $artistName;
        }
    }

    return $event;
}

/**
 * @param array<int, array<string, mixed>> $events
 * @return array<int, array<string, mixed>>
 */
function tourStampArtistOnEvents(array $events, array $dj): array
{
    if ($events === []) {
        return [];
    }

    $out = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $out[] = tourStampArtistOnEvent($event, $dj);
    }

    return $out;
}

/**
 * @return array<int, array<string, mixed>>
 */
function getMergedTourDatesForDj(array $dj, bool $force = false, bool $allowFetch = false): array
{
    $djId = tourDjId($dj);
    if ($djId === '') {
        return [];
    }

    $finish = static function (array $events) use ($dj): array {
        return tourStampArtistOnEvents(tourDedupeEventList($events), $dj);
    };

    if ($force) {
        tourInvalidateCache($djId);
    } elseif (!$force) {
        $fresh = tourReadCache($djId);
        if ($fresh !== null && $fresh !== []) {
            return $finish($fresh);
        }
    }

    $local = is_array($dj['tour_dates'] ?? null) ? $dj['tour_dates'] : [];
    $local = tourFilterUpcomingEvents(array_map(
        static fn($e) => tourNormalizeEvent(is_array($e) ? $e : []),
        $local
    ));

    if (!$allowFetch && !$force) {
        $stale = tourReadCacheStale($djId);
        if ($stale !== null && $stale !== []) {
            return $finish($stale);
        }

        return $finish($local);
    }

    $external = [];
    $bit = tourFetchBandsintownEvents((string) ($dj['name'] ?? ''), $djId);
    if ($bit !== []) {
        $external = array_merge($external, $bit);
    }
    $tm = tourFetchTicketmasterEvents((string) ($dj['name'] ?? ''));
    if ($tm !== []) {
        $external = array_merge($external, $tm);
    }

    $merged = tourDedupeEventList(tourMergeEventLists($local, $external));

    if ($merged !== []) {
        tourWriteCache($djId, $merged);
    }

    return $finish($merged);
}

/**
 * Remove past tour dates from djs.json and per-artist tour cache files.
 *
 * @return array{today: string, djs_pruned: int, shows_removed: int, caches_rewritten: int, caches_removed: int}
 */
function tourExpirePersistedTourDates(bool $persist = true): array
{
    if (function_exists('djc_bootstrap_site_timezone')) {
        djc_bootstrap_site_timezone();
    }

    $stats = [
        'today' => function_exists('djc_today_ymd') ? djc_today_ymd() : date('Y-m-d'),
        'djs_pruned' => 0,
        'shows_removed' => 0,
        'caches_rewritten' => 0,
        'caches_removed' => 0,
    ];

    if ($persist && is_readable(__DIR__ . '/djs_store.php')) {
        require_once __DIR__ . '/djs_store.php';
        $djs = djsLoad();
        $dirty = false;
        foreach ($djs as &$dj) {
            if (!is_array($dj) || !is_array($dj['tour_dates'] ?? null) || $dj['tour_dates'] === []) {
                continue;
            }
            $before = count($dj['tour_dates']);
            $afterList = tourFilterUpcomingEvents($dj['tour_dates']);
            $after = count($afterList);
            if ($after === $before) {
                continue;
            }
            $stats['shows_removed'] += $before - $after;
            $stats['djs_pruned']++;
            $dj['tour_dates'] = $afterList;
            $dirty = true;
        }
        unset($dj);
        if ($dirty && function_exists('djsSave')) {
            djsSave($djs);
        }
    }

    $dir = tourCacheDir();
    foreach (glob($dir . '/*.json') ?: [] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $data = tourReadCacheFile($path);
        if (!is_array($data) || $data === []) {
            continue;
        }
        $filtered = tourFilterUpcomingEvents($data);
        if (count($filtered) === count($data)) {
            continue;
        }
        $stats['shows_removed'] += count($data) - count($filtered);
        if ($filtered === []) {
            if (@unlink($path)) {
                $stats['caches_removed']++;
            }
            continue;
        }
        if (file_put_contents(
            $path,
            json_encode(array_values($filtered), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ) !== false) {
            $stats['caches_rewritten']++;
        }
    }

    tourBulkLoadCaches(true);

    if (function_exists('djsCollectAllUpcomingEventsInvalidateCache')) {
        djsCollectAllUpcomingEventsInvalidateCache();
    }

    return $stats;
}

/**
 * @return array<int, array<string, mixed>>
 */
function tourFetchExternal(string $artistName, string $djId, bool $force = true): array
{
    return getMergedTourDatesForDj(
        ['id' => $djId, 'name' => $artistName, 'tour_dates' => []],
        $force,
        true
    );
}

function tourProbeBandsintown(string $artistName = 'Martin Garrix'): array
{
    $events = tourFetchBandsintown($artistName, 'martingarrix');

    return [
        'artist' => $artistName,
        'app_ids_tried' => tourBandsintownAppIdCandidates(),
        'show_count' => count($events),
        'sample' => $events[0] ?? null,
    ];
}

/**
 * @param array<string, mixed> $event
 * @return list<array{id: string, label: string, url: string}>
 */
function tourTicketProvidersFromEvent(array $event): array
{
    $tm = trim((string) ($event['link_tm'] ?? ''));
    $bit = trim((string) ($event['link_bit'] ?? ''));
    $fallback = trim((string) ($event['link'] ?? ''));
    $source = strtolower(trim((string) ($event['source'] ?? '')));

    if ($tm === '' && $source === 'ticketmaster' && $fallback !== '') {
        $tm = $fallback;
    }
    if ($bit === '' && $source === 'bandsintown' && $fallback !== '') {
        $bit = $fallback;
    }
    if ($tm !== '' && function_exists('tmAffiliateWrapUrl')) {
        $tm = tmAffiliateWrapUrl($tm);
    }

    $providers = [];
    $seen = [];
    foreach ([
        ['id' => 'ticketmaster', 'label' => 'Ticketmaster', 'url' => $tm],
        ['id' => 'bandsintown', 'label' => 'Bandsintown', 'url' => $bit],
    ] as $candidate) {
        $url = trim((string) ($candidate['url'] ?? ''));
        if ($url === '' || $url === '#') {
            continue;
        }
        $key = strtolower($url);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $providers[] = $candidate;
    }

    if ($fallback !== '' && $fallback !== '#') {
        $wrapped = function_exists('tmAffiliateWrapUrl') ? tmAffiliateWrapUrl($fallback) : $fallback;
        $key = strtolower($wrapped);
        if (!isset($seen[$key])) {
            $providers[] = [
                'id' => 'vendor',
                'label' => 'Tickets',
                'url' => $wrapped,
            ];
        }
    }

    return $providers;
}
