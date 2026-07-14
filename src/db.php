<?php

/**
 * PDO connection helper.
 * All database access goes through db(): one place to change
 * settings if the connection or driver options ever change.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Open (once) and return the shared PDO connection.
 */
function db(): PDO
{
    // Reuse the connection across calls within this request.
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // DSN = "data source name": driver + where + which DB + text encoding.
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        config('DB_HOST', '127.0.0.1'),
        config('DB_NAME')
    );
    try{
        $pdo = new PDO($dsn, config('DB_USER'), config('DB_PASS'), [
            // Errors become exceptions instead of silent false returns,
            // so bugs surface immediately at the line that caused them.
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            // Fetch rows as ['column' => value] arrays by default.
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            // Use the server's real prepared statements, not PHP's
            // client-side emulation of them.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        // Log the real reason (2002 server down, 1045 bad credentials...)
        // — this goes to the LOG, never to the client.
        error_log('db.php connection failed: ' . $e->getMessage());

        // Rethrow WITHOUT the original exception: a PDOException's stack
        // trace contains PDO::__construct's arguments — i.e. THE DATABASE
        // PASSWORD. This fresh RuntimeException's trace starts here,
        // where no secret is an argument, so even display_errors=On
        // cannot leak credentials anymore.
        throw new RuntimeException('Database connection failed.');
    }
    

    return $pdo;
}