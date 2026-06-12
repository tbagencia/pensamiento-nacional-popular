<?php
/**
 * Dev-only previews of the platform emails and post-action screens.
 * Lets the team review the visual identity without going through the
 * real flows. Returns 404 in production (DEV_MODE=false).
 */

require_once __DIR__ . '/mailer.php';

if (!DEV_MODE) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('404 Not Found');
}

$emails = [
    'verification' => [
        'Validación de carga (botón VALIDAR)',
        fn (): string => verification_email_html(
            'Discurso del 17 de octubre',
            base_url() . '/validar/' . str_repeat('0', 64)
        ),
    ],
    'moderators' => [
        'Aviso a moderadores (documento pendiente)',
        fn (): string => moderation_email_html(
            ['title' => 'Discurso del 17 de octubre', 'author' => 'Juan Domingo Perón', 'year' => 1945],
            base_url() . '/admin/'
        ),
    ],
    'approved' => [
        'Aporte aprobado (al autor)',
        fn (): string => approved_email_html('Discurso del 17 de octubre', base_url() . '/documento/1'),
    ],
    'rejected' => [
        'Aporte rechazado con motivo (al autor)',
        fn (): string => rejected_email_html(
            'Discurso del 17 de octubre',
            "Ya existe un documento igual en la línea de tiempo.\nRevisá el año 1945."
        ),
    ],
    'rejected-no-reason' => [
        'Aporte rechazado sin motivo (al autor)',
        fn (): string => rejected_email_html('Discurso del 17 de octubre', ''),
    ],
    'feedback' => [
        'Comentario de visitante (a moderadores)',
        fn (): string => feedback_email_html(
            'Reporte de error',
            "El buscador no encuentra «Evita» con tilde.\nProbé en Firefox y en Chrome.",
            'visitante@example.org',
            '/linea/1952'
        ),
    ],
];

$email = $_GET['email'] ?? '';
if (isset($emails[$email])) {
    header('Content-Type: text/html; charset=utf-8');
    echo $emails[$email][1]();
    exit;
}

$verifyStates = ['verified', 'published', 'already', 'invalid'];

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Previews de desarrollo · <?= SITE_NAME ?></title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=23">
</head>
<body>
  <main class="verify-page">
    <div class="card" style="max-inline-size: 30rem;">
      <h1>Previews de desarrollo</h1>
      <p>Solo disponible con <code>DEV_MODE=true</code>.</p>

      <h2>Emails</h2>
      <ul>
        <?php foreach ($emails as $key => [$label]): ?>
          <li><a href="/api/preview.php?email=<?= $key ?>"><?= htmlspecialchars($label) ?></a></li>
        <?php endforeach; ?>
      </ul>

      <h2>Pantallas</h2>
      <ul>
        <?php foreach ($verifyStates as $state): ?>
          <li><a href="/api/verify.php?preview=<?= $state ?>">Validación: <?= $state ?></a></li>
        <?php endforeach; ?>
        <li><a href="/cargar#preview-success">Carga recibida (pantalla de éxito)</a></li>
      </ul>
    </div>
  </main>
</body>
</html>
