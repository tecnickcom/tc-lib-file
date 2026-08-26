<?php

/**
 * large.php - Test HTTP endpoint that returns a large body.
 *
 * Used by FileTest to verify that the maxRemoteSize limit is enforced.
 *
 * The length is declared explicitly: the built-in server omits Content-Length
 * for a generated body, and without it libcurl reports an unknown download size
 * for the whole transfer, so the declared-size guard in getUrlData() could never
 * be reached from an integration test.
 */

$body = \str_repeat('X', 1000);

\header('Content-Length: ' . \strlen($body));

echo $body;
