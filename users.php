<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role('admin');

$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$roles = ['admin' => 'Administrator', 'doctor' => 'Doctor', 'receptionist' => 'Receptionist'];
$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'delete') {
        if ($id === $me['id']) {
            flash('error', 'You cannot delete your own account.');
        } else {
            q('DELETE FROM users WHERE id = ?', [$id]);
            flash('success', 'User deleted.');
        }
        redirect('users.php');
    }

    $name = post('name');
    $email = post('email');
    $role = post('role');
    $password = (string) post('password');
    $doctorLink = (int) post('doctor_id');

    $error = null;
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'A name and valid email are required.';
    } elseif (!isset($roles[$role])) {
        $error = 'Invalid role.';
    } elseif (q('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $id])->fetch()) {
        $error = 'Another user already uses that email.';
    } elseif (($action === 'add' || $password !== '') && strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($action === 'edit' && $id === $me['id'] && $role !== 'admin') {
        $error = 'You cannot remove your own administrator role.';
    }

    if ($error) {
        flash('error', $error);
        redirect('users.php' . ($action === 'edit' ? '?action=edit&id=' . $id : ''));
    }

    db()->beginTransaction();
    if ($action === 'edit') {
        q('UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?', [$name, $email, $role, $id]);
        if ($password !== '') {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
        }
    } else {
        q('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)', [$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        $id = (int) db()->lastInsertId();
    }
    // Keep the doctor-profile link in sync with the role.
    q('UPDATE doctors SET user_id = NULL WHERE user_id = ?', [$id]);
    if ($role === 'doctor' && $doctorLink) {
        q('UPDATE doctors SET user_id = ? WHERE id = ?', [$id, $doctorLink]);
    }
    db()->commit();

    flash('success', $action === 'edit' ? 'User updated.' : 'User created.');
    redirect('users.php');
}

$editing = $action === 'edit' ? q('SELECT u.*, d.id AS doctor_id FROM users u LEFT JOIN doctors d ON d.user_id = u.id WHERE u.id = ?', [$id])->fetch() : null;
$users = q('SELECT u.*, d.name AS doctor_name FROM users u LEFT JOIN doctors d ON d.user_id = u.id ORDER BY u.name')->fetchAll();
$linkable = q('SELECT id, name, user_id FROM doctors WHERE user_id IS NULL' . ($editing ? ' OR user_id = ' . (int) $editing['id'] : '') . ' ORDER BY name')->fetchAll();

$pageTitle = 'Users';
require __DIR__ . '/includes/header.php';
?>
<div class="grid-2">
    <div class="card">
        <h2><?= $editing ? 'Edit User' : 'Add User' ?></h2>
        <form method="post" action="users.php?action=<?= $editing ? 'edit&id=' . $editing['id'] : 'add' ?>">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div><label>Name *</label><input name="name" value="<?= e($editing['name'] ?? '') ?>" required></div>
                <div><label>Email *</label><input type="email" name="email" value="<?= e($editing['email'] ?? '') ?>" required></div>
                <div><label>Role *</label>
                    <select name="role" required>
                        <?php foreach ($roles as $value => $label): ?><option value="<?= $value ?>"<?= selected($value, $editing['role'] ?? 'receptionist') ?>><?= $label ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label>Password <?= $editing ? '(leave blank to keep)' : '*' ?></label><input type="password" name="password" minlength="6" <?= $editing ? '' : 'required' ?>></div>
                <div class="full"><label>Linked doctor profile (doctor role only)</label>
                    <select name="doctor_id">
                        <option value="">— none —</option>
                        <?php foreach ($linkable as $d): ?><option value="<?= $d['id'] ?>"<?= selected($d['id'], $editing['doctor_id'] ?? '') ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn"><?= $editing ? 'Save changes' : 'Create user' ?></button>
                <?php if ($editing): ?><a class="btn btn-outline" href="users.php">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
    <div class="card">
        <h2>All Users</h2>
        <div class="table-wrap"><table>
            <tr><th>Name</th><th>Role</th><th></th></tr>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><strong><?= e($u['name']) ?></strong><br><small class="muted"><?= e($u['email']) ?><?= $u['doctor_name'] ? ' · ' . e($u['doctor_name']) : '' ?></small></td>
                    <td><?= badge($u['role']) ?></td>
                    <td class="actions">
                        <a class="btn btn-sm btn-outline" href="users.php?action=edit&id=<?= $u['id'] ?>">Edit</a>
                        <?php if ((int) $u['id'] !== $me['id']): ?>
                            <form method="post" action="users.php?action=delete&id=<?= $u['id'] ?>" data-confirm="Delete this user?">
                                <?= csrf_field() ?><button class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table></div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
