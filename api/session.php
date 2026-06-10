<?php
/** Exposes the session's verified email so the form can pre-fill and lock it. */

session_start();
require_once __DIR__ . '/config.php';

json_response(['verified_email' => $_SESSION['verified_email'] ?? null]);
