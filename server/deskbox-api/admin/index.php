<?php
declare(strict_types=1);

// Keep the public /admin/ route while the implementation lives under /api/admin/.
require dirname(__DIR__) . '/api/admin/index.php';
