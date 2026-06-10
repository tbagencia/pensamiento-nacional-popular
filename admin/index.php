<?php
/**
 * Moderation panel.
 * Single-admin login with the password hash defined in api/config.php.
 */

session_start();
require_once __DIR__ . '/../api/db.php';

$loginError = null;

if (($_POST['action'] ?? '') === 'login') {
    if (password_verify($_POST['password'] ?? '', ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    } else {
        $loginError = 'Contraseña incorrecta.';
    }
}

$isAdmin = !empty($_SESSION['admin']);
$validTabs = ['pending_review', 'pending_email', 'approved', 'rejected'];

function tab_counts(PDO $pdo, array $tabs): array
{
    $counts = [];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM resources WHERE status = ?");
    foreach ($tabs as $tab) {
        $stmt->execute([$tab]);
        $counts[$tab] = (int) $stmt->fetchColumn();
    }
    return $counts;
}

if ($isAdmin && in_array($_POST['action'] ?? '', ['approve', 'reject', 'delete'], true)) {
    // Requests from admin.js expect JSON; plain form posts get the redirect.
    $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        if ($isAjax) {
            json_response(['ok' => false, 'error' => 'Invalid CSRF token'], 403);
        }
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    $id = (int) ($_POST['id'] ?? 0);
    $pdo = db();
    match ($_POST['action']) {
        'approve' => $pdo->prepare("UPDATE resources SET status = 'approved' WHERE id = ?")->execute([$id]),
        'reject'  => $pdo->prepare("UPDATE resources SET status = 'rejected' WHERE id = ?")->execute([$id]),
        'delete'  => $pdo->prepare("DELETE FROM resources WHERE id = ?")->execute([$id]),
    };
    if ($isAjax) {
        json_response(['ok' => true, 'counts' => tab_counts($pdo, $validTabs)]);
    }
    header('Location: index.php' . (($_POST['tab'] ?? '') ? '?tab=' . urlencode($_POST['tab']) : ''));
    exit;
}

$tab = $_GET['tab'] ?? 'pending_review';
if (!in_array($tab, $validTabs, true)) {
    $tab = 'pending_review';
}

$rows = [];
$counts = [];
if ($isAdmin) {
    $pdo = db();
    $counts = tab_counts($pdo, $validTabs);
    $stmt = $pdo->prepare("SELECT * FROM resources WHERE status = ? ORDER BY created_at DESC");
    $stmt->execute([$tab]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$tabLabels = [
    'pending_review' => 'Pendientes',
    'pending_email'  => 'Sin validar',
    'approved'       => 'Aprobados',
    'rejected'       => 'Rechazados',
];

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Administración · <?= SITE_NAME ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700&amp;family=Bitter:ital,wght@0,400;0,600;0,700;0,800;1,400&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/styles.css?v=3">
</head>
<body class="admin-body">

<?php if (!$isAdmin): ?>
  <main class="verify-page">
    <form method="post" class="card login-card">
      <h1>Administración</h1>
      <p>Panel de moderación de la línea de tiempo.</p>
      <?php if ($loginError): ?><p class="form-error"><?= e($loginError) ?></p><?php endif; ?>
      <input type="hidden" name="action" value="login">
      <label for="password">Contraseña</label>
      <input type="password" id="password" name="password" required autofocus>
      <button type="submit" class="btn btn-primary">Ingresar</button>
    </form>
  </main>
<?php else: ?>
  <header class="admin-header">
    <h1>Moderación</h1>
    <div class="admin-header-actions">
      <a class="btn btn-ghost" href="#moderadores">Moderadores</a>
      <a class="btn btn-ghost" href="/" target="_blank" rel="noopener">Ver sitio</a>
    </div>
  </header>

  <nav class="admin-tabs">
    <?php foreach ($tabLabels as $key => $label): ?>
      <a href="?tab=<?= $key ?>"
         class="<?= $tab === $key ? 'active' : '' ?>"
         <?= $key === 'pending_review' && $counts[$key] > 0 ? 'data-attention' : '' ?>>
        <span class="label"><?= e($label) ?></span>
        <span class="count"><?= $counts[$key] ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <main class="admin-list">
    <?php if (!$rows): ?>
      <p class="empty">No hay documentos en esta categoría.</p>
    <?php endif; ?>

    <?php foreach ($rows as $r): ?>
      <article class="card admin-card">
        <header>
          <span class="badge badge-year"><?= (int) $r['year'] ?></span>
          <span class="badge badge-type"><?= e($r['type']) ?></span>
        </header>
        <h2><?= e($r['title']) ?></h2>
        <p class="author"><?= e($r['author']) ?></p>
        <p class="excerpt"><?= nl2br(e($r['excerpt'])) ?></p>
        <?php if ($r['source_url']): ?>
          <p><a href="<?= e($r['source_url']) ?>" target="_blank" rel="noopener noreferrer">Fuente</a></p>
        <?php endif; ?>
        <p class="meta">Cargado por <?= e($r['submitter_email']) ?> · <?= e($r['created_at']) ?> UTC</p>

        <div class="admin-actions">
          <?php foreach ([['approve', 'Aprobar', 'btn-primary'], ['reject', 'Rechazar', 'btn-danger'], ['delete', 'Eliminar', 'btn-ghost']] as [$action, $label, $class]): ?>
            <?php if ($action === 'approve' && $r['status'] === 'approved') continue; ?>
            <?php if ($action === 'reject' && $r['status'] === 'rejected') continue; ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="action" value="<?= $action ?>">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="tab" value="<?= e($tab) ?>">
              <button type="submit" class="btn <?= $class ?>"><?= $label ?></button>
            </form>
          <?php endforeach; ?>
        </div>
      </article>
    <?php endforeach; ?>

    <footer class="admin-foot" id="moderadores">
      <?php if (moderator_emails()): ?>
        <p>Notificaciones de moderación activas para:
          <strong><?= e(implode(', ', moderator_emails())) ?></strong></p>
        <p>Para cambiar la lista, editá <code>MODERATOR_EMAILS</code> en el archivo <code>.env</code>.</p>
      <?php else: ?>
        <p>No hay moderadores configurados: nadie recibe aviso cuando se valida una carga.
          Agregá <code>MODERATOR_EMAILS</code> en el archivo <code>.env</code>.</p>
      <?php endif; ?>
    </footer>
  </main>

  <div id="toasts" class="toasts" aria-live="polite"></div>
  <script src="/assets/js/admin.js?v=2"></script>
<?php endif; ?>

</body>
</html>
