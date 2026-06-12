<?php
/**
 * Public read API: distinct canonical author names of approved documents.
 * Feeds the author autocomplete on the submission form so new uploads
 * reuse the existing spelling instead of creating near-duplicates.
 */

require_once __DIR__ . '/db.php';

$authors = db()->query(
    "SELECT DISTINCT ra.author
     FROM resource_authors ra
     JOIN resources r ON r.id = ra.resource_id
     WHERE r.status = 'approved'
     ORDER BY ra.author"
)->fetchAll(PDO::FETCH_COLUMN);

json_response(['authors' => $authors]);
