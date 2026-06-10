<?php
/**
 * Exposes the session's verified email so the form can pre-fill and lock it,
 * plus the server-configured excerpt limit so the form counter matches.
 */

session_start();
require_once __DIR__ . '/config.php';

json_response([
    'verified_email' => $_SESSION['verified_email'] ?? null,
    'excerpt_max_length' => EXCERPT_MAX_LENGTH,
]);
