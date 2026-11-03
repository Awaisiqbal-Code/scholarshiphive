<?php
// Local, Railway & Vercel SQLite / MySQL automatic switcher
$hasMysql = (bool)(getenv('MYSQLHOST') || getenv('DB_HOST'));

if (!$hasMysql) {
    $bundle = __DIR__ . '/portal.sqlite';
    $dbFile = getenv('PORTAL_SQLITE') ?: $bundle;
    if (!getenv('PORTAL_SQLITE') && (getenv('VERCEL') || !is_writable(__DIR__) || !is_writable($dbFile))) {
        $tmpDb = rtrim(sys_get_temp_dir(), '/\\') . '/portal_v5_369.sqlite';
        if (!is_file($tmpDb) || (is_file($bundle) && filesize($tmpDb) !== filesize($bundle))) {
            @copy($bundle, $tmpDb);
            @chmod($tmpDb, 0666);
        }
        $dbFile = $tmpDb;
    }

    return [
        'dsn' => 'sqlite:' . $dbFile,
        'debug' => (getenv('RAILWAY_ENVIRONMENT') || getenv('VERCEL')) ? false : true,
    ];
}

return [];
