#!/bin/sh
set -e

VOLUME_DIR="${RAILWAY_VOLUME_MOUNT_PATH:-}"
if [ -z "$VOLUME_DIR" ] && [ -d "/data" ]; then
    VOLUME_DIR="/data"
fi

# Persistent file storage if volume is mounted
if [ -n "$VOLUME_DIR" ] && [ -d "$VOLUME_DIR" ]; then
    mkdir -p "$VOLUME_DIR/files"
    export STORAGE_DIR="$VOLUME_DIR/files"
fi

# If no external MYSQLHOST is configured, run embedded MariaDB inside container
if [ -z "${MYSQLHOST:-}" ] && [ -z "${DB_HOST:-}" ]; then
    DB_DATA_DIR="/var/lib/mysql"
    if [ -n "$VOLUME_DIR" ] && [ -d "$VOLUME_DIR" ]; then
        DB_DATA_DIR="$VOLUME_DIR/mysql"
    fi
    mkdir -p "$DB_DATA_DIR" /run/mysqld
    chown -R mysql:mysql "$DB_DATA_DIR" /run/mysqld

    if [ ! -d "$DB_DATA_DIR/mysql" ]; then
        echo "[Railway Init] Initializing embedded MariaDB data directory at $DB_DATA_DIR..."
        mariadb-install-db --user=mysql --datadir="$DB_DATA_DIR" --skip-test-db >/dev/null
    fi

    echo "[Railway Init] Starting embedded MariaDB server..."
    mariadbd --user=mysql --datadir="$DB_DATA_DIR" --socket=/run/mysqld/mysqld.sock --bind-address=127.0.0.1 --port=3306 --skip-networking=0 &

    for i in $(seq 1 30); do
        if mariadb-admin --protocol=tcp -h 127.0.0.1 -P 3306 ping >/dev/null 2>&1; then
            break
        fi
        sleep 1
    done

    mariadb --protocol=tcp -h 127.0.0.1 -P 3306 -u root -e "CREATE DATABASE IF NOT EXISTS portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'portal'@'%' IDENTIFIED BY 'portal123'; CREATE USER IF NOT EXISTS 'portal'@'localhost' IDENTIFIED BY 'portal123'; GRANT ALL PRIVILEGES ON portal.* TO 'portal'@'%'; GRANT ALL PRIVILEGES ON portal.* TO 'portal'@'localhost'; FLUSH PRIVILEGES;"

    export MYSQLHOST="127.0.0.1"
    export MYSQLPORT="3306"
    export MYSQLDATABASE="portal"
    export MYSQLUSER="portal"
    export MYSQLPASSWORD="portal123"
fi

DB_H="${MYSQLHOST:-${DB_HOST:-127.0.0.1}}"
DB_P="${MYSQLPORT:-${DB_PORT:-3306}}"
DB_N="${MYSQLDATABASE:-${MYSQL_DATABASE:-${DB_NAME:-portal}}}"
DB_U="${MYSQLUSER:-${DB_USER:-portal}}"
DB_W="${MYSQLPASSWORD:-${DB_PASS:-portal123}}"

echo "[Railway Init] Waiting for MySQL at ${DB_H}:${DB_P}/${DB_N}..."
for i in $(seq 1 30); do
    if mariadb --protocol=tcp --skip-ssl -h "$DB_H" -P "$DB_P" -u "$DB_U" -p"$DB_W" -e "SELECT 1" >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

# Seed database on first boot if users table does not exist yet
HAS_USERS=$(mariadb --protocol=tcp --skip-ssl -N -B -h "$DB_H" -P "$DB_P" -u "$DB_U" -p"$DB_W" "$DB_N" -e "SHOW TABLES LIKE 'users';" 2>/dev/null || true)
if [ -z "$HAS_USERS" ]; then
    echo "[Railway Init] Empty database detected — importing full v5 schema and seed data (369 students, 381 users)..."
    mariadb --protocol=tcp --skip-ssl -h "$DB_H" -P "$DB_P" -u "$DB_U" -p"$DB_W" "$DB_N" < /app/database/railway_full_seed.sql
    echo "[Railway Init] Database seed complete!"
fi

php /app/database/migrate.php || true

PORT_NUM="${PORT:-8000}"
echo "[Railway Start] Starting ScholarshipHive Processing Portal on 0.0.0.0:${PORT_NUM}..."
exec php -d upload_max_filesize=20M -d post_max_size=22M -S "0.0.0.0:${PORT_NUM}" -t /app/portal /app/portal/router.php
