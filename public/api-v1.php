<?php

/**
 * api-v1.php — GET endpoint: one day of 15-minute energy slots, as JSON.
 *
 * Contract:
 *   GET /api-v1.php?date=YYYY-MM-DD&meter_id=...
 *   Authorization: Bearer <API_TOKEN>
 *
 *   200 day of slots (possibly empty) | 400 bad date | 401 bad token
 *   404 unknown meter                 | 405 wrong verb
 *
 * This is the read-side twin of ingest.php: what the Pi POSTs in,
 * a client GETs back out — same table, opposite direction.
 */

declare(strict_types=1);


// __DIR__ = the folder THIS file lives in (public/), so the paths work no
// matter where the web server was started from. ../src is OUTSIDE the web
// root: helpers and config can never be requested directly by a browser.
require_once __DIR__ . '/../src/config.php'; # function to get the environment variables
require_once __DIR__ . '/../src/api-helper.php'; # helper api functions
require_once __DIR__ . '/../src/db.php'; # database functions.


// ── Gate 1: only GET may pass (shared helper — same 405 + Allow header
//    behavior as ingest.php, defined exactly once) ────────────────────────
require_method('GET');

// ── Gate 2: authentication ───────────────────────────────────────────────
// Same Bearer token as ingest — the load profile reveals when someone is
// home / on holiday, so it is NOT public (unlike ESIT's prices).
//
// $_SERVER is the superglobal where PHP exposes the request environment;
// incoming HTTP headers appear uppercased with an HTTP_ prefix:
//   Authorization  ->  HTTP_AUTHORIZATION
// If the client sent no such header the key doesn't exist AT ALL, so
// "?? ''" (null coalescing) substitutes an empty string — the function
// always receives a real string, and "missing" flows into the normal
// comparison as a failed match.
require_bearer_token($_SERVER['HTTP_AUTHORIZATION'] ?? '', config('API_TOKEN'));


// ── Gate 3: the ?date= query parameter ───────────────────────────────────
// Optional; default is today in UTC (gmdate = date() in UTC).
$date = $_GET['date'] ?? gmdate('Y-m-d');

// Round-trip validation trick: createFromFormat alone is too forgiving —
// it happily accepts "2026-02-31" and silently rolls it over to March 3rd.
// So: parse, format BACK, and demand the result equals the input.
// Only real calendar dates survive that round trip.
//
// The "!" in '!Y-m-d' means "zero out every field the format doesn't
// mention" — hours/minutes/seconds become 00:00:00, giving us exactly
// midnight UTC, which doubles as our day start.
$dayStart = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
if ($dayStart === false || $dayStart->format('Y-m-d') !== $date) {
    respond(400, ['error' => 'date must have the form YYYY-MM-DD.']);
}
//print($dayStart->format('Y-m-d H:i:s'));
//var_dump($dayStart);

// ── Gate 4: which meter? ─────────────────────────────────────────────────
// The response declares ONE meter_id, so one meter per request.
// Default = our only meter, purely for convenience while there's one.
$meterId = $_GET['meter_id'] ?? 'mock-meter-001';


// The meters table is the allowlist for reads too. Asking for an
// unregistered meter = asking for a resource that doesn't exist -> 404.
// (Compare ingest's 422 for the same situation: there the request was
// "please STORE this" — understood but unprocessable. Here it's "please
// SHOW me X" and there is no X. Different verbs, different semantics.)
//
// prepare/execute with a placeholder even for this tiny query: the
// meter_id came from the URL — user input NEVER gets glued into SQL.
$stmt = db()->prepare('SELECT meter_id FROM meters WHERE meter_id = :meter_id');
$stmt->execute([':meter_id' => $meterId]);
if ($stmt->fetch() === false) {           // no row -> fetch() returns false
    respond(404, ['error' => "Unknown meter_id '{$meterId}'."]);
}

// ── Fetch the day's slots ────────────────────────────────────────────────
// The day is a HALF-OPEN interval: [ dayStart, dayStart + 1 day )
//   window_start_utc >= 00:00 of the day    (>=  : midnight included)
//   window_start_utc <  00:00 of NEXT day   (<   : next midnight excluded)
//
// Why "< nextDay" and not "<= 23:59:59"? Because between 23:59:59 and
// 00:00:00 there is an infinity of moments (23:59:59.5 ...) that a
// closed interval silently loses. Half-open intervals mean every moment
// belongs to EXACTLY one day: no gaps, no double counting. Our windows
// already work this way (10:00–10:15, then 10:15–10:30: the instant
// 10:15 belongs to the second window).
//
// P1D = ISO 8601 duration: P(eriod) 1 D(ay). Immutable ->add() returns
// a NEW object; $dayStart itself is untouched.
$dayEnd = $dayStart->add(new DateInterval('P1D'));

$stmt = db()->prepare(
    'SELECT window_start_utc, window_end_utc, energy_delta_kwh
     FROM meter_aggregates
     WHERE meter_id = :meter_id
       AND window_start_utc >= :day_start
       AND window_start_utc <  :day_end
     ORDER BY window_start_utc'   // chronological — the contract's order,
                                  // decided by the SERVER, not left to
                                  // whatever the storage engine returns
);
$stmt->execute([
    // The DB stores "Y-m-d H:i:s" in UTC (our convention, decision #6),
    // so we format the boundaries the same way. Because the format is
    // fixed-width with big units first (year..second), STRING comparison
    // in SQL equals TIME comparison — same trick as in ingest validation.
    ':meter_id'  => $meterId,
    ':day_start' => $dayStart->format('Y-m-d H:i:s'),
    ':day_end'   => $dayEnd->format('Y-m-d H:i:s'),
]);


// ── Convert DB rows to contract slots ────────────────────────────────────
// Boundary conversion, outbound direction: storage format -> wire format.
// (format_utc_atom() lives in api-helper.php, right next to its inbound
// twin parse_utc_datetime() — same boundary, opposite arrows.)
$slots = [];
foreach ($stmt->fetchAll() as $row) {
    $slots[] = [
        // "2026-07-12 10:00:00" -> "2026-07-12T10:00:00+00:00"
        // ISO 8601 with explicit offset: self-describing, no client ever
        // guesses a timezone. Same style as ESIT.
        'start_timestamp' => format_utc_atom($row['window_start_utc']),
        'end_timestamp'   => format_utc_atom($row['window_end_utc']),

        // PDO hands DECIMAL columns to PHP as STRINGS ("0.100000") —
        // deliberately, so exactness survives the trip (a float can't
        // hold every decimal). We cast to float at the LAST moment so
        // json_encode emits a number (0.1), not a quoted string.
        'value'           => (float) $row['energy_delta_kwh'],

        // These are MEASURED ENERGY amounts -> kWh. Not CHF/kWh: prices
        // don't exist yet — that's the future price engine's endpoint.
        // Honest units beat symmetry with ESIT.
        'unit'            => 'kWh',
    ];
}

// ── The response ─────────────────────────────────────────────────────────
respond(200, [
    'meter_id'           => $meterId,
    'date'               => $date,
    'resolution_minutes' => 15,
    //'unit'  			 => 'CHF/kWh',
    'unit'  			 => 'kWh',

    // "slots" stays an ARRAY even with one element (note the double [[ ):
    // the contract says "a list of slots", and clients written against a
    // [] on a day with no data — and that's a 200, not an error:
    // "nothing measured that day" is a valid, complete answer to a
    // well-formed question. Clients iterate it without special cases.
    'slots'              => $slots,
]);
