<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/public_flow.php';

// This route is intentionally used only by the generated QR code. Redirecting
// immediately implements POST/Redirect/GET-like behavior for a scan: refreshing
// the destination does not run the QR reset again.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . sfmsQrEntryDestination(), true, 302);
exit;
