<?php
/**
 * Moderation panel.
 * Single-admin login with the password hash defined in api/config.php.
 */

session_start();
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/mailer.php';

// When ADMIN_PATH is set (production), the panel only answers at
// /panel/{ADMIN_PATH} and the guessable /admin/ URL turns into a 404.
// The secret lives in .env, never in this public repository.
$adminPath = env('ADMIN_PATH', '') ?? '';
if ($adminPath !== '') {
    $requestPath = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
    if (!hash_equals('/panel/' . $adminPath, $requestPath)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '404 Not Found';
        exit;
    }
}

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

/** Validates and inserts/updates a period; periods must not overlap. */
function save_period(PDO $pdo, array $input): void
{
    $id = (int) ($input['id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $start = (int) ($input['start_year'] ?? 0);
    $end = trim($input['end_year'] ?? '') === '' ? null : (int) $input['end_year'];

    $errors = [];
    if ($name === '' || mb_strlen($name) > 80) {
        $errors[] = 'El nombre es obligatorio (máximo 80 caracteres).';
    }
    if ($start < MIN_YEAR || $start > 2100) {
        $errors[] = 'El año de inicio debe estar entre ' . MIN_YEAR . ' y 2100.';
    }
    if ($end !== null && $end < $start) {
        $errors[] = 'El año de fin no puede ser anterior al de inicio.';
    }
    foreach (periods($pdo) as $other) {
        if ((int) $other['id'] === $id) {
            continue;
        }
        $otherEnd = $other['end_year'] !== null ? (int) $other['end_year'] : PHP_INT_MAX;
        if ($start <= $otherEnd && (int) $other['start_year'] <= ($end ?? PHP_INT_MAX)) {
            $errors[] = "Se superpone con «{$other['name']}».";
            break;
        }
    }
    if ($errors) {
        http_response_code(422);
        exit('Datos inválidos: ' . implode(' ', $errors));
    }

    if ($id > 0) {
        $pdo->prepare('UPDATE periods SET name = ?, start_year = ?, end_year = ? WHERE id = ?')
            ->execute([$name, $start, $end, $id]);
    } else {
        $pdo->prepare('INSERT INTO periods (name, start_year, end_year) VALUES (?, ?, ?)')
            ->execute([$name, $start, $end]);
    }
}

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

// Period management: plain POST + redirect — edits are rare and the
// curated table is tiny, no AJAX needed.
if ($isAdmin && in_array($_POST['action'] ?? '', ['period_save', 'period_delete'], true)) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    $pdo = db();
    if ($_POST['action'] === 'period_save') {
        save_period($pdo, $_POST);
    } else {
        $pdo->prepare('DELETE FROM periods WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
    }
    header('Location: index.php?tab=periodos');
    exit;
}

if ($isAdmin && in_array($_POST['action'] ?? '', ['approve', 'reject', 'delete', 'edit'], true)) {
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

    if ($_POST['action'] === 'edit') {
        edit_resource($pdo, $id, $_POST, $isAjax, $validTabs);
    }

    // Fetched before mutating so approve/reject can notify the submitter.
    $stmt = $pdo->prepare(
        "SELECT id, title, " . author_label_sql('resources') . " AS author, submitter_email
         FROM resources WHERE id = ?"
    );
    $stmt->execute([$id]);
    $resource = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    match ($_POST['action']) {
        'approve' => $pdo->prepare("UPDATE resources SET status = 'approved' WHERE id = ?")->execute([$id]),
        'reject'  => $pdo->prepare("UPDATE resources SET status = 'rejected' WHERE id = ?")->execute([$id]),
        'delete'  => $pdo->prepare("DELETE FROM resources WHERE id = ?")->execute([$id]),
    };
    if ($_POST['action'] === 'delete') {
        $pdo->prepare("DELETE FROM resource_authors WHERE resource_id = ?")->execute([$id]);
    }

    // The submitter learns the outcome by email; moderators skip their own.
    if ($resource && !is_moderator_email($resource['submitter_email'])) {
        if ($_POST['action'] === 'approve') {
            notify_submitter_approved(
                $resource['submitter_email'],
                $resource['title'],
                base_url() . document_path($resource)
            );
        } elseif ($_POST['action'] === 'reject') {
            notify_submitter_rejected(
                $resource['submitter_email'],
                $resource['title'],
                trim($_POST['reason'] ?? '')
            );
        }
    }

    if ($isAjax) {
        json_response(['ok' => true, 'counts' => tab_counts($pdo, $validTabs)]);
    }
    header('Location: index.php' . (($_POST['tab'] ?? '') ? '?tab=' . urlencode($_POST['tab']) : ''));
    exit;
}

/** Validates and saves the moderator's edits. Mirrors the submit.php rules. */
function edit_resource(PDO $pdo, int $id, array $input, bool $isAjax, array $validTabs): never
{
    $title = trim($input['title'] ?? '');
    $author = trim($input['author'] ?? '');
    $year = (int) ($input['year'] ?? 0);
    $type = in_array($input['type'] ?? '', VALID_TYPES, true) ? $input['type'] : 'texto';
    $excerpt = trim($input['excerpt'] ?? '');
    $sourceUrl = trim($input['source_url'] ?? '');

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
    if ($errors) {
        if ($isAjax) {
            json_response(['ok' => false, 'errors' => $errors], 422);
        }
        http_response_code(422);
        exit('Datos inválidos: ' . implode(' ', $errors));
    }

    $stmt = $pdo->prepare(
        "UPDATE resources SET title = ?, year = ?, type = ?, excerpt = ?, source_url = ?
         WHERE id = ?"
    );
    $stmt->execute([$title, $year, $type, $excerpt, $sourceUrl ?: null, $id]);
    set_resource_authors($pdo, $id, $author);

    if ($isAjax) {
        json_response([
            'ok' => true,
            'counts' => tab_counts($pdo, $validTabs),
            'resource' => [
                'title' => $title,
                'author' => implode(', ', resource_author_names($pdo, $id)),
                'year' => $year,
                'type' => $type,
                'excerpt' => $excerpt,
                'source_url' => $sourceUrl,
            ],
        ]);
    }
    header('Location: index.php' . (($input['tab'] ?? '') ? '?tab=' . urlencode($input['tab']) : ''));
    exit;
}

$tab = $_GET['tab'] ?? 'pending_review';
if (!in_array($tab, $validTabs, true) && $tab !== 'periodos') {
    $tab = 'pending_review';
}

$rows = [];
$counts = [];
if ($isAdmin) {
    $pdo = db();
    $counts = tab_counts($pdo, $validTabs);
    if ($tab !== 'periodos') {
        $stmt = $pdo->prepare(
            "SELECT *, " . author_label_sql('resources') . " AS author
             FROM resources WHERE status = ? ORDER BY created_at DESC"
        );
        $stmt->execute([$tab]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
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
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="/assets/img/pnp-favicon/favicon.svg">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/pnp-favicon/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/img/pnp-favicon/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/img/pnp-favicon/apple-icon-180x180.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700&amp;family=Bitter:ital,wght@0,400;0,600;0,700;0,800;1,400&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/styles.css?v=45">
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
    <a href="?tab=periodos" class="<?= $tab === 'periodos' ? 'active' : '' ?>">
      <span class="label">Períodos</span>
    </a>
    <div class="admin-search">
      <input type="search" id="admin-search" placeholder="Buscar por título, autor, año o email"
             autocomplete="off" aria-label="Buscar documentos en esta categoría">
    </div>
  </nav>

  <main class="admin-list">
    <?php if ($tab === 'periodos'): ?>
      <section class="card admin-card periods-admin">
        <h2>Períodos historiográficos</h2>
        <p class="meta">
          Los capítulos de la línea de tiempo. Sin año de fin, el período
          llega hasta hoy. Los períodos no pueden superponerse.
        </p>
        <div class="period-row period-head" aria-hidden="true">
          <span>Nombre</span><span>Inicio</span><span>Fin</span>
        </div>
        <?php foreach (periods($pdo) as $p): ?>
          <div class="period-row">
            <form method="post" class="period-form">
              <input type="hidden" name="action" value="period_save">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
              <input type="text" name="name" maxlength="80" required value="<?= e($p['name']) ?>" aria-label="Nombre del período">
              <input type="number" name="start_year" min="<?= MIN_YEAR ?>" max="2100" required value="<?= (int) $p['start_year'] ?>" aria-label="Año de inicio">
              <input type="number" name="end_year" min="<?= MIN_YEAR ?>" max="2100" value="<?= $p['end_year'] !== null ? (int) $p['end_year'] : '' ?>" placeholder="hoy" aria-label="Año de fin">
              <button type="submit" class="btn btn-primary">Guardar</button>
            </form>
            <form method="post" class="period-delete-form">
              <input type="hidden" name="action" value="period_delete">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
              <button type="submit" class="btn btn-ghost">Eliminar</button>
            </form>
          </div>
        <?php endforeach; ?>

        <h3>Agregar período</h3>
        <div class="period-row">
          <form method="post" class="period-form">
            <input type="hidden" name="action" value="period_save">
            <input type="hidden" name="id" value="0">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? '') ?>">
            <input type="text" name="name" maxlength="80" required placeholder="Nombre del período" aria-label="Nombre del período">
            <input type="number" name="start_year" min="<?= MIN_YEAR ?>" max="2100" required placeholder="Inicio" aria-label="Año de inicio">
            <input type="number" name="end_year" min="<?= MIN_YEAR ?>" max="2100" placeholder="hoy" aria-label="Año de fin">
            <button type="submit" class="btn btn-primary">Agregar</button>
          </form>
        </div>
      </section>
    <?php endif; ?>

    <?php if (!$rows && $tab !== 'periodos'): ?>
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
          <p class="admin-source"><a href="<?= e($r['source_url']) ?>" target="_blank" rel="noopener noreferrer">Fuente</a></p>
        <?php endif; ?>
        <p class="meta">Cargado por <?= e($r['submitter_email']) ?> · <time datetime="<?= e(str_replace(' ', 'T', $r['created_at'])) ?>Z"><?= e($r['created_at']) ?> UTC</time></p>

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

        <details class="admin-edit">
          <summary>Editar</summary>
          <form method="post" class="admin-edit-form">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <div class="field">
              <label for="edit-title-<?= (int) $r['id'] ?>">Título</label>
              <input type="text" id="edit-title-<?= (int) $r['id'] ?>" name="title" maxlength="200" required value="<?= e($r['title']) ?>">
              <p class="field-error" data-for="title"></p>
            </div>
            <div class="field">
              <label for="edit-author-<?= (int) $r['id'] ?>">Autor o autora</label>
              <input type="text" id="edit-author-<?= (int) $r['id'] ?>" name="author" maxlength="120" required value="<?= e($r['author']) ?>" autocomplete="off">
              <p class="field-error" data-for="author"></p>
            </div>
            <div class="field-row">
              <div class="field">
                <label for="edit-year-<?= (int) $r['id'] ?>">Año</label>
                <input type="number" id="edit-year-<?= (int) $r['id'] ?>" name="year" min="<?= MIN_YEAR ?>" max="<?= (int) date('Y') ?>" required value="<?= (int) $r['year'] ?>">
                <p class="field-error" data-for="year"></p>
              </div>
              <div class="field">
                <label for="edit-type-<?= (int) $r['id'] ?>">Tipo</label>
                <select id="edit-type-<?= (int) $r['id'] ?>" name="type">
                  <?php foreach (VALID_TYPES as $type): ?>
                    <option value="<?= $type ?>" <?= $r['type'] === $type ? 'selected' : '' ?>><?= ucfirst($type) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="field">
              <label for="edit-excerpt-<?= (int) $r['id'] ?>">Descripción o extracto</label>
              <textarea id="edit-excerpt-<?= (int) $r['id'] ?>" name="excerpt" rows="8" required><?= e($r['excerpt']) ?></textarea>
              <p class="field-error" data-for="excerpt"></p>
            </div>
            <div class="field">
              <label for="edit-source-<?= (int) $r['id'] ?>">Enlace a la fuente</label>
              <input type="url" id="edit-source-<?= (int) $r['id'] ?>" name="source_url" value="<?= e($r['source_url']) ?>">
              <p class="field-error" data-for="source_url"></p>
            </div>
            <button type="submit" class="btn btn-primary">Guardar cambios</button>
            <p class="field-error" data-for="general"></p>
          </form>
        </details>
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

  <!-- Canonical author names for the edit forms' autocomplete, so
       moderators converge on existing spellings while reviewing. -->
  <script type="application/json" id="author-options"><?= json_encode(
      db()->query(
          "SELECT DISTINCT a.name FROM authors a
           JOIN resource_authors ra ON ra.author_id = a.id
           JOIN resources r ON r.id = ra.resource_id
           WHERE r.status = 'approved' ORDER BY a.name"
      )->fetchAll(PDO::FETCH_COLUMN),
      JSON_HEX_TAG | JSON_UNESCAPED_UNICODE
  ) ?></script>
  <div id="toasts" class="toasts" aria-live="polite"></div>
  <script src="/assets/js/author-tags.js?v=1"></script>
  <script src="/assets/js/admin.js?v=7"></script>
<?php endif; ?>

</body>
</html>
