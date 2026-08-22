<?php
/**
 * Saxane Real Estate Management System
 * Environment file (.env) loader
 *
 * Keeps deployment-specific values — database credentials above all — out of
 * the tracked source tree. config/app.php and config/database.php read through
 * env() instead of hard-coding a password that would otherwise be committed,
 * shared and impossible to rotate.
 *
 * No Composer here on purpose: the project has no vendor/ directory, so this
 * is a small dependency-free parser rather than vlucas/phpdotenv.
 *
 * Precedence, highest first:
 *   1. A real environment variable ($_ENV / $_SERVER / getenv)
 *   2. The value in .env
 *   3. The default passed to env()
 *
 * Real environment wins so a production host (cPanel, Docker, systemd) can set
 * DB_PASS in its own panel without a .env file existing at all. The defaults
 * passed by the config files are the XAMPP development values, so a fresh
 * clone with no .env still runs locally.
 *
 * Parsed values are held in a private static store and deliberately NOT pushed
 * into $_ENV or putenv(): putenv() leaks secrets to every child process the
 * app spawns, and anything in $_ENV shows up in a stray var_dump or a badly
 * configured phpinfo() page.
 */

/**
 * Read and parse a .env file. Safe to call repeatedly — the file is read once.
 * A missing file is not an error: the caller's defaults take over.
 */
function env_load(?string $path = null): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }
    $loaded = true;

    $path ??= dirname(__DIR__) . '/.env';

    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        return;
    }

    // Strip a UTF-8 BOM: Notepad and some Windows editors add one, and it
    // would otherwise become part of the very first key name.
    $bom = pack('C*', 0xEF, 0xBB, 0xBF);
    if (str_starts_with($contents, $bom)) {
        $contents = substr($contents, 3);
    }

    // Normalise CRLF and lone CR. The file is edited on Windows and may be
    // deployed to Linux, so line endings cannot be assumed either way.
    $contents = str_replace(["\r\n", "\r"], "\n", $contents);

    foreach (explode("\n", $contents) as $line) {
        $line = trim($line);

        // Blank line or whole-line comment.
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // Tolerate "export KEY=value", which people paste in from shell notes.
        if (str_starts_with($line, 'export ')) {
            $line = ltrim(substr($line, 7));
        }

        $eq = strpos($line, '=');
        if ($eq === false) {
            continue; // Not an assignment; ignore rather than guess.
        }

        $key = rtrim(substr($line, 0, $eq));
        $val = ltrim(substr($line, $eq + 1));

        // Only accept shell-style identifiers. Anything else is a typo, and
        // silently accepting it would produce a key nothing can ever read.
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }

        env_store($key, env_parse_value($val));
    }
}

/**
 * Turn the right-hand side of a KEY=VALUE line into its final string.
 * Handles quoting, escapes inside double quotes, and trailing comments.
 *
 * @internal
 */
function env_parse_value(string $val): string
{
    if ($val === '') {
        return '';
    }

    $quote = $val[0];

    if ($quote === '"' || $quote === "'") {
        // Walk to the closing quote, honouring backslash escapes in "..." only.
        $out = '';
        $len = strlen($val);

        for ($i = 1; $i < $len; $i++) {
            $ch = $val[$i];

            if ($ch === "\\" && $quote === '"' && $i + 1 < $len) {
                $next = $val[$i + 1];
                $out .= match ($next) {
                    'n'     => "\n",
                    'r'     => "\r",
                    't'     => "\t",
                    '"'     => '"',
                    "\\"    => "\\",
                    default => "\\" . $next,
                };
                $i++;
                continue;
            }

            if ($ch === $quote) {
                break; // Closing quote; anything after it is a comment.
            }

            $out .= $ch;
        }

        return $out;
    }

    // Unquoted: a # starts a comment, so "secret # note" is just "secret".
    // This is why any password containing # must be quoted in .env.
    $hash = strpos($val, '#');
    if ($hash !== false) {
        $val = substr($val, 0, $hash);
    }

    return trim($val);
}

/**
 * Read from / write to the private value store.
 * Called with one argument to read, two to write.
 *
 * @internal
 */
function env_store(?string $key = null, ?string $value = null): mixed
{
    static $values = [];

    if ($key === null) {
        return $values;
    }

    if (func_num_args() > 1) {
        $values[$key] = $value;
        return $value;
    }

    return $values[$key] ?? null;
}

/**
 * Fetch a configuration value.
 *
 * The strings "true", "false", "null" and "empty" are converted to their
 * literal meanings, so APP_DEBUG=false behaves as expected rather than
 * arriving as the five-character string "false".
 *
 * @param  mixed $default Returned when the key is set nowhere.
 * @return mixed
 */
function env(string $key, mixed $default = null): mixed
{
    env_load();

    $value = null;

    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        $value = $_ENV[$key];
    } elseif (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        $value = $_SERVER[$key];
    } else {
        $fromShell = getenv($key);
        if ($fromShell !== false && $fromShell !== '') {
            $value = $fromShell;
        } else {
            $value = env_store($key);
        }
    }

    if ($value === null) {
        return $default;
    }

    if (!is_string($value)) {
        return $value;
    }

    return match (strtolower($value)) {
        'true', '(true)'   => true,
        'false', '(false)' => false,
        'null', '(null)'   => null,
        'empty', '(empty)' => '',
        default            => $value,
    };
}

/**
 * Boolean form of env(). Accepts true/false, 1/0, yes/no, on/off.
 */
function env_bool(string $key, bool $default = false): bool
{
    $value = env($key);

    if ($value === null) {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }

    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
}

/**
 * Integer form of env(). Non-numeric values fall back to the default rather
 * than silently becoming 0 — a 0 upload limit would be a hard-to-trace bug.
 */
function env_int(string $key, int $default = 0): int
{
    $value = env($key);

    if ($value === null || !is_numeric($value)) {
        return $default;
    }

    return (int) $value;
}
