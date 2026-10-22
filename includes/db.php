<?php
/**
 * Database connection and first-run installation.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if (DB_DRIVER === 'mysql') {
        $server = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, $options);
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', DB_NAME) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, $options);
    } else {
        $dir = dirname(DB_SQLITE_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . DB_SQLITE_PATH, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    install_if_needed($pdo);
    return $pdo;
}

/** Run a prepared query and return the statement. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function install_if_needed(PDO $pdo): void
{
    try {
        $pdo->query('SELECT 1 FROM users LIMIT 1');
        return;
    } catch (PDOException $e) {
        // Tables missing: install below.
    }

    $file = __DIR__ . '/../database/schema.' . (DB_DRIVER === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
    foreach (array_filter(array_map('trim', explode(';', file_get_contents($file)))) as $statement) {
        $pdo->exec($statement);
    }

    seed($pdo);
}

function seed(PDO $pdo): void
{
    $user = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
    $user->execute(['System Administrator', 'admin@hospital.com', password_hash('admin123', PASSWORD_DEFAULT), 'admin']);
    $user->execute(['Front Desk', 'reception@hospital.com', password_hash('reception123', PASSWORD_DEFAULT), 'receptionist']);
    $user->execute(['Dr. Jane Smith', 'doctor@hospital.com', password_hash('doctor123', PASSWORD_DEFAULT), 'doctor']);
    $doctorUserId = (int) $pdo->lastInsertId();

    $dept = $pdo->prepare('INSERT INTO departments (name, description) VALUES (?, ?)');
    foreach ([
        ['General Medicine', 'Outpatient consultations and general care'],
        ['Cardiology', 'Heart and blood vessel conditions'],
        ['Pediatrics', 'Medical care for infants and children'],
        ['Orthopedics', 'Bones, joints and muscles'],
        ['Maternity', 'Pregnancy, childbirth and postnatal care'],
    ] as $d) {
        $dept->execute($d);
    }

    $pdo->prepare('INSERT INTO doctors (user_id, department_id, name, specialization, phone, email, consultation_fee) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$doctorUserId, 1, 'Dr. Jane Smith', 'General Physician', '0700000001', 'doctor@hospital.com', 50]);

    $ward = $pdo->prepare('INSERT INTO wards (name, type, daily_rate) VALUES (?, ?, ?)');
    $bed = $pdo->prepare('INSERT INTO beds (ward_id, bed_number) VALUES (?, ?)');
    foreach ([['General Ward A', 'General', 30, 'A', 6], ['Private Wing', 'Private', 120, 'P', 3], ['ICU', 'Intensive Care', 400, 'ICU-', 2]] as [$name, $type, $rate, $prefix, $count]) {
        $ward->execute([$name, $type, $rate]);
        $wardId = (int) $pdo->lastInsertId();
        for ($i = 1; $i <= $count; $i++) {
            $bed->execute([$wardId, $prefix . $i]);
        }
    }
}
