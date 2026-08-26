<?php

/**
 * notfound.php - Test HTTP endpoint that answers 404 with a body.
 *
 * Used by FileTest to pin the upper bound of isRedirectStatus(): a 404 must not
 * count as a redirect. Reached with a File configured without
 * CURLOPT_FAILONERROR, which is what lets a 4xx body reach getUrlData() at all.
 */

\header('Content-Type: text/plain', true, 404);

echo 'NOTFOUND-BODY';
