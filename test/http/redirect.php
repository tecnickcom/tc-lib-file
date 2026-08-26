<?php

/**
 * redirect.php - Test HTTP endpoint that answers with a 302 redirect.
 *
 * Used by FileTest to verify redirect handling in getUrlData(): the target
 * defaults to /empty.php and can be overridden with the 'to' query parameter
 * to point at a host outside the allowlist.
 */

$target = isset($_GET['to']) && \is_string($_GET['to']) ? $_GET['to'] : '/empty.php';
\header('Location: ' . $target, true, 302);
