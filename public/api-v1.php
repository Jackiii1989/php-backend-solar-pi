<?php

declare(strict_types=1);


// __DIR__ = the folder THIS file lives in (public/), so the paths work no
// matter where the web server was started from. ../src is OUTSIDE the web
// root: helpers and config can never be requested directly by a browser.
//require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/api-helper.php';


// ── Gate 1: only GET may pass ────────────────────────────────────────────
// This endpoint READS data; GET is the HTTP verb for reading.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    // The Allow header is part of the 405 contract: tell the client
    // which verbs WOULD be accepted.
    header('Allow: GET');
    respond(405, ['error' => 'Method not allowed. Use GET.']);
}

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


// ── Mock data: one 15-minute slot at the start of the requested day ──────
// 'PT15M' = ISO 8601 duration: P(eriod), T(ime part), 15 M(inutes).
// ->add() returns a NEW object — the "Immutable" in DateTimeImmutable.
$slotStart = $dayStart;
$slotEnd   = $dayStart->add(new DateInterval('PT15M'));

// ── The response: our API contract (ESIT-style price slots) ──────────────
// "source":"mock" flips to "database" once real data feeds this endpoint.

// Metadata before data: a client should never have to GUESS units or
// resolution when the server can simply state them.
// "source":"mock" is an honest flag — it flips to "database" in step 4,
// and you can always tell at a glance which world you're looking at.
respond(200, [
    'meter_id'           => 'mock-meter-001',
    'date'               => $date,
    'resolution_minutes' => 15,
    'unit'  			 => 'CHF/kWh',
    'source'             => 'mock',

    // "slots" stays an ARRAY even with one element (note the double [[ ):
    // the contract says "a list of slots", and clients written against a
    // list keep working unchanged when 96 slots arrive in step 4.
    'slots'              => [
        [
            // DateTimeInterface::ATOM = PHP's constant for ISO 8601 with
            // offset: "2026-07-08T00:00:00+00:00". Self-describing — no
            // client ever has to guess the timezone. Same style as ESIT.
            'start_timestamp' => $slotStart->format(DateTimeInterface::ATOM),
            'end_timestamp'   => $slotEnd->format(DateTimeInterface::ATOM),

           // A PRICE, not an energy amount: what one kWh costs in this
            // 15-minute window. 0.15 CHF/kWh = 15 Rp./kWh — inside EGA's
            // 1–20 Rp. band, so the mock is realistic.
            // The unit lives INSIDE the slot (like ESIT) so that future
            // price components can each carry their own unit.
            'value' => 0.15,
            
        ],
    ],
]);

?>