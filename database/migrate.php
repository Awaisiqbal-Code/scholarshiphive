<?php
declare(strict_types=1);
// Database update from the command line:  php database/migrate.php
// Same as Admin > System > "Run database update" in the portal. Safe to run more than once.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this from the command line, or use Admin > System in the portal.'); }

require __DIR__ . '/../portal/app/bootstrap.php';

echo "Updating the database...\n";
$log = run_migrations(db());
echo $log ? '  - ' . implode("\n  - ", $log) . "\n" : "  Already up to date.\n";
echo "Done. Schema version: " . SCHEMA_VERSION . "\n";
