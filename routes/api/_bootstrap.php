<?php

foreach ([
    'public.php',
    'customer.php',
    'admin.php',
    'catalog.php',
    'content.php',
    'supplier.php',
] as $routeFile) {
    require __DIR__ . DIRECTORY_SEPARATOR . $routeFile;
}
