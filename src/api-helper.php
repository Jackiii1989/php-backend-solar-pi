<?php

// Strict type checking 
declare(strict_types=1);

/**
 * Send a JSON response and stop. Every exit path of every endpoint
 * goes through here, so clients always get the same shape.
 */
function respond(int $status, array $body): never
{
	
	// Sets the STATUS LINE of the HTTP response — the very first line the
    // client sees, e.g. "HTTP/1.1 401 Unauthorized". This is the
    // machine-readable verdict that clients (and curl -i) branch on
    http_response_code($status);
	
	// Response header declaring what the body's bytes ARE. Without it PHP
    // defaults to text/html and clients have to guess.
    // HTTP ordering rule: status line + headers travel BEFORE the body,
    // so header() must be called before any echo.
    header('Content-Type: application/json');
	
    // json_encode converts the PHP array to a JSON string:
    //   ['error' => 'x']  ─►  {"error":"x"}
    // echo writes it into the response body. This is the moment the
    // internal PHP world is serialized for the outside world.
    echo json_encode($body);
    exit;
}

/**
 * Stop the request with 401 unless the given Authorization header
 * matches the expected Bearer token (constant-time comparison).
 */
function require_bearer_token(string $authHeader, string $expectedToken): void
{
	// Build the full expected header value ("Bearer abc123...") and compare
    // against what the client sent — in CONSTANT TIME.
    //
    // Why hash_equals and not === : a normal comparison stops at the first
    // wrong byte, so rejecting "Axxx" is measurably faster than rejecting
    // the almost-correct token. An attacker with a stopwatch can crack the
    // token byte by byte. hash_equals always compares every byte; timing
    // reveals nothing.
    //
    // Bonus of comparing the ENTIRE header instead of extracting the token:
    // a missing header arrives here as '' and fails the same comparison —
    // one condition covers "absent", "malformed" and "wrong".
    if (!hash_equals('Bearer ' . $expectedToken, $authHeader)) {
		// 401 = HTTP's "who are you?". The message deliberately does NOT
        // distinguish missing from invalid — that would confirm to an
        // attacker that their header format is already right.
        respond(401, ['error' => 'Missing or invalid token.']);
    }
}


/**
 * Stop the request with 405 unless the HTTP method matches.
 *
 * Call this FIRST in every endpoint: it's the cheapest gate (one string
 * comparison, no secrets, no body parsing), so wrong requests leave
 * early and nothing later has to wonder "but what if this was a GET?".
 */ 
function require_method(string $method): void
{
    // $_SERVER['REQUEST_METHOD'] = the verb from the request's first line:
    //   "POST /ingest.php HTTP/1.1"  ->  "POST"
    // The client chooses it (curl -X, the Pi's requests library, a browser
    // typing a URL always sends GET). Like all client input: check, don't trust.
    //
    // "?? ''" — under a real web server the key always exists, but PHP can
    // also run this file from the command line (php -r, unit tests), where
    // there IS no HTTP request. The fallback '' simply fails the comparison,
    // so the CLI case degrades into a clean 405 instead of a PHP warning.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {

        // The Allow header is REQUIRED by the HTTP spec (RFC 9110) on every
        // 405 response: when you refuse a verb, you must state which verbs
        // you WOULD accept, e.g.  "Allow: POST".
        //
        // Why bother? So the client can fix itself without documentation:
        //   curl -i http://.../ingest.php        (a GET)
        //   ...
        //   HTTP/1.1 405 Method Not Allowed
        //   Allow: POST                          <- "aha, resend as POST"
        //
        // header() appends one raw response-header line. It must run before
        // any body output — headers and body travel in strict order.
        // (respond() below also calls header(); that's fine, each call
        // adds/replaces its own header line.)
        header('Allow: ' . $method);

        // 405 = "the URL exists, but not with this verb". Distinct from
        // 404 ("no such URL") and 401 ("who are you?") — precise status
        // codes are the API being honest about WHICH rule was broken.
        respond(405, ['error' => "Method not allowed. Use {$method}."]);
    }

    // Matching method: fall through and return void — the endpoint
    // continues. Gates only interrupt on failure.
}


/**
 * Read and decode the JSON request body, or stop with 400.
 *
 * Call this only AFTER the auth gate: parsing work is done for
 * authenticated callers only — no free CPU for strangers.
 */
function read_json_body(): array
{
    // php://input is a read-only pseudo-file containing the RAW bytes of
    // the request body — exactly what the client sent after the headers.
    //
    // Why not $_POST? $_POST is only filled when the body is in one of the
    // two HTML-form encodings (application/x-www-form-urlencoded or
    // multipart/form-data). Our Pi sends application/json, which PHP does
    // NOT parse automatically — for JSON, $_POST stays empty and the truth
    // lives in php://input.
    $raw = file_get_contents('php://input');

    // json_decode turns the JSON text into PHP values.
    // The second argument `true` means: decode JSON objects {...} into
    // associative arrays  ['key' => ...]  instead of stdClass objects
    // ($payload['meter_id'] rather than $payload->meter_id).
    $payload = json_decode($raw, true);

    // One check, three failure modes:
    //  1. invalid JSON        -> json_decode returns null
    //  2. empty body          -> null as well
    //  3. VALID JSON that is not an object -> e.g. the body "42" decodes
    //     to int(42), "\"hi\"" to a string, "true" to bool — all legal
    //     JSON, all useless to us. is_array() rejects every case that
    //     isn't a {...} object in one go.
    //
    // Edge note: the body "null" also decodes to null — indistinguishable
    // from a parse error. We don't care: either way it's not the object
    // the contract demands, and 400 is the right answer.
    if (!is_array($payload)) {
        respond(400, ['error' => 'Request body must be a JSON object.']);
    }

    // From here on the endpoint works with a plain PHP array and never
    // thinks about HTTP transport again — that's the whole point of the
    // helper: convert "wire format" to "language values" at the boundary.
    return $payload;
} 


/**
 * Outbound twin of parse_utc_datetime():
 * storage format -> wire format.
 *
 * "2026-07-12 10:00:00" (MySQL DATETIME, UTC by our convention)
 *   -> "2026-07-12T10:00:00+00:00" (ISO 8601 / ATOM).
 *
 * The DATETIME column stores no timezone — the "UTC" is OUR rule
 * (decision #6), which is why we must pin new DateTimeZone('UTC') here:
 * without it PHP would assume the server's local timezone and shift
 * every timestamp on a non-UTC machine.
 */
function format_utc_atom(string $mysqlDatetime): string
{
    return DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $mysqlDatetime,
        new DateTimeZone('UTC')
    )->format(DateTimeInterface::ATOM);
}