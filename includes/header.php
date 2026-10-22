<?php
/** @var string $pageTitle */
$user = current_user();
$current = basename($_SERVER['PHP_SELF']);
$nav = [
    ['index.php', 'Dashboard', ['admin', 'doctor', 'receptionist']],
    ['patients.php', 'Patients', ['admin', 'doctor', 'receptionist']],
    ['appointments.php', 'Appointments', ['admin', 'doctor', 'receptionist']],
    ['records.php', 'Medical Records', ['admin', 'doctor']],
    ['admissions.php', 'Admissions', ['admin', 'doctor', 'receptionist']],
    ['wards.php', 'Wards & Beds', ['admin', 'receptionist']],
    ['billing.php', 'Billing', ['admin', 'receptionist']],
    ['doctors.php', 'Doctors', ['admin', 'receptionist']],
    ['departments.php', 'Departments', ['admin']],
    ['users.php', 'Users', ['admin']],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($pageTitle ?? 'Dashboard') . ' · ' . APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand"><span class="logo">+</span> <?= e(APP_NAME) ?></div>
        <nav>
            <?php foreach ($nav as [$file, $label, $roles]): ?>
                <?php if (has_role(...$roles)): ?>
                    <a href="<?= $file ?>" class="<?= $current === $file ? 'active' : '' ?>"><?= e($label) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </aside>
    <div class="main">
        <header class="topbar">
            <h1><?= e($pageTitle ?? 'Dashboard') ?></h1>
            <div class="user">
                <span><?= e($user['name']) ?> <small class="muted">(<?= e(ucfirst($user['role'])) ?>)</small></span>
                <a href="profile.php">Profile</a>
                <a href="logout.php">Logout</a>
            </div>
        </header>
        <main class="content">
            <?php foreach (take_flashes() as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
            <?php endforeach; ?>
