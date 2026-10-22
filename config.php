<?php
/**
 * Application configuration.
 *
 * DB_DRIVER can be 'sqlite' (zero setup, file stored in data/) or 'mysql'.
 * Tables are created automatically on first run.
 */
define('APP_NAME', 'MediCare HMS');

define('DB_DRIVER', getenv('HMS_DB_DRIVER') ?: 'sqlite');

// SQLite settings
define('DB_SQLITE_PATH', __DIR__ . '/data/hospital.sqlite');

// MySQL settings (used when DB_DRIVER = 'mysql')
define('DB_HOST', getenv('HMS_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('HMS_DB_PORT') ?: '3306');
define('DB_NAME', getenv('HMS_DB_NAME') ?: 'hospital_db');
define('DB_USER', getenv('HMS_DB_USER') ?: 'root');
define('DB_PASS', getenv('HMS_DB_PASS') ?: '');

define('CURRENCY', '$');

date_default_timezone_set('UTC');
