<?php

/**
 * Ingest endpoint — receives one 15-minute aggregate per POST from the Pi.
 * Contract: 201 stored / 200 already stored (retry) / 401 bad token /
 * 405 wrong method / 400 bad payload / 422 unknown meter.
 */

declare(strict_types=1);


// includes
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/api-helper.php';


// ── The gauntlet: cheapest gate first, body parsed only after auth ──────
require_method('POST');
require_bearer_token($_SERVER['HTTP_AUTHORIZATION'] ?? '', config('API_TOKEN'));
$payload = read_json_body();


// Collect ALL problems, then answer once — a client fixing its payload
// wants the full list, not one complaint per round trip.
$errors = [];



// ── Validation ───────────────────────────────────────────────────────────

$meterId = $payload['meter_id'] ?? null;
if (!is_string($meterId) || $meterId === '' || strlen($meterId) > 100) {
    $errors[] = 'meter_id must be a non-empty string (max 100 chars).';
}


/**
 * Validate an ISO 8601 timestamp and convert it to UTC for storage.
 *
 * Returns "YYYY-MM-DD HH:MM:SS" (what MySQL DATETIME wants), or null
 * if the value is not a valid timestamp.
 *
 * Accepts any UTC-offset form ("...T10:00:00+00:00", "...+02:00", "...Z")
 * and NORMALIZES to UTC — this is decision #6, "everything in the DB is
 * UTC", enforced at the boundary. The Pi should send UTC, but if it ever
 * sends +02:00 by accident, we store the correct moment anyway.
 */
function parse_utc_datetime(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    // "Z" is ISO 8601 shorthand for +00:00; normalize so one format
    // string handles both spellings.
    $normalized = str_replace('Z', '+00:00', $value);

    // ATOM = "Y-m-d\TH:i:sP", e.g. 2026-07-12T10:00:00+00:00.
    // Same round-trip trick as api-v1's date check: parse, format back,
    // demand equality — otherwise createFromFormat "helpfully" accepts
    // garbage like month 13 by rolling it over.
    $dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $normalized);
    if ($dt === false || $dt->format(DateTimeInterface::ATOM) !== $normalized) {
        return null;
    }

    // Convert whatever offset arrived into UTC, then drop the offset —
    // the column is DATETIME and the _utc suffix documents the convention.
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}


$windowStart = parse_utc_datetime($payload['window_start_utc'] ?? null);
if ($windowStart === null) {
    $errors[] = 'window_start_utc must be an ISO 8601 timestamp (e.g. 2026-07-12T10:00:00+00:00).';
}

$windowEnd = parse_utc_datetime($payload['window_end_utc'] ?? null);
if ($windowEnd === null) {
    $errors[] = 'window_end_utc must be an ISO 8601 timestamp.';
}

// Both timestamps are now "YYYY-MM-DD HH:MM:SS" in UTC, so plain string
// comparison IS chronological comparison — that's why the format is safe
// to compare with >=.
if ($windowStart !== null && $windowEnd !== null && $windowStart >= $windowEnd) {
    $errors[] = 'window_start_utc must be before window_end_utc.';
}

// JSON numbers arrive as int (0, 1) or float (0.1) depending on how they
// were written — accept both; reject strings ("0.1") and negatives.
$energyDelta = $payload['energy_delta_kwh'] ?? null;
if (!(is_float($energyDelta) || is_int($energyDelta)) || $energyDelta < 0) {
     $errors[] = 'energy_delta_kwh must be a non-negative number.';
} 

if ($errors !== []) {
    respond(400, ['error' => 'Invalid payload.', 'details' => $errors]);
}


// ── Insert — the DB enforces the hard rules, we translate its verdicts ──
// No "does this window already exist?" pre-check: two simultaneous
// retries could both pass such a check (race condition). The UNIQUE KEY
// cannot be raced; we just insert and interpret the outcome.
$sql = 'INSERT INTO meter_aggregates
            (meter_id, window_start_utc, window_end_utc,
             energy_delta_kwh, received_at_utc)
        VALUES
            (:meter_id, :window_start_utc, :window_end_utc,
             :energy_delta_kwh, UTC_TIMESTAMP())';

try {
    // prepare/execute with named placeholders: SQL shape and values
    // travel to MySQL separately — a hostile meter_id is just a string,
    // never executable SQL (this is why EMULATE_PREPARES is off).
    db()->prepare($sql)->execute([
        ':meter_id'         => $meterId,
        ':window_start_utc' => $windowStart,
        ':window_end_utc'   => $windowEnd,
        ':energy_delta_kwh' => $energyDelta,
    ]);
} catch (PDOException $e) {
    // errorInfo = [SQLSTATE, driver-specific errno, message];
    // index 1 is MySQL's own error number — precise, unlike SQLSTATE
    // 23000 which lumps ALL integrity violations together.
    $mysqlErrno = $e->errorInfo[1] ?? null;

    if ($mysqlErrno === 1062) {
        // Duplicate uq_meter_window: the Pi retried a POST whose response
        // got lost. The row it wanted stored IS stored — success, not
        // conflict. 200 (not 201: nothing was created this time).
        respond(200, ['status' => 'already recorded']);
    }

    if ($mysqlErrno === 1452) {
        // FK violation: meter not in the meters allowlist (decision #2:
        // reject, don't auto-register). 422 = "I understood the request,
        // but its content is not processable" — distinct from 400, where
        // the payload itself was malformed.
        respond(422, ['error' => "Unknown meter_id '{$meterId}'. Register it first."]);
    }

    // Anything else is OUR problem, not the client's: log the detail
    // server-side, reveal nothing (no SQL, no schema names) outward.
    error_log('ingest.php insert failed: ' . $e->getMessage());
    respond(500, ['error' => 'Internal server error.']);
} catch (Throwable $e) {
    // Safety net for everything else — including the RuntimeException
    // that db() throws when the connection itself fails. Throwable is
    // the top of PHP's error hierarchy: NOTHING gets past this, so every
    // failure exits through respond() and the JSON contract holds.
    error_log('ingest.php failed: ' . $e->getMessage());
    respond(500, ['error' => 'Internal server error.']);
}

respond(201, ['status' => 'created']);
