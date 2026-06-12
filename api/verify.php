<?php
/** Email verification landing page (target of the VALIDAR button). */

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

$token = $_GET['token'] ?? '';
$state = 'invalid';

// Dev-only: render any state without touching the database
// (see api/preview.php for the index of previews).
$preview = DEV_MODE ? ($_GET['preview'] ?? '') : '';
if (in_array($preview, ['verified', 'published', 'already', 'invalid'], true)) {
    $state = $preview;
} elseif ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT id, status, title, author, year, submitter_email FROM resources WHERE verify_token = ?"
    );
    $stmt->execute([$token]);
    $resource = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($resource) {
        if ($resource['status'] === 'pending_email') {
            // A moderator validating their own submission publishes it
            // directly; nobody needs to moderate the moderator.
            if (is_moderator_email($resource['submitter_email'])) {
                $pdo->prepare("UPDATE resources SET status = 'approved' WHERE id = ?")
                    ->execute([$resource['id']]);
                $state = 'published';
            } else {
                $pdo->prepare("UPDATE resources SET status = 'pending_review' WHERE id = ?")
                    ->execute([$resource['id']]);
                $state = 'verified';
                notify_moderators($resource);
            }
        } else {
            $state = 'already';
        }
        // Remember the verified email: further submissions in this session
        // skip the email-validation step.
        $_SESSION['verified_email'] = $resource['submitter_email'];
    }
}

$messages = [
    'verified' => [
        'Email validado',
        'Tu carga quedó confirmada. Ahora será revisada por un moderador y, si es aprobada, aparecerá en la línea de tiempo.',
        'ok', '✓',
    ],
    'published' => [
        'Documento publicado',
        'Tu email de moderación quedó validado y el documento ya está publicado en la línea de tiempo.',
        'ok', '✓',
    ],
    'already' => [
        'Este aporte ya fue validado',
        'No hace falta validarlo de nuevo. Si fue aprobado, ya está visible en la línea de tiempo.',
        'muted', '✓',
    ],
    'invalid' => [
        'Enlace no válido',
        'El enlace de validación no existe o expiró. Volvé a cargar el documento si el problema persiste.',
        'danger', '✕',
    ],
];
[$heading, $detail, $tone, $mark] = $messages[$state];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title><?= htmlspecialchars($heading) ?> · <?= SITE_NAME ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700&amp;family=Bitter:ital,wght@0,400;0,600;0,700;0,800;1,400&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/styles.css?v=26">
</head>
<body>
  <main class="verify-page">
    <div class="status-screen">
      <div class="card verify-card" data-state="<?= $state ?>">
        <header class="status-head">
          <p class="eyebrow">Archivo colaborativo &middot; 1810 &mdash; hoy</p>
        </header>
        <div class="status-body">
          <p class="status-mark" data-tone="<?= $tone ?>" aria-hidden="true"><?= $mark ?></p>
          <h1><?= htmlspecialchars($heading) ?></h1>
          <p><?= htmlspecialchars($detail) ?></p>
          <a class="btn btn-accent" href="/">Ir a la línea de tiempo</a>
        </div>
      </div>
    </div>
  </main>
</body>
</html>
