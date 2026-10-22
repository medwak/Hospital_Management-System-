<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('index.php');
}

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = post('email');
    $user = q('SELECT * FROM users WHERE email = ?', [$email])->fetch();
    if ($user && password_verify((string) post('password'), $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']];
        redirect('index.php');
    }
    $error = 'Invalid email or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="login-page">
    <form class="login-box" method="post">
        <h1><span class="logo">+</span> <?= e(APP_NAME) ?></h1>
        <p class="muted">Sign in to continue</p>
        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <?= csrf_field() ?>
        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>
        <button class="btn" type="submit">Login</button>
        <div class="demo">
            <strong>Demo accounts</strong><br>
            Admin: admin@hospital.com / admin123<br>
            Doctor: doctor@hospital.com / doctor123<br>
            Reception: reception@hospital.com / reception123
        </div>
    </form>
</div>
</body>
</html>
