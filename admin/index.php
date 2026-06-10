<?php
/**
 * Moderation panel.
 * Single-admin login with the password hash defined in api/config.php.
 */

session_start();
require_once __DIR__ . '/../api/db.php';

$loginError = null;

if (($_POST['action'] ?? '') === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit;
}

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

if ($isAdmin && in_array($_POST['action'] ?? '', ['approve', 'reject', 'delete'], true)) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
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
    header('Location: index.php' . (($_POST['tab'] ?? '') ? '?tab=' . urlencode($_POST['tab']) : ''));
    exit;
}

$tab = $_GET['tab'] ?? 'pending_review';
$validTabs = ['pending_review', 'pending_email', 'approved', 'rejected'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'pending_review';
}

$rows = [];
$counts = [];
if ($isAdmin) {
    $pdo = db();
    foreach ($validTabs as $t) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM resources WHERE status = ?");
        $stmt->execute([$t]);
        $counts[$t] = (int) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare("SELECT * FROM resources WHERE status = ? ORDER BY created_at DESC");
    $stmt->execute([$tab]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$tabLabels = [
    'pending_review' => 'Pendientes de moderación',
    'pending_email'  => 'Sin validar email',
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
  <link rel="stylesheet" href="/assets/css/styles.css">
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
    <form method="post">
      <input type="hidden" name="action" value="logout">
      <button type="submit" class="btn btn-ghost">Salir</button>
    </form>
  </header>

  <nav class="admin-tabs">
    <?php foreach ($tabLabels as $key => $label): ?>
      <a href="?tab=<?= $key ?>" class="<?= $tab === $key ? 'active' : '' ?>">
        <?= e($label) ?> <span class="count"><?= $counts[$key] ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <main class="admin-list">
    <?php if (!$rows): ?>
      <p class="empty">No hay recursos en esta categoría.</p>
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
  </main>
<?php endif; ?>

</body>
</html>
