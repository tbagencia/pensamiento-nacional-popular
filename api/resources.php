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

json_response(['resources' => $resources]);
