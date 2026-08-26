<?php

/**
 * gzip.php - Test HTTP endpoint that returns a gzip-encoded body.
 *
 * A small compressed payload that inflates to far more than it costs to
 * transfer. Used by FileTest to verify that maxRemoteSize bounds the bytes that
 * reach PHP memory rather than the bytes received: the progress figures count
 * the compressed length, so enforcing there would let this response through.
 */

$body = (string) \gzencode(\str_repeat('Z', 1_000_000), 9);

\header('Content-Encoding: gzip');
\header('Content-Length: ' . \strlen($body));

echo $body;
