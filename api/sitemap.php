<?php
/** Dynamic XML sitemap: home, submission form and every approved document. */

require_once __DIR__ . '/db.php';

$base = base_url();
$rows = db()
    ->query("SELECT id FROM resources WHERE status = 'approved' ORDER BY id")
    ->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc><?= htmlspecialchars($base) ?>/</loc></url>
  <url><loc><?= htmlspecialchars($base) ?>/cargar</loc></url>
<?php foreach ($rows as $row): ?>
  <url><loc><?= htmlspecialchars($base) ?>/documento/<?= (int) $row['id'] ?></loc></url>
<?php endforeach; ?>
</urlset>
