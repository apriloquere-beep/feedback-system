<?php
/**
 * Legacy login entrypoint.
 * Admin login now lives at /admin/login.php.
 */

header('Location: /Feedback/admin/login.php', true, 302);
exit;
