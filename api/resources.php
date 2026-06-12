<?php
/** Public read API: returns approved resources ordered by year. */

require_once __DIR__ . '/db.php';

$stmt = db()->query(
    "SELECT id, title, " . author_label_sql('resources') . " AS author,
            year, type, excerpt, source_url
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

// The timeline renders period bands as section headers.
$periods = array_map(
    fn (array $p): array => [
        'id' => (int) $p['id'],
        'name' => $p['name'],
        'start_year' => (int) $p['start_year'],
        'end_year' => $p['end_year'] !== null ? (int) $p['end_year'] : null,
    ],
    periods(db())
);

json_response(['resources' => $resources, 'periods' => $periods]);
