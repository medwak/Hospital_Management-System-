<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $row = q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetch();
    $new = (string) post('new_password');
    if (!password_verify((string) post('current_password'), $row['password_hash'])) {
        flash('error', 'Current password is incorrect.');
    } elseif (strlen($new) < 6) {
        flash('error', 'New password must be at least 6 characters.');
    } elseif ($new !== post('confirm_password')) {
        flash('error', 'New passwords do not match.');
    } else {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        flash('success', 'Password changed successfully.');
    }
    redirect('profile.php');
}

$pageTitle = 'My Profile';
require __DIR__ . '/includes/header.php';
?>
<div class="grid-2">
    <div class="card">
        <h2>Account</h2>
        <dl class="details">
            <dt>Name</dt><dd><?= e($user['name']) ?></dd>
            <dt>Email</dt><dd><?= e($user['email']) ?></dd>
            <dt>Role</dt><dd><?= badge($user['role']) ?></dd>
        </dl>
    </div>
    <div class="card">
        <h2>Change Password</h2>
        <form method="post">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="full"><label>Current password</label><input type="password" name="current_password" required></div>
                <div><label>New password</label><input type="password" name="new_password" minlength="6" required></div>
                <div><label>Confirm new password</label><input type="password" name="confirm_password" minlength="6" required></div>
            </div>
            <div class="form-actions"><button class="btn">Update password</button></div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
