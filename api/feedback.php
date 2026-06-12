<?php
/**
 * Public feedback endpoint.
 * Receives a comment, bug report or suggestion and emails it to the
 * configured moderators. Nothing is stored in the database.
 */

session_start();
require_once __DIR__ . '/mailer.php';

const FEEDBACK_MAX_LENGTH = 3000;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// Honeypot: bots fill every field; real users never see this one.
if (!empty($input['website'])) {
    json_response(['ok' => true]); // pretend success, send nothing
}

$kindLabels = [
    'comentario' => 'Comentario',
    'error' => 'Reporte de error',
    'sugerencia' => 'Sugerencia',
];
$kind = array_key_exists($input['kind'] ?? '', $kindLabels) ? $input['kind'] : 'comentario';
$message = trim($input['message'] ?? '');
$email = trim($input['email'] ?? '');
$page = mb_substr(trim($input['page'] ?? ''), 0, 300);

$errors = [];
if ($message === '' || mb_strlen($message) > FEEDBACK_MAX_LENGTH) {
    $errors['message'] = 'El mensaje es obligatorio (máximo '
        . number_format(FEEDBACK_MAX_LENGTH, 0, ',', '.') . ' caracteres).';
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Ingrese un email válido.';
}
if ($errors) {
    json_response(['errors' => $errors], 422);
}

// Basic abuse limit: a handful of reports per session is plenty.
if (($_SESSION['feedback_count'] ?? 0) >= 5) {
    json_response(['errors' => ['general' => 'Se alcanzó el límite de envíos. Probá de nuevo más tarde.']], 429);
}
$_SESSION['feedback_count'] = ($_SESSION['feedback_count'] ?? 0) + 1;

$mailSent = notify_feedback($kindLabels[$kind], $message, $email, $page);
json_response(['ok' => true, 'mail_sent' => $mailSent], 201);

function notify_feedback(string $kindLabel, string $message, string $email, string $page): bool
{
    $emails = moderator_emails();
    if (!$emails) {
        error_log('[feedback] No moderator emails configured; feedback lost');
        return false;
    }

    $e = fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br($e($message));
    $safeFrom = $email !== '' ? $e($email) : 'No indicado';
    $safePage = $page !== '' ? $e($page) : 'No indicada';
    $subject = $kindLabel . ' desde el sitio - ' . SITE_NAME;

    $body = <<<HTML
    <!DOCTYPE html>
    <html lang="es">
    <body style="font-family: Arial, sans-serif; background: #f4f1ea; padding: 24px;">
      <div style="max-width: 520px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px;">
        <h2 style="color: #1d3557; margin-top: 0;">$kindLabel</h2>
        <p style="background: #f7f3ea; border-radius: 6px; padding: 16px;">$safeMessage</p>
        <p style="color: #666; font-size: 13px;">
          Email de contacto: $safeFrom<br>
          Página: $safePage
        </p>
      </div>
    </body>
    </html>
    HTML;

    $sent = false;
    foreach ($emails as $to) {
        $sent = send_email($to, $subject, $body) || $sent;
    }
    return $sent;
}
