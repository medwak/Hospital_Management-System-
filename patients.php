<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$canEdit = has_role('admin', 'receptionist');
$fields = ['first_name', 'last_name', 'gender', 'date_of_birth', 'blood_group', 'phone', 'email', 'address', 'emergency_contact'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'delete') {
        require_role('admin');
        q('DELETE FROM patients WHERE id = ?', [$id]);
        flash('success', 'Patient deleted.');
        redirect('patients.php');
    }

    if (in_array($action, ['add', 'edit'], true)) {
        require_role('admin', 'receptionist');
        $data = [];
        foreach ($fields as $f) {
            $data[$f] = post($f);
        }
        if ($data['first_name'] === '' || $data['last_name'] === '') {
            $errors[] = 'First and last name are required.';
        }
        if (!in_array($data['gender'], ['Male', 'Female', 'Other'], true)) {
            $errors[] = 'Please select a gender.';
        }
        if ($data['date_of_birth'] !== '' && strtotime($data['date_of_birth']) > time()) {
            $errors[] = 'Date of birth cannot be in the future.';
        }
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address is not valid.';
        }
        $data['date_of_birth'] = $data['date_of_birth'] ?: null;

        if (!$errors) {
            if ($action === 'add') {
                q('INSERT INTO patients (patient_no, ' . implode(', ', $fields) . ') VALUES (?' . str_repeat(', ?', count($fields)) . ')',
                    array_merge([next_code('patients', 'patient_no', 'PT-')], array_values($data)));
                $id = (int) db()->lastInsertId();
                flash('success', 'Patient registered successfully.');
            } else {
                q('UPDATE patients SET ' . implode(' = ?, ', $fields) . ' = ? WHERE id = ?', array_merge(array_values($data), [$id]));
                flash('success', 'Patient details updated.');
            }
            redirect('patients.php?action=view&id=' . $id);
        }
    }
}

if (in_array($action, ['add', 'edit'], true)) {
    require_role('admin', 'receptionist');
    $patient = array_fill_keys($fields, '');
    if ($action === 'edit') {
        $patient = q('SELECT * FROM patients WHERE id = ?', [$id])->fetch() ?: redirect('patients.php');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $patient = array_merge($patient, array_intersect_key($_POST, array_flip($fields)));
    }

    $pageTitle = $action === 'add' ? 'Register Patient' : 'Edit Patient';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
        <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div><label>First name *</label><input name="first_name" value="<?= e($patient['first_name']) ?>" required></div>
                <div><label>Last name *</label><input name="last_name" value="<?= e($patient['last_name']) ?>" required></div>
                <div><label>Gender *</label>
                    <select name="gender" required>
                        <option value="">Select…</option>
                        <?php foreach (['Male', 'Female', 'Other'] as $g): ?><option<?= selected($g, $patient['gender']) ?>><?= $g ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label>Date of birth</label><input type="date" name="date_of_birth" value="<?= e($patient['date_of_birth']) ?>" max="<?= date('Y-m-d') ?>"></div>
                <div><label>Blood group</label>
                    <select name="blood_group">
                        <option value="">Unknown</option>
                        <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $b): ?><option<?= selected($b, $patient['blood_group']) ?>><?= $b ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label>Phone</label><input name="phone" value="<?= e($patient['phone']) ?>"></div>
                <div><label>Email</label><input type="email" name="email" value="<?= e($patient['email']) ?>"></div>
                <div><label>Emergency contact</label><input name="emergency_contact" value="<?= e($patient['emergency_contact']) ?>" placeholder="Name & phone"></div>
                <div class="full"><label>Address</label><textarea name="address"><?= e($patient['address']) ?></textarea></div>
            </div>
            <div class="form-actions">
                <button class="btn"><?= $action === 'add' ? 'Register patient' : 'Save changes' ?></button>
                <a class="btn btn-outline" href="patients.php">Cancel</a>
            </div>
        </form>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'view') {
    $patient = q('SELECT * FROM patients WHERE id = ?', [$id])->fetch() ?: redirect('patients.php');
    $appointments = q('SELECT a.*, d.name AS doctor_name FROM appointments a JOIN doctors d ON d.id = a.doctor_id WHERE a.patient_id = ? ORDER BY a.appointment_date DESC, a.appointment_time DESC', [$id])->fetchAll();
    $records = q('SELECT r.*, d.name AS doctor_name FROM medical_records r LEFT JOIN doctors d ON d.id = r.doctor_id WHERE r.patient_id = ? ORDER BY r.visit_date DESC, r.id DESC', [$id])->fetchAll();
    $admissions = q('SELECT a.*, b.bed_number, w.name AS ward_name FROM admissions a JOIN beds b ON b.id = a.bed_id JOIN wards w ON w.id = b.ward_id WHERE a.patient_id = ? ORDER BY a.admitted_at DESC', [$id])->fetchAll();
    $invoices = has_role('admin', 'receptionist') ? q('SELECT * FROM invoices WHERE patient_id = ? ORDER BY id DESC', [$id])->fetchAll() : [];

    $pageTitle = $patient['first_name'] . ' ' . $patient['last_name'];
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="toolbar">
        <a href="patients.php">&larr; All patients</a>
        <div>
            <?php if ($canEdit): ?><a class="btn btn-sm btn-outline" href="appointments.php?action=add&patient_id=<?= $id ?>">Book appointment</a><?php endif; ?>
            <?php if (has_role('admin', 'doctor')): ?><a class="btn btn-sm btn-outline" href="records.php?action=add&patient_id=<?= $id ?>">Add medical record</a><?php endif; ?>
            <?php if ($canEdit): ?>
                <a class="btn btn-sm btn-outline" href="billing.php?action=add&patient_id=<?= $id ?>">Create invoice</a>
                <a class="btn btn-sm" href="patients.php?action=edit&id=<?= $id ?>">Edit</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <h2>Patient Details</h2>
        <dl class="details">
            <dt>Patient No.</dt><dd><?= e($patient['patient_no']) ?></dd>
            <dt>Gender</dt><dd><?= e($patient['gender']) ?></dd>
            <dt>Date of birth</dt><dd><?= e(fmt_date($patient['date_of_birth'])) ?> (age <?= e(age($patient['date_of_birth'])) ?>)</dd>
            <dt>Blood group</dt><dd><?= e($patient['blood_group'] ?: 'Unknown') ?></dd>
            <dt>Phone</dt><dd><?= e($patient['phone'] ?: '—') ?></dd>
            <dt>Email</dt><dd><?= e($patient['email'] ?: '—') ?></dd>
            <dt>Address</dt><dd><?= nl2br(e($patient['address'] ?: '—')) ?></dd>
            <dt>Emergency contact</dt><dd><?= e($patient['emergency_contact'] ?: '—') ?></dd>
            <dt>Registered</dt><dd><?= e(fmt_datetime($patient['created_at'])) ?></dd>
        </dl>
    </div>

    <div class="card">
        <h2>Medical History</h2>
        <?php if (!$records): ?><p class="muted">No medical records yet.</p><?php else: ?>
            <div class="table-wrap"><table>
                <tr><th>Date</th><th>Doctor</th><th>Diagnosis</th><th>Prescription</th><th></th></tr>
                <?php foreach ($records as $r): ?>
                    <tr>
                        <td><?= e(fmt_date($r['visit_date'])) ?></td>
                        <td><?= e($r['doctor_name'] ?? '—') ?></td>
                        <td><?= nl2br(e($r['diagnosis'])) ?></td>
                        <td><?= nl2br(e($r['prescription'])) ?></td>
                        <td><?php if (has_role('admin', 'doctor')): ?><a href="records.php?action=view&id=<?= $r['id'] ?>">View</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </table></div>
        <?php endif; ?>
    </div>

    <div class="grid-2">
        <div class="card">
            <h2>Appointments</h2>
            <?php if (!$appointments): ?><p class="muted">No appointments.</p><?php else: ?>
                <div class="table-wrap"><table>
                    <tr><th>Date</th><th>Doctor</th><th>Status</th></tr>
                    <?php foreach ($appointments as $a): ?>
                        <tr><td><?= e(fmt_date($a['appointment_date']) . ' ' . $a['appointment_time']) ?></td><td><?= e($a['doctor_name']) ?></td><td><?= badge($a['status']) ?></td></tr>
                    <?php endforeach; ?>
                </table></div>
            <?php endif; ?>
        </div>
        <div class="card">
            <h2>Admissions</h2>
            <?php if (!$admissions): ?><p class="muted">No admissions.</p><?php else: ?>
                <div class="table-wrap"><table>
                    <tr><th>Admitted</th><th>Ward / Bed</th><th>Status</th></tr>
                    <?php foreach ($admissions as $a): ?>
                        <tr><td><?= e(fmt_datetime($a['admitted_at'])) ?></td><td><?= e($a['ward_name'] . ' / ' . $a['bed_number']) ?></td><td><?= badge($a['status']) ?></td></tr>
                    <?php endforeach; ?>
                </table></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (has_role('admin', 'receptionist')): ?>
        <div class="card">
            <h2>Invoices</h2>
            <?php if (!$invoices): ?><p class="muted">No invoices.</p><?php else: ?>
                <div class="table-wrap"><table>
                    <tr><th>Invoice No.</th><th>Date</th><th class="text-right">Total</th><th>Status</th></tr>
                    <?php foreach ($invoices as $inv): ?>
                        <tr><td><a href="billing.php?action=view&id=<?= $inv['id'] ?>"><?= e($inv['invoice_no']) ?></a></td><td><?= e(fmt_date($inv['issue_date'])) ?></td><td class="text-right"><?= e(money($inv['total'])) ?></td><td><?= badge($inv['status']) ?></td></tr>
                    <?php endforeach; ?>
                </table></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// List
$search = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$where = '';
$params = [];
if ($search !== '') {
    $where = 'WHERE first_name LIKE ? OR last_name LIKE ? OR patient_no LIKE ? OR phone LIKE ?';
    $params = array_fill(0, 4, '%' . $search . '%');
}
$total = (int) q("SELECT COUNT(*) AS c FROM patients $where", $params)->fetch()['c'];
$pages = max(1, (int) ceil($total / $perPage));
$patients = q("SELECT * FROM patients $where ORDER BY id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params)->fetchAll();

$pageTitle = 'Patients';
require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <form method="get">
        <input name="q" value="<?= e($search) ?>" placeholder="Search name, number, phone…">
        <button class="btn btn-outline">Search</button>
        <?php if ($search !== ''): ?><a class="btn btn-outline" href="patients.php">Clear</a><?php endif; ?>
    </form>
    <?php if ($canEdit): ?><a class="btn" href="patients.php?action=add">+ Register patient</a><?php endif; ?>
</div>
<div class="card">
    <?php if (!$patients): ?>
        <p class="muted">No patients found.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <tr><th>Patient No.</th><th>Name</th><th>Gender</th><th>Age</th><th>Blood</th><th>Phone</th><th></th></tr>
            <?php foreach ($patients as $p): ?>
                <tr>
                    <td><?= e($p['patient_no']) ?></td>
                    <td><a href="patients.php?action=view&id=<?= $p['id'] ?>"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></a></td>
                    <td><?= e($p['gender']) ?></td>
                    <td><?= e(age($p['date_of_birth'])) ?></td>
                    <td><?= e($p['blood_group'] ?: '—') ?></td>
                    <td><?= e($p['phone']) ?></td>
                    <td class="actions">
                        <a class="btn btn-sm btn-outline" href="patients.php?action=view&id=<?= $p['id'] ?>">View</a>
                        <?php if ($canEdit): ?><a class="btn btn-sm btn-outline" href="patients.php?action=edit&id=<?= $p['id'] ?>">Edit</a><?php endif; ?>
                        <?php if (has_role('admin')): ?>
                            <form method="post" action="patients.php?action=delete&id=<?= $p['id'] ?>" data-confirm="Delete this patient and all their records?">
                                <?= csrf_field() ?><button class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table></div>
        <p class="muted">
            <?= $total ?> patient(s) · Page <?= $page ?> of <?= $pages ?>
            <?php if ($page > 1): ?> · <a href="?q=<?= urlencode($search) ?>&page=<?= $page - 1 ?>">&larr; Prev</a><?php endif; ?>
            <?php if ($page < $pages): ?> · <a href="?q=<?= urlencode($search) ?>&page=<?= $page + 1 ?>">Next &rarr;</a><?php endif; ?>
        </p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
