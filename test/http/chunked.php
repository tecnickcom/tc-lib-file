<?php

/**
 * chunked.php - Test HTTP endpoint that returns a body of undeclared length.
 *
 * The body is flushed in pieces without a Content-Length, so libcurl reports an
 * unknown download size throughout. Used by FileTest to reach the streaming arm
 * of the maxRemoteSize enforcement, the one the declared-size guard cannot
 * cover.
 */

for ($i = 0; $i < 10; $i++) {
    echo \str_repeat('Y', 100);
    \flush();
}
