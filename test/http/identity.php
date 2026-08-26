<?php

/**
 * identity.php - Test HTTP endpoint that echoes the server's identity marker.
 *
 * Used by FileTest to confirm that the port it probed is served by the server
 * it started: the free port is chosen by binding and closing a socket, so
 * another process can claim it before `php -S` binds.
 */

echo (string) \getenv('TC_LIB_FILE_SERVER_MARKER');
