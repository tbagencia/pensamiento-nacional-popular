<?php
/**
 * Dynamic XML sitemap: home plus every approved document.
 * No priority/changefreq (Google ignores them). lastmod is the
 * submission date — accurate, since documents are not edited after
 * approval — and the home carries the latest publication date.
 */

require_once __DIR__ . '/db.php';

$base = base_url();
$rows = db()
    ->query("SELECT id, title, author, created_at FROM resources WHERE status = 'approved' ORDER BY id")
    ->fetchAll(PDO::FETCH_ASSOC);

$lastmod = fn (string $createdAt): string => substr($createdAt, 0, 10);
$latest = $rows ? max(array_column($rows, 'created_at')) : null;

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc><?= htmlspecialchars($base) ?>/</loc><?= $latest ? '<lastmod>' . $lastmod($latest) . '</lastmod>' : '' ?></url>
<?php foreach ($rows as $row): ?>
  <url><loc><?= htmlspecialchars($base . document_path($row)) ?></loc><lastmod><?= $lastmod($row['created_at']) ?></lastmod></url>
<?php endforeach; ?>
</urlset>
