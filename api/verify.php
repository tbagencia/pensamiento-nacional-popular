<?php
/** Email verification landing page (target of the VALIDAR button). */

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

$token = $_GET['token'] ?? '';
$state = 'invalid';

if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT id, status, title, author, year, submitter_email FROM resources WHERE verify_token = ?"
    );
    $stmt->execute([$token]);
    $resource = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($resource) {
        if ($resource['status'] === 'pending_email') {
            $pdo->prepare("UPDATE resources SET status = 'pending_review' WHERE id = ?")
                ->execute([$resource['id']]);
            $state = 'verified';
            notify_moderators($resource);
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
    ],
    'already' => [
        'Este aporte ya fue validado',
        'No hace falta validarlo de nuevo. Si fue aprobado, ya está visible en la línea de tiempo.',
    ],
    'invalid' => [
        'Enlace no válido',
        'El enlace de validación no existe o expiró. Volvé a cargar el documento si el problema persiste.',
    ],
];
[$heading, $detail] = $messages[$state];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($heading) ?> · <?= SITE_NAME ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700&amp;family=Bitter:ital,wght@0,400;0,600;0,700;0,800;1,400&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/styles.css?v=4">
</head>
<body>
  <main class="verify-page">
    <div class="card verify-card <?= $state === 'verified' ? 'verify-ok' : '' ?>">
      <h1><?= htmlspecialchars($heading) ?></h1>
      <p><?= htmlspecialchars($detail) ?></p>
      <a class="btn btn-primary" href="/">Ir a la línea de tiempo</a>
    </div>
  </main>
</body>
</html>
