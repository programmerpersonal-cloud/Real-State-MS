<?php
/**
 * Directory guard.
 *
 * If a server is ever configured without AllowOverride, the .htaccess files in
 * this tree are ignored and Apache would fall back to serving an index. This
 * stub makes that fallback a dead end instead of a file listing.
 */
http_response_code(404);
exit;
