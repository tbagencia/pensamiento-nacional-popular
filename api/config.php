<?php
/**
 * Global configuration.
 * Secrets and environment-specific values live in the .env file at the
 * project root (see .env.example). Nothing sensitive belongs in this file.
 */

load_env(__DIR__ . '/../.env');

// Generate a hash with: php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
define('ADMIN_PASSWORD_HASH', env('ADMIN_PASSWORD_HASH', ''));

// When true, the verification link is returned in the API response.
// MUST be false in production.
define('DEV_MODE', filter_var(env('DEV_MODE', 'false'), FILTER_VALIDATE_BOOL));

// Sender shown to users. In production use a mailbox of your own domain.
define('MAIL_FROM', env('MAIL_FROM', 'no-reply@example.com'));
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'Línea de Tiempo del Pensamiento Nacional y Popular'));

// Comma-separated list notified when a resource is ready for moderation.
define('MODERATOR_EMAILS', env('MODERATOR_EMAILS', ''));

// 'smtp' (Mailtrap sandbox in dev) or 'mail' (shared hosting in production).
define('MAIL_DRIVER', env('MAIL_DRIVER', 'mail'));
define('SMTP_HOST', env('SMTP_HOST', ''));
define('SMTP_PORT', (int) env('SMTP_PORT', '2525'));
define('SMTP_USER', env('SMTP_USER', ''));
define('SMTP_PASS', env('SMTP_PASS', ''));

const SITE_NAME = 'Línea de Tiempo del Pensamiento Nacional y Popular Argentino';

// Overridable via env so the test suite can run on a throwaway database.
define('DB_PATH', env('DB_PATH', __DIR__ . '/../data/timeline.sqlite'));

const MIN_YEAR = 1800;

// Max excerpt length in characters. Generous by default so full texts
// (a speech, a letter) fit; tune per environment via .env.
define('EXCERPT_MAX_LENGTH', max(1, (int) env('EXCERPT_MAX_LENGTH', '10000')));

/** Loads KEY=VALUE pairs from a .env file into the process environment. */
function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);
        // Strip surrounding quotes if present.
        if (preg_match('/^(["\']).*\1$/', $value)) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

/** Reads an environment variable with a fallback default. */
function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

/** Valid moderator email addresses from MODERATOR_EMAILS. */
function moderator_emails(): array
{
    return array_values(array_filter(
        array_map('trim', explode(',', MODERATOR_EMAILS)),
        fn(string $email) => filter_var($email, FILTER_VALIDATE_EMAIL)
    ));
}

/** Moderators' own submissions skip moderation and publish directly. */
function is_moderator_email(string $email): bool
{
    return in_array(
        mb_strtolower($email),
        array_map('mb_strtolower', moderator_emails()),
        true
    );
}

/** Base URL of the site, auto-detected. */
function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Project root is one level above /api
    $dir = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return "$scheme://$host$dir";
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
