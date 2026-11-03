<?php
// ------------------------------------------------------------------
// Processing Team Portal — settings
// Fill in the database details from cPanel > MySQL Databases, or set
// environment variables (MYSQLHOST, MYSQLPORT, MYSQLDATABASE, MYSQLUSER, MYSQLPASSWORD).
// ------------------------------------------------------------------

return [
    'db_host' => getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost',
    'db_port' => getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306',
    'db_name' => getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE') ?: getenv('DB_NAME') ?: 'portal',
    'db_user' => getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'portal',
    'db_pass' => getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'portal123'),

    // Name shown in the header of every portal.
    'app_name' => 'ScholarshipHive Processing Portal',

    // Leave empty to detect automatically. If the portal lives in a
    // sub-folder and links break, set it, e.g. '/portal'.
    'base_url' => '',

    // Sign everyone out after this many minutes without activity.
    'idle_minutes' => 30,

    // Failed sign-ins allowed per 15 minutes (per username and per IP).
    'max_attempts' => 8,

    'timezone' => 'Asia/Karachi',

    // Secret key that encrypts stored student account passwords (email / university portal).
    // Keep it private and never change it after import, or those passwords can no longer be read.
    'app_key' => getenv('APP_KEY') ?: 'UL33qNMMkKZHF5uevGzmx1H/AG+vQRtIfyHWqcFwT3U=',

    // Keep false on the live site. True shows PHP errors on screen.
    'debug' => false,

    // Uploaded documents and meeting files. Leave empty to use portal/app/storage/files
    // (that folder is never served directly; files open only through the portal after sign-in).
    'storage_dir' => getenv('STORAGE_DIR') ?: '',

    // Largest file officers and students may upload, in MB. Your host's PHP limits
    // (upload_max_filesize and post_max_size in cPanel > MultiPHP INI Editor) must be at least this.
    'max_upload_mb' => 20,
];
