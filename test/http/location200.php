<?php

/**
 * location200.php - Test HTTP endpoint that answers 200 with a Location header.
 *
 * The header is legal on a non-3xx response and libcurl never follows it there.
 * Used by FileTest to verify that the redirect validation callback leaves such
 * a response alone: the target names a host outside the allowlist, so treating
 * it as a redirect would make a readable response unreadable.
 */

\header('Location: https://evil.example/elsewhere', true, 200);

echo 'PLAIN-200-BODY';
