<?php
/**
 * Per-document page (/documento/{id}).
 * Server-rendered with the full text so search engines can index each
 * document on its own. Visually the document sits on the timeline rail
 * between its neighbours: one point on a line larger than itself.
 */

require_once __DIR__ . '/db.php';

$id = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare(
    "SELECT id, title, author, year, type, excerpt, source_url
     FROM resources
     WHERE id = ? AND status = 'approved'"
);
$stmt->execute([$id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    header('Location: /', true, 302);
    exit;
}

// Neighbours in timeline order (year ASC, id ASC) anchor the page to
// the line and give crawlers a path through the whole archive.
$prevStmt = db()->prepare(
    "SELECT id, title, author, year FROM resources
     WHERE status = 'approved' AND (year < ? OR (year = ? AND id < ?))
     ORDER BY year DESC, id DESC LIMIT 1"
);
$prevStmt->execute([$doc['year'], $doc['year'], $doc['id']]);
$prev = $prevStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$nextStmt = db()->prepare(
    "SELECT id, title, author, year FROM resources
     WHERE status = 'approved' AND (year > ? OR (year = ? AND id > ?))
     ORDER BY year ASC, id ASC LIMIT 1"
);
$nextStmt->execute([$doc['year'], $doc['year'], $doc['id']]);
$next = $nextStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$pageTitle = sprintf('«%s» — %s (%d)', $doc['title'], $doc['author'], $doc['year']);
$flatExcerpt = trim(preg_replace('/\s+/', ' ', $doc['excerpt'] ?? ''));
$metaDescription = mb_strlen($flatExcerpt) > 300
    ? rtrim(mb_substr($flatExcerpt, 0, 297)) . '…'
    : $flatExcerpt;
$pageUrl = base_url() . '/documento/' . $doc['id'];
$timelineUrl = '/linea/' . $doc['year'] . '#doc-' . $doc['id'];

$e = fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($pageTitle) ?> · <?= $e(SITE_NAME) ?></title>
  <meta name="description" content="<?= $e($metaDescription) ?>">
  <link rel="canonical" href="<?= $e($pageUrl) ?>">
  <meta property="og:type" content="article">
  <meta property="og:title" content="<?= $e($pageTitle) ?>">
  <meta property="og:description" content="<?= $e($metaDescription) ?>">
  <meta property="og:url" content="<?= $e($pageUrl) ?>">
  <meta property="og:site_name" content="<?= $e(SITE_NAME) ?>">
  <meta property="og:locale" content="es_AR">
  <meta property="og:image" content="<?= $e(base_url()) ?>/assets/img/share-default.png">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= $e($pageTitle) ?>">
  <meta name="twitter:description" content="<?= $e($metaDescription) ?>">
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="/assets/img/pnp-favicon/favicon.svg">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/pnp-favicon/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/assets/img/pnp-favicon/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/assets/img/pnp-favicon/apple-icon-180x180.png">
  <meta name="theme-color" content="#1d3557">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700&amp;family=Bitter:ital,wght@0,400;0,600;0,700;0,800;1,400&amp;display=swap" rel="stylesheet">
  <script src="/assets/js/font-scale.js?v=1"></script>
  <link rel="stylesheet" href="/assets/css/styles.css?v=26">
  <script type="application/ld+json"><?= json_encode([
      '@context' => 'https://schema.org',
      '@type' => 'Article',
      'headline' => $doc['title'],
      'description' => $metaDescription,
      'articleBody' => $doc['excerpt'],
      'author' => ['@type' => 'Person', 'name' => $doc['author']],
      'datePublished' => $doc['year'] . '-01-01',
      'inLanguage' => 'es-AR',
      'mainEntityOfPage' => $pageUrl,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
</head>
<body>
  <header class="site-header site-header-small">
    <a class="back-link" href="/">&larr; Volver a la línea de tiempo</a>
    <div class="header-actions">
      <div class="font-controls" role="group" aria-label="Tamaño del texto">
        <button
          type="button"
          class="font-control"
          data-font-scale-down
          aria-label="Reducir el tamaño del texto"
        >
          A&minus;
        </button>
        <button
          type="button"
          class="font-control"
          data-font-scale-up
          aria-label="Aumentar el tamaño del texto"
        >
          A+
        </button>
      </div>
      <button
        type="button"
        class="credits-link"
        data-credits-trigger
        aria-controls="credits-panel"
        aria-expanded="false"
      >
        Acerca de
      </button>
    </div>
    <div class="[ center ]">
      <p class="eyebrow">Archivo colaborativo · 1810 — hoy</p>
      <p class="doc-site-name">Línea de Tiempo del Pensamiento Nacional y Popular Argentino</p>
    </div>
  </header>

  <main class="doc-page">
    <div class="doc-rail">
      <?php if ($prev): ?>
        <a class="doc-neighbor" href="/documento/<?= (int) $prev['id'] ?>">
          <span class="doc-neighbor-year"><?= (int) $prev['year'] ?></span>
          <span class="doc-neighbor-card">
            <span class="doc-neighbor-title"><?= $e($prev['title']) ?></span>
            <span class="doc-neighbor-author"><?= $e($prev['author']) ?></span>
          </span>
        </a>
      <?php endif; ?>

      <p class="doc-year"><?= (int) $doc['year'] ?></p>

      <article class="card doc-card">
        <button type="button" class="share-btn" aria-label="Compartir este documento" aria-expanded="false" data-tip="Compartir">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="8.59" y1="10.49" x2="15.42" y2="6.51"/></svg>
        </button>
        <?php if ($doc['source_url']): ?>
          <a class="source-btn" href="<?= $e($doc['source_url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="Ver la fuente externa" data-tip="Ver fuente">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
          </a>
        <?php endif; ?>
        <span class="badge badge-type"><?= $e($doc['type']) ?></span>
        <h1><?= $e($doc['title']) ?></h1>
        <p class="author"><?= $e($doc['author']) ?></p>
        <p class="excerpt"><?= $e($doc['excerpt']) ?></p>
      </article>

      <?php if ($next): ?>
        <a class="doc-neighbor" href="/documento/<?= (int) $next['id'] ?>">
          <span class="doc-neighbor-year"><?= (int) $next['year'] ?></span>
          <span class="doc-neighbor-card">
            <span class="doc-neighbor-title"><?= $e($next['title']) ?></span>
            <span class="doc-neighbor-author"><?= $e($next['author']) ?></span>
          </span>
        </a>
      <?php endif; ?>
    </div>

    <p class="doc-cta">
      <a class="btn btn-primary" href="<?= $e($timelineUrl) ?>">Ir a la línea de tiempo Nacional y Popular</a>
    </p>
  </main>

  <footer class="site-footer">
    <p>Archivo colaborativo del Pensamiento Nacional y Popular Argentino.</p>
    <p>
      <a href="/cargar">Aportar un documento</a> ·
      <a href="mailto:aportes@pensamientonacionalypopular.com.ar">Contacto</a>
      ·
      <button
        type="button"
        class="link-button"
        data-credits-trigger
        aria-controls="credits-panel"
        aria-expanded="false"
      >
        Acerca de
      </button>
      ·
      <button
        type="button"
        class="link-button"
        data-feedback-trigger
        aria-controls="feedback-panel"
        aria-expanded="false"
      >
        Enviar un comentario
      </button>
    </p>
  </footer>

  <aside
    id="credits-panel"
    class="credits-panel"
    role="dialog"
    aria-modal="true"
    aria-labelledby="credits-title"
    aria-hidden="true"
  >
    <div class="credits-overlay" id="credits-overlay"></div>
    <div class="credits-content">
      <header class="credits-head">
        <button
          type="button"
          id="credits-close"
          class="credits-close"
          aria-label="Cerrar"
        >
          <svg
            width="20"
            height="20"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
          >
            <line x1="18" y1="6" x2="6" y2="18" />
            <line x1="6" y1="6" x2="18" y2="18" />
          </svg>
        </button>
        <p class="eyebrow">Archivo colaborativo</p>
        <h2 id="credits-title">Acerca del proyecto</h2>
      </header>

      <div class="credits-body">
      <section>
        <p>
          Herramienta web interactiva para visualizar una línea de tiempo de
          autores, debates y eventos del pensamiento nacional y popular
          argentino.
        </p>
        <p class="credits-subtitle">
          Proyecto final del Seminario «Pensamiento Nacional y Popular en el
          Siglo XX Argentino» — Facultad de Filosofía y Letras, Universidad de
          Buenos Aires.
        </p>
      </section>

      <section>
        <h3>Autores</h3>
        <ul class="credits-list">
          <li>Diego Gabriel Muñiz</li>
          <li>Nicolás Cubero</li>
        </ul>
      </section>

      <section>
        <h3>Dirección</h3>
        <ul class="credits-list">
          <li>Matías Farías</li>
          <li>Julia Rosemberg</li>
        </ul>
      </section>

      <section>
        <h3>Tecnología</h3>
        <ul class="credits-tech">
          <li>PHP</li>
          <li>SQLite</li>
          <li>JavaScript vanilla</li>
          <li>CSS · CUBE</li>
          <li>Bitter</li>
          <li>Archivo</li>
        </ul>
      </section>

      <section>
        <h3>Feedback</h3>
        <p>
          ¿Encontraste un error o tenés una sugerencia?
          <button
            type="button"
            class="link-button"
            data-feedback-trigger
            aria-controls="feedback-panel"
            aria-expanded="false"
          >
            Envianos un comentario
          </button>
        </p>
      </section>

      <section>
        <h3>Licencia</h3>
        <p class="credits-license">
          Código abierto bajo
          <a
            href="https://www.gnu.org/licenses/gpl-3.0.html"
            target="_blank"
            rel="noopener"
            >GNU General Public License v3.0</a
          >
        </p>
        <a
          href="https://github.com/tbagencia/pensamiento-nacional-popular"
          target="_blank"
          rel="noopener"
          class="credits-repo"
        >
          <svg
            width="14"
            height="14"
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
          >
            <path
              d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"
            />
          </svg>
          Ver el código en GitHub
        </a>
      </section>
      </div>
    </div>
  </aside>

  <script type="application/json" id="doc-data"><?= json_encode([
      'id' => (int) $doc['id'],
      'title' => $doc['title'],
      'author' => $doc['author'],
      'year' => (int) $doc['year'],
      'excerpt' => $doc['excerpt'],
  ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
  <script src="/assets/js/share.js?v=4"></script>
  <script src="/assets/js/documento.js?v=1"></script>
  <script src="/assets/js/credits.js?v=2"></script>
  <script src="/assets/js/feedback.js?v=3"></script>
</body>
</html>
