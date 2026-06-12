<?php
/** Public read API: returns approved resources ordered by year. */

require_once __DIR__ . '/db.php';

$stmt = db()->query(
    "SELECT id, title, author, year, type, excerpt, source_url
     FROM resources
     WHERE status = 'approved'
     ORDER BY year ASC, id ASC"
);
$resources = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Canonical page path, so the front end never rebuilds slugs on its own.
foreach ($resources as &$resource) {
    $resource['path'] = document_path($resource);
}
unset($resource);

json_response(['resources' => $resources]);
