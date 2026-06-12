<?php
/**
 * Public submission endpoint.
 * Creates a resource in 'pending_email' status and sends a verification
 * email with a VALIDAR button. Clicking it moves the resource to
 * 'pending_review' so a moderator can approve or reject it.
 */

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// Honeypot: bots fill every field; real users never see this one.
if (!empty($input['website'])) {
    json_response(['ok' => true]); // pretend success, store nothing
}

$title = trim($input['title'] ?? '');
$author = trim($input['author'] ?? '');
$year = (int) ($input['year'] ?? 0);
$type = in_array($input['type'] ?? '', VALID_TYPES, true) ? $input['type'] : 'texto';
$excerpt = trim($input['excerpt'] ?? '');
$sourceUrl = trim($input['source_url'] ?? '');
$email = trim($input['email'] ?? '');

$errors = [];
if ($title === '' || mb_strlen($title) > 200) {
    $errors['title'] = 'El título es obligatorio (máximo 200 caracteres).';
}
if ($author === '' || mb_strlen($author) > 120) {
    $errors['author'] = 'El autor es obligatorio (máximo 120 caracteres).';
}
$maxYear = (int) date('Y');
if ($year < MIN_YEAR || $year > $maxYear) {
    $errors['year'] = "El año debe estar entre " . MIN_YEAR . " y $maxYear.";
}
if ($excerpt === '' || mb_strlen($excerpt) > EXCERPT_MAX_LENGTH) {
    $errors['excerpt'] = 'La descripción o extracto es obligatoria (máximo '
        . number_format(EXCERPT_MAX_LENGTH, 0, ',', '.') . ' caracteres).';
}
if ($sourceUrl !== '' && !filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
    $errors['source_url'] = 'La URL de la fuente no es válida.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Ingrese un email válido.';
}
if ($errors) {
    json_response(['errors' => $errors], 422);
}

$pdo = db();

// Basic abuse limit: max 5 submissions per email per day.
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM resources
     WHERE submitter_email = ? AND created_at > datetime('now', '-1 day')"
);
$stmt->execute([$email]);
if ((int) $stmt->fetchColumn() >= 5) {
    json_response(['errors' => ['email' => 'Se alcanzó el límite de cargas diarias para este email.']], 429);
}

// An email already verified in this session skips re-validation:
// the resource goes straight to moderation, no link is sent.
// A moderator's own submission publishes directly, with no notification.
if ($email === ($_SESSION['verified_email'] ?? null)) {
    $isModerator = is_moderator_email($email);
    $stmt = $pdo->prepare(
        "INSERT INTO resources (title, author, year, type, excerpt, source_url, submitter_email, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $title, $author, $year, $type, $excerpt, $sourceUrl ?: null, $email,
        $isModerator ? 'approved' : 'pending_review',
    ]);
    set_resource_authors($pdo, (int) $pdo->lastInsertId(), $author);
    if (!$isModerator) {
        notify_moderators(['title' => $title, 'author' => $author, 'year' => $year]);
    }
    json_response(['ok' => true, 'already_verified' => true, 'published' => $isModerator], 201);
}

$token = bin2hex(random_bytes(32));

$stmt = $pdo->prepare(
    "INSERT INTO resources (title, author, year, type, excerpt, source_url, submitter_email, status, verify_token)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'pending_email', ?)"
);
$stmt->execute([$title, $author, $year, $type, $excerpt, $sourceUrl ?: null, $email, $token]);
set_resource_authors($pdo, (int) $pdo->lastInsertId(), $author);

$verifyUrl = base_url() . '/validar/' . $token;
$mailSent = send_verification_email($email, $title, $verifyUrl);

$response = ['ok' => true, 'mail_sent' => $mailSent];
if (DEV_MODE) {
    // mail() returning true only means the message was handed to the local
    // mail system, not that it was delivered. In dev the link is always
    // exposed so the flow can be tested without a working mail server.
    $response['verify_url'] = $verifyUrl;
}
json_response($response, 201);

function send_verification_email(string $to, string $title, string $verifyUrl): bool
{
    return send_email($to, 'Validá tu carga - ' . SITE_NAME, verification_email_html($title, $verifyUrl));
}
