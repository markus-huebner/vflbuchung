<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

if ($config->isSetupRequired()) {
    (new App\SetupWizard($config, null, true))->run();
    exit;
}

if ($pdo === null) {
    (new App\SetupWizard($config, $databaseConnectionError))->run();
    exit;
}

(new App\Application($config, $pdo))->run();
