<?php
/**
 * Zero-dependency integration test suite.
 *
 * Boots the app on a local test server with a throwaway SQLite database
 * and exercises the full circuit over real HTTP:
 * submission -> email validation -> session skip -> moderation.
 *
 * Run: php tests/integration.php
 * Exit code 0 = all green, 1 = failures.
 */

const TEST_HOST = '127.0.0.1:8765';
const TEST_URL = 'http://' . TEST_HOST;
const ADMIN_PASSWORD = 'testpass';
const ADMIN_PATH = 'slug-de-prueba';
const ADMIN_URL = '/panel/' . ADMIN_PATH;

$root = dirname(__DIR__);
$dbPath = sys_get_temp_dir() . '/timeline-test-' . getmypid() . '.sqlite';
$cookieDir = sys_get_temp_dir() . '/timeline-test-cookies-' . getmypid();
mkdir($cookieDir);

/* ---------- Test server lifecycle ---------- */

$server = proc_open(
    ['php', '-S', TEST_HOST, 'router.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    $root,
    array_merge(getenv(), [
        'DB_PATH' => $dbPath,
        'DEV_MODE' => 'true',
        'MAIL_DRIVER' => 'smtp',   // with empty credentials: fails fast, sends nothing
        'SMTP_USER' => '',
        'SMTP_PASS' => '',
        'MODERATOR_EMAILS' => 'mod@test.local',
        'ADMIN_PASSWORD_HASH' => password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT),
        'ADMIN_PATH' => ADMIN_PATH,
    ])
);

register_shutdown_function(function () use ($server, $dbPath, $cookieDir) {
    proc_terminate($server);
    @unlink($dbPath);
    array_map('unlink', glob("$cookieDir/*") ?: []);
    @rmdir($cookieDir);
});

// Wait for the server to accept connections.
$ready = false;
for ($i = 0; $i < 50; $i++) {
    if (@file_get_contents(TEST_URL . '/api/resources.php') !== false) {
        $ready = true;
        break;
    }
    usleep(100_000);
}
if (!$ready) {
    fwrite(STDERR, "Test server did not start\n");
    exit(1);
}

/* ---------- Helpers ---------- */

$passed = 0;
$failed = 0;

function check(bool $condition, string $label, mixed $context = null): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n";
        if ($context !== null) {
            echo '    context: ' . json_encode($context, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
}

function section(string $name): void
{
    echo "\n$name\n";
}

/** HTTP request with optional JSON/form body and a named cookie jar. */
function request(string $method, string $path, array $opts = []): array
{
    global $cookieDir;
    $ch = curl_init(str_starts_with($path, 'http') ? $path : TEST_URL . $path);
    $headers = $opts['headers'] ?? [];

    if (isset($opts['json'])) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
    } elseif (isset($opts['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
    }
    if (isset($opts['cookies'])) {
        $jar = "$cookieDir/{$opts['cookies']}.txt";
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = (string) curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true)];
}

function db(): PDO
{
    global $dbPath;
    return new PDO('sqlite:' . $dbPath);
}

function submission(array $overrides = []): array
{
    return array_merge([
        'title' => 'Recurso de prueba',
        'author' => 'Autora de Prueba',
        'year' => 1950,
        'type' => 'texto',
        'excerpt' => 'Extracto de prueba para la suite de integración.',
        'source_url' => '',
        'email' => 'visitante@test.local',
    ], $overrides);
}

/* ---------- Tests ---------- */

section('Public API');
$res = request('GET', '/api/resources.php');
check($res['status'] === 200, 'resources API responds 200');
check(count($res['json']['resources'] ?? []) === 33, 'fresh database is seeded with 33 entries', $res['json'] ?? $res['body']);

section('Friendly URLs');
check(request('GET', '/')['status'] === 200, 'home responds');
check(request('GET', '/linea/1945')['status'] === 200, 'year URL responds');
check(request('GET', '/cargar/1945')['status'] === 200, 'prefilled form URL responds');
check(request('GET', '/linea/abcd')['status'] === 404, 'invalid year URL is 404');
check(request('GET', '/linea/')['status'] === 302, 'year-less /linea redirects home');
check(request('GET', '/tipo/discurso')['status'] === 200, 'type filter URL responds');
check(request('GET', '/tipo/zarzuela')['status'] === 302, 'unknown type redirects home');

section('Document page (SEO)');
$res = request('GET', '/documento/1');
check($res['status'] === 200, 'document page responds 200');
check(str_contains($res['body'], 'og:title'), 'page carries Open Graph tags');
check(str_contains($res['body'], 'Plan de Operaciones'), 'page title quotes the document', $res['body']);
check(str_contains($res['body'], 'rel="canonical"'), 'page declares its canonical URL');
check(
    !str_contains($res['body'], 'location.replace') && !str_contains($res['body'], 'http-equiv="refresh"'),
    'page no longer redirects browsers away (indexable)'
);
check(str_contains($res['body'], 'articleBody'), 'structured data carries the article body');
$excerpt = (string) db()->query('SELECT excerpt FROM resources WHERE id = 1')->fetchColumn();
$fragment = htmlspecialchars(mb_substr($excerpt, 0, 60), ENT_QUOTES, 'UTF-8');
check(str_contains($res['body'], $fragment), 'full text is server-rendered in the body', $fragment);
check(str_contains($res['body'], '/linea/1810#doc-1'), 'page links back to its spot on the timeline');
check(str_contains($res['body'], 'doc-neighbor'), 'page links its neighbouring documents');
check(str_contains($res['body'], 'id="doc-data"'), 'share payload is inlined for the share menu');
check(request('GET', '/documento/99999')['status'] === 302, 'unknown document redirects home');

section('Sitemap');
$res = request('GET', '/sitemap.xml');
check($res['status'] === 200, 'sitemap responds 200');
check(str_contains($res['body'], '/documento/1</loc>'), 'sitemap lists approved documents');
check(str_contains($res['body'], '<lastmod>'), 'entries carry lastmod dates');
check(!str_contains($res['body'], '/cargar'), 'form page is left out of the sitemap');

section('Submission validation');
$res = request('POST', '/api/submit.php', ['json' => ['title' => '', 'email' => 'nope'], 'cookies' => 'visitor']);
check($res['status'] === 422, 'invalid submission is rejected with 422');
check(isset($res['json']['errors']['title'], $res['json']['errors']['email']), 'field errors are reported');

$res = request('POST', '/api/submit.php', [
    'json' => submission(['excerpt' => str_repeat('a', 10001)]),
    'cookies' => 'visitor',
]);
check($res['status'] === 422 && isset($res['json']['errors']['excerpt']), 'excerpt over the configured limit is rejected');

$res = request('POST', '/api/submit.php', [
    'json' => submission(['excerpt' => str_repeat('b', 2500), 'email' => 'extracto@test.local']),
    'cookies' => 'visitor',
]);
check($res['status'] === 201, 'long excerpt within the configured limit is accepted', $res['json'] ?? $res['body']);

$res = request('POST', '/api/submit.php', ['json' => submission(['website' => 'spam-bot']), 'cookies' => 'visitor']);
check($res['status'] === 200 && ($res['json']['ok'] ?? false), 'honeypot pretends success');
$count = db()->query("SELECT COUNT(*) FROM resources WHERE submitter_email = 'visitante@test.local'")->fetchColumn();
check((int) $count === 0, 'honeypot stores nothing');

section('Email validation circuit');
$res = request('POST', '/api/submit.php', ['json' => submission(), 'cookies' => 'visitor']);
check($res['status'] === 201 && ($res['json']['ok'] ?? false), 'valid submission accepted');
$verifyUrl = $res['json']['verify_url'] ?? null;
check(is_string($verifyUrl), 'DEV_MODE exposes the verification link', $res['json']);
$status = db()->query("SELECT status FROM resources WHERE submitter_email = 'visitante@test.local'")->fetchColumn();
check($status === 'pending_email', 'new resource awaits email validation');

$res = request('GET', $verifyUrl, ['cookies' => 'visitor']);
check(str_contains($res['body'], 'Email validado'), 'verification page confirms');
$status = db()->query("SELECT status FROM resources WHERE submitter_email = 'visitante@test.local'")->fetchColumn();
check($status === 'pending_review', 'validated resource moves to moderation');

$res = request('GET', $verifyUrl, ['cookies' => 'visitor']);
check(str_contains($res['body'], 'ya fue validado'), 'second visit reports already validated');
$res = request('GET', TEST_URL . '/validar/' . str_repeat('0', 64), ['cookies' => 'visitor']);
check(str_contains($res['body'], 'no válido'), 'unknown token reports invalid link');

section('Session skips re-validation');
$res = request('GET', '/api/session.php', ['cookies' => 'visitor']);
check(($res['json']['verified_email'] ?? null) === 'visitante@test.local', 'session remembers the verified email');

$res = request('POST', '/api/submit.php', [
    'json' => submission(['title' => 'Segundo aporte misma sesión']),
    'cookies' => 'visitor',
]);
check($res['status'] === 201 && ($res['json']['already_verified'] ?? false), 'same verified email skips validation', $res['json']);
check(!isset($res['json']['verify_url']), 'no verification link is sent again');
$status = db()->query("SELECT status FROM resources WHERE title = 'Segundo aporte misma sesión'")->fetchColumn();
check($status === 'pending_review', 'skipped submission goes straight to moderation');

$res = request('POST', '/api/submit.php', [
    'json' => submission(['title' => 'Otro email en la misma sesión', 'email' => 'otra@test.local']),
    'cookies' => 'visitor',
]);
check($res['status'] === 201 && !($res['json']['already_verified'] ?? false), 'different email still requires validation');
$status = db()->query("SELECT status FROM resources WHERE submitter_email = 'otra@test.local'")->fetchColumn();
check($status === 'pending_email', 'different email starts at pending_email');

$res = request('GET', '/api/session.php', ['cookies' => 'fresh-visitor']);
check(
    array_key_exists('verified_email', $res['json'] ?? []) && $res['json']['verified_email'] === null,
    'a fresh session has no verified email',
    $res['json']
);

section('Moderator submissions skip moderation');
$res = request('POST', '/api/submit.php', [
    'json' => submission(['title' => 'Aporte de moderador', 'email' => 'mod@test.local']),
    'cookies' => 'moderator',
]);
$verifyUrl = $res['json']['verify_url'] ?? '';
check(is_string($verifyUrl) && $verifyUrl !== '', 'moderator still validates the email first', $res['json']);
$res = request('GET', $verifyUrl, ['cookies' => 'moderator']);
check(str_contains($res['body'], 'Documento publicado'), 'verification page reports direct publication');
$status = db()->query("SELECT status FROM resources WHERE title = 'Aporte de moderador'")->fetchColumn();
check($status === 'approved', 'validated moderator submission is published');

$res = request('POST', '/api/submit.php', [
    'json' => submission(['title' => 'Segundo aporte de moderador', 'email' => 'mod@test.local']),
    'cookies' => 'moderator',
]);
check(($res['json']['published'] ?? false) === true, 'moderator session publishes directly', $res['json']);
$status = db()->query("SELECT status FROM resources WHERE title = 'Segundo aporte de moderador'")->fetchColumn();
check($status === 'approved', 'session-skip moderator submission is published');

section('Daily rate limit');
for ($i = 1; $i <= 5; $i++) {
    request('POST', '/api/submit.php', ['json' => submission(['title' => "Carga $i", 'email' => 'limite@test.local'])]);
}
$res = request('POST', '/api/submit.php', ['json' => submission(['title' => 'Carga 6', 'email' => 'limite@test.local'])]);
check($res['status'] === 429, 'sixth submission in a day is rejected', $res);

section('Admin path obfuscation');
check(request('GET', '/admin/index.php')['status'] === 404, 'guessable /admin URL is 404 when ADMIN_PATH is set');
check(request('GET', '/panel/slug-equivocado')['status'] === 404, 'wrong panel slug is 404');
check(request('GET', ADMIN_URL)['status'] === 200, 'secret panel URL responds');

section('Admin moderation');
$res = request('POST', ADMIN_URL, ['form' => ['action' => 'login', 'password' => 'wrong'], 'cookies' => 'admin']);
check(str_contains($res['body'], 'Contraseña incorrecta'), 'wrong password is rejected');

$res = request('POST', ADMIN_URL, ['form' => ['action' => 'login', 'password' => ADMIN_PASSWORD], 'cookies' => 'admin']);
check(str_contains($res['body'], 'Moderación'), 'admin logs in');
preg_match('/name="csrf" value="([a-f0-9]{32})"/', $res['body'], $m);
$csrf = $m[1] ?? '';
check($csrf !== '', 'CSRF token is present');

$id = (int) db()->query("SELECT id FROM resources WHERE title = 'Segundo aporte misma sesión'")->fetchColumn();
$res = request('POST', ADMIN_URL, [
    'form' => ['action' => 'approve', 'id' => $id, 'csrf' => $csrf],
    'headers' => ['X-Requested-With: fetch'],
    'cookies' => 'admin',
]);
check(($res['json']['ok'] ?? false) && isset($res['json']['counts']), 'AJAX approve returns ok with counts', $res['json']);
$status = db()->query("SELECT status FROM resources WHERE id = $id")->fetchColumn();
check($status === 'approved', 'resource is approved');

$res = request('POST', ADMIN_URL, [
    'form' => ['action' => 'approve', 'id' => $id, 'csrf' => 'bogus'],
    'headers' => ['X-Requested-With: fetch'],
    'cookies' => 'admin',
]);
check($res['status'] === 403, 'invalid CSRF is rejected with 403');

section('Admin edit and reject feedback');
$res = request('POST', ADMIN_URL, [
    'form' => [
        'action' => 'edit', 'id' => $id, 'csrf' => $csrf,
        'title' => '', 'author' => 'Autora', 'year' => 1950, 'type' => 'carta', 'excerpt' => 'x',
    ],
    'headers' => ['X-Requested-With: fetch'],
    'cookies' => 'admin',
]);
check($res['status'] === 422 && isset($res['json']['errors']['title']), 'edit with empty title returns 422', $res['json']);

$res = request('POST', ADMIN_URL, [
    'form' => [
        'action' => 'edit', 'id' => $id, 'csrf' => $csrf,
        'title' => 'Título corregido', 'author' => 'Autora Corregida', 'year' => 1950,
        'type' => 'carta', 'excerpt' => 'Extracto corregido por moderación.',
        'source_url' => 'https://example.org/fuente',
    ],
    'headers' => ['X-Requested-With: fetch'],
    'cookies' => 'admin',
]);
check(
    ($res['json']['ok'] ?? false) && ($res['json']['resource']['title'] ?? '') === 'Título corregido',
    'AJAX edit returns ok with the updated resource',
    $res['json']
);
$row = db()->query("SELECT title, author, year, type FROM resources WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
check(
    $row['title'] === 'Título corregido' && (int) $row['year'] === 1950 && $row['type'] === 'carta',
    'edited fields are persisted',
    $row
);

$res = request('POST', ADMIN_URL, [
    'form' => ['action' => 'reject', 'id' => $id, 'csrf' => $csrf, 'reason' => 'Duplicado de un documento ya publicado.'],
    'headers' => ['X-Requested-With: fetch'],
    'cookies' => 'admin',
]);
check(($res['json']['ok'] ?? false), 'AJAX reject with reason returns ok', $res['json']);
$status = db()->query("SELECT status FROM resources WHERE id = $id")->fetchColumn();
check($status === 'rejected', 'resource is rejected');

$res = request('POST', ADMIN_URL, [
    'form' => ['action' => 'delete', 'id' => $id, 'csrf' => $csrf],
    'headers' => ['X-Requested-With: fetch'],
    'cookies' => 'admin',
]);
check(($res['json']['ok'] ?? false), 'AJAX delete returns ok');
$count = db()->query("SELECT COUNT(*) FROM resources WHERE id = $id")->fetchColumn();
check((int) $count === 0, 'deleted resource is gone');

/* ---------- Summary ---------- */

echo "\n" . str_repeat('-', 40) . "\n";
echo "$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
