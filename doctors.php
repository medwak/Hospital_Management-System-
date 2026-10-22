<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role('admin', 'receptionist');

$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$fields = ['name', 'department_id', 'specialization', 'phone', 'email', 'consultation_fee'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('admin');

    if ($action === 'delete') {
        q('DELETE FROM doctors WHERE id = ?', [$id]);
        flash('success', 'Doctor removed.');
        redirect('doctors.php');
    }

    if (in_array($action, ['add', 'edit'], true)) {
        $data = [];
        foreach ($fields as $f) {
            $data[$f] = post($f);
        }
        $data['department_id'] = (int) $data['department_id'] ?: null;
        $data['consultation_fee'] = max(0, (float) $data['consultation_fee']);
        $createLogin = $action === 'add' && post('create_login') === '1';
        $password = (string) post('password');

        if ($data['name'] === '') {
            $errors[] = 'Name is required.';
        }
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address is not valid.';
        }
        if ($createLogin) {
            if ($data['email'] === '') {
                $errors[] = 'An email is required to create a login account.';
            } elseif (q('SELECT 1 FROM users WHERE email = ?', [$data['email']])->fetch()) {
                $errors[] = 'A user with that email already exists.';
            }
            if (strlen($password) < 6) {
                $errors[] = 'Password must be at least 6 characters.';
            }
        }

        if (!$errors) {
            db()->beginTransaction();
            if ($action === 'add') {
                $userId = null;
                if ($createLogin) {
                    q('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)', [$data['name'], $data['email'], password_hash($password, PASSWORD_DEFAULT), 'doctor']);
                    $userId = (int) db()->lastInsertId();
                }
                q('INSERT INTO doctors (user_id, ' . implode(', ', $fields) . ') VALUES (?' . str_repeat(', ?', count($fields)) . ')', array_merge([$userId], array_values($data)));
                flash('success', 'Doctor added' . ($createLogin ? ' with a login account.' : '.'));
            } else {
                q('UPDATE doctors SET ' . implode(' = ?, ', $fields) . ' = ? WHERE id = ?', array_merge(array_values($data), [$id]));
                flash('success', 'Doctor updated.');
            }
            db()->commit();
            redirect('doctors.php');
        }
    }
}

$departments = q('SELECT id, name FROM departments ORDER BY name')->fetchAll();

if (in_array($action, ['add', 'edit'], true)) {
    require_role('admin');
    $doctor = array_fill_keys($fields, '');
    $doctor['consultation_fee'] = '0';
    if ($action === 'edit') {
        $doctor = q('SELECT * FROM doctors WHERE id = ?', [$id])->fetch() ?: redirect('doctors.php');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $doctor = array_merge($doctor, array_intersect_key($_POST, array_flip($fields)));
    }

    $pageTitle = $action === 'add' ? 'Add Doctor' : 'Edit Doctor';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
        <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div><label>Full name *</label><input name="name" value="<?= e($doctor['name']) ?>" placeholder="Dr. …" required></div>
                <div><label>Department</label>
                    <select name="department_id">
                        <option value="">—</option>
                        <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"<?= selected($d['id'], $doctor['department_id']) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label>Specialization</label><input name="specialization" value="<?= e($doctor['specialization']) ?>"></div>
                <div><label>Consultation fee</label><input type="number" step="0.01" min="0" name="consultation_fee" value="<?= e($doctor['consultation_fee']) ?>"></div>
                <div><label>Phone</label><input name="phone" value="<?= e($doctor['phone']) ?>"></div>
                <div><label>Email</label><input type="email" name="email" value="<?= e($doctor['email']) ?>"></div>
                <?php if ($action === 'add'): ?>
                    <div class="full"><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="create_login" value="1" style="width:auto"<?= post('create_login') === '1' ? ' checked' : '' ?>> Create a login account for this doctor (uses the email above)</label></div>
                    <div><label>Initial password</label><input type="password" name="password" minlength="6"></div>
                <?php endif; ?>
            </div>
            <div class="form-actions">
                <button class="btn"><?= $action === 'add' ? 'Add doctor' : 'Save changes' ?></button>
                <a class="btn btn-outline" href="doctors.php">Cancel</a>
            </div>
        </form>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$deptFilter = (int) ($_GET['department_id'] ?? 0);
$doctors = q('SELECT d.*, dep.name AS department_name, u.email AS login_email,
                (SELECT COUNT(*) FROM appointments a WHERE a.doctor_id = d.id AND a.status = \'scheduled\') AS upcoming
              FROM doctors d
              LEFT JOIN departments dep ON dep.id = d.department_id
              LEFT JOIN users u ON u.id = d.user_id
              ' . ($deptFilter ? 'WHERE d.department_id = ?' : '') . '
              ORDER BY d.name', $deptFilter ? [$deptFilter] : [])->fetchAll();

$pageTitle = 'Doctors';
require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <form method="get">
        <select name="department_id">
            <option value="">All departments</option>
            <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"<?= selected($d['id'], $deptFilter) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-outline">Filter</button>
    </form>
    <?php if (has_role('admin')): ?><a class="btn" href="doctors.php?action=add">+ Add doctor</a><?php endif; ?>
</div>
<div class="card">
    <?php if (!$doctors): ?>
        <p class="muted">No doctors found.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <tr><th>Name</th><th>Department</th><th>Specialization</th><th>Contact</th><th class="text-right">Fee</th><th>Upcoming</th><th>Login</th><?php if (has_role('admin')): ?><th></th><?php endif; ?></tr>
            <?php foreach ($doctors as $d): ?>
                <tr>
                    <td><strong><?= e($d['name']) ?></strong></td>
                    <td><?= e($d['department_name'] ?? '—') ?></td>
                    <td><?= e($d['specialization'] ?: '—') ?></td>
                    <td><?= e($d['phone']) ?><br><small class="muted"><?= e($d['email']) ?></small></td>
                    <td class="text-right"><?= e(money($d['consultation_fee'])) ?></td>
                    <td><a href="appointments.php?doctor_id=<?= $d['id'] ?>&status=scheduled"><?= (int) $d['upcoming'] ?></a></td>
                    <td><?= $d['login_email'] ? badge('doctor') : '<span class="muted">none</span>' ?></td>
                    <?php if (has_role('admin')): ?>
                        <td class="actions">
                            <a class="btn btn-sm btn-outline" href="doctors.php?action=edit&id=<?= $d['id'] ?>">Edit</a>
                            <form method="post" action="doctors.php?action=delete&id=<?= $d['id'] ?>" data-confirm="Remove this doctor? Their appointments will also be deleted.">
                                <?= csrf_field() ?><button class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </table></div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
