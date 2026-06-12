<?php
/**
 * Public read API: authors of approved documents, most published first.
 * Feeds the author autocomplete on the submission form and the author
 * filter pills on the timeline; the slug is served so the front end
 * never rebuilds it on its own.
 */

require_once __DIR__ . '/db.php';

$authors = db()->query(
    "SELECT a.name, COUNT(*) AS count
     FROM authors a
     JOIN resource_authors ra ON ra.author_id = a.id
     JOIN resources r ON r.id = ra.resource_id
     WHERE r.status = 'approved'
     GROUP BY a.id
     ORDER BY count DESC, a.name"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($authors as &$author) {
    $author['count'] = (int) $author['count'];
    $author['slug'] = slugify($author['name']);
}
unset($author);

json_response(['authors' => $authors]);
