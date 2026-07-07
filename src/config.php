<?php

/**
 * Configuration loader.
 *
 * Lookup order: real environment variable -> .env file -> error.
 */

# variables are strict declared
declare(strict_types=1);

/**
 * Parse a .env file into an associative array.
 * Minimal on purpose: KEY=VALUE lines, "#" starts a comment,
 * optional quotes around the value.
 */
function load_env_file(string $path): array{

	// check if the file can be read
	if (!is_readable($path)){
		return [];
	}
	
	$values = [];
	# file reads the file at $path line by line where it ignores new lines and skips empty lines
	foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
		$line = trim($line); // remove empty spaces in the front of file
		
		#skip comments and lines without "="
		if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
			continue;
        }
		
		[$key, $value] = explode('=', $line, 2); # explodes splits on "=" in variable $line and split maximal 2 parts. 
		$key = trim($key);
        $value = trim($value);
		
		// Strip surrounding quotes, if any.
        if (strlen($value) >= 2
            && ($value[0] === '"' || $value[0] === "'")
            && $value[strlen($value) - 1] === $value[0]
        ) {
            $value = substr($value, 1, -1);
        }
		
		$values[$key] = $value;
	}
	return $values;
	
}

/**
 * Return one config value, or stop the program if a required key is missing.
 */
function config(string $key, ?string $default = null): string
{
    // "static" = parse the .env file only once, then remember it
    // across every config() call in this request.
	// preserves the value only during the current PHP execution: new api requst, refresh browser, PHP starts a new,
	// then it loads the file again
    static $envFile = null;
	
	# is $envFile exactly null
	if ($envFile === null) {
		$envFile = load_env_file(__DIR__ . '/../.env');
    }
	
	# get the env file if exist
	$value = getenv($key);

    if ($value !== false && $value !== '') {
		# if yes, return the value
        return $value;
    }
	// if not, get it from the local .env
    if (isset($envFile[$key]) && $envFile[$key] !== '') {
        return $envFile[$key];
    }
	// otherwise use the default value, if it is not null
    if ($default !== null) {
        return $default;
    }

	// last branch fail. 
    // Fail fast, but never echo secrets or key lists to the browser.
    http_response_code(500);
    error_log("Missing required config key: {$key}");
    exit('Server misconfigured.');
}
?>
