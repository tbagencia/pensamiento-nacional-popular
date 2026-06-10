<?php
/**
 * Per-document landing page for shared links (/documento/{id}).
 * Crawlers (Facebook, WhatsApp, X) read the Open Graph tags; browsers
 * are redirected to the timeline positioned at the document's card.
 */

require_once __DIR__ . '/db.php';

$id = (int) ($_GET['id'] ?? 0);

$stmt = db()->prepare(
    "SELECT id, title, author, year, excerpt
     FROM resources
     WHERE id = ? AND status = 'approved'"
);
$stmt->execute([$id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    header('Location: /', true, 302);
    exit;
}

$ogTitle = sprintf('«%s» — %s (%d)', $doc['title'], $doc['author'], $doc['year']);
$excerpt = trim(preg_replace('/\s+/', ' ', $doc['excerpt'] ?? ''));
$ogDescription = mb_strlen($excerpt) > 300
    ? rtrim(mb_substr($excerpt, 0, 297)) . '…'
    : $excerpt;
$pageUrl = base_url() . '/documento/' . $doc['id'];
$target = '/linea/' . $doc['year'] . '#doc-' . $doc['id'];

$e = fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($ogTitle) ?> · <?= $e(SITE_NAME) ?></title>
  <meta name="description" content="<?= $e($ogDescription) ?>">
  <link rel="canonical" href="<?= $e($pageUrl) ?>">
  <meta property="og:type" content="article">
  <meta property="og:title" content="<?= $e($ogTitle) ?>">
  <meta property="og:description" content="<?= $e($ogDescription) ?>">
  <meta property="og:url" content="<?= $e($pageUrl) ?>">
  <meta property="og:site_name" content="<?= $e(SITE_NAME) ?>">
  <meta property="og:locale" content="es_AR">
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="<?= $e($ogTitle) ?>">
  <meta name="twitter:description" content="<?= $e($ogDescription) ?>">
  <script>location.replace(<?= json_encode($target) ?>);</script>
  <noscript><meta http-equiv="refresh" content="0; url=<?= $e($target) ?>"></noscript>
</head>
<body>
  <p><a href="<?= $e($target) ?>"><?= $e($ogTitle) ?></a></p>
</body>
</html>
