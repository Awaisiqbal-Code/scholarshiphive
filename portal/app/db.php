<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    global $CONFIG;
    if ($pdo) return $pdo;
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        if (!empty($CONFIG['dsn'])) {               // used for local testing only
            $pdo = new PDO($CONFIG['dsn'], null, null, $opts);
            if (str_starts_with((string)$CONFIG['dsn'], 'sqlite:')) {
                // Register standard MySQL functions for SQLite compatibility
                $pdo->sqliteCreateFunction('FIELD', function(...$args) {
                    if (count($args) < 2) return 0;
                    $val = (string)$args[0];
                    for ($i = 1; $i < count($args); $i++) {
                        if ((string)$args[$i] === $val) return $i;
                    }
                    return 0;
                });
                $pdo->sqliteCreateFunction('CURDATE', fn() => date('Y-m-d'));
                $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
                $pdo->sqliteCreateFunction('IF', fn($cond, $a, $b) => $cond ? $a : $b);
            }
        } else {
            $port = !empty($CONFIG['db_port']) ? ';port=' . (int)$CONFIG['db_port'] : '';
            $dsn = 'mysql:host=' . $CONFIG['db_host'] . $port . ';dbname=' . $CONFIG['db_name'] . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass'], $opts);
        }
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        exit('The portal cannot reach its database. Check app/config.php.');
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
