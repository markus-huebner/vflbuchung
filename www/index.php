<?php
declare(strict_types=1);

$applicationRoot = is_file(__DIR__ . '/app/bootstrap.php') ? __DIR__ : dirname(__DIR__);
require $applicationRoot . '/app/bootstrap.php';

(new App\Application($config, $pdo))->run();
