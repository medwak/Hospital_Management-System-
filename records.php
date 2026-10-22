<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role('admin', 'doctor');

$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$doctorId = current_doctor_id();
$fields = ['patient_id', 'doctor_id', 'appointment_id', 'visit_date', 'symptoms', 'diagnosis', 'prescription', 'notes'];
$errors = [];

function find_record(int $id): array
{
    $r = q('SELECT r.*, p.first_name, p.last_name, p.patient_no, p.gender, p.date_of_birth, d.name AS doctor_name, d.specialization
            FROM medical_records r
            JOIN patients p ON p.id = r.patient_id
            LEFT JOIN doctors d ON d.id = r.doctor_id
            WHERE r.id = ?', [$id])->fetch();
    if (!$r) {
        flash('error', 'Record not found.');
        redirect('records.php');
    }
    return $r;
}

function can_modify_record(array $r, ?int $doctorId): bool
{
    return has_role('admin') || ($doctorId && (int) $r['doctor_id'] === $doctorId);
}

if ($doctorId === 0) {
    flash('error', 'Your user account is not linked to a doctor profile. Ask an administrator to link it.');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'delete') {
        require_role('admin');
        q('DELETE FROM medical_records WHERE id = ?', [$id]);
        flash('success', 'Record deleted.');
        redirect('records.php');
    }

    if (in_array($action, ['add', 'edit'], true)) {
        if ($action === 'edit' && !can_modify_record(find_record($id), $doctorId)) {
            flash('error', 'You can only edit records you wrote.');
            redirect('records.php');
        }
        $data = [];
        foreach ($fields as $f) {
            $data[$f] = post($f);
        }
        $data['patient_id'] = (int) $data['patient_id'];
        $data['doctor_id'] = $doctorId ?? ((int) $data['doctor_id'] ?: null);
        $data['appointment_id'] = (int) $data['appointment_id'] ?: null;

        if (!q('SELECT 1 FROM patients WHERE id = ?', [$data['patient_id']])->fetch()) {
            $errors[] = 'Please select a patient.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['visit_date'])) {
            $errors[] = 'Please choose a valid visit date.';
        }
        if ($data['diagnosis'] === '') {
            $errors[] = 'Diagnosis is required.';
        }

        if (!$errors) {
            if ($action === 'add') {
                q('INSERT INTO medical_records (' . implode(', ', $fields) . ') VALUES (?' . str_repeat(', ?', count($fields) - 1) . ')', array_values($data));
                $id = (int) db()->lastInsertId();
                if ($data['appointment_id']) {
                    q("UPDATE appointments SET status = 'completed' WHERE id = ?", [$data['appointment_id']]);
                }
                flash('success', 'Medical record saved.');
            } else {
                unset($data['appointment_id']);
                q('UPDATE medical_records SET ' . implode(' = ?, ', array_keys($data)) . ' = ? WHERE id = ?', array_merge(array_values($data), [$id]));
                flash('success', 'Medical record updated.');
            }
            redirect('records.php?action=view&id=' . $id);
        }
    }
}

if (in_array($action, ['add', 'edit'], true)) {
    $record = array_fill_keys($fields, '');
    $record['visit_date'] = date('Y-m-d');
    $record['patient_id'] = (int) ($_GET['patient_id'] ?? 0);
    $record['doctor_id'] = $doctorId;
    $appointment = null;

    if ($action === 'add' && !empty($_GET['appointment_id'])) {
        $appointment = q('SELECT a.*, p.first_name, p.last_name FROM appointments a JOIN patients p ON p.id = a.patient_id WHERE a.id = ?', [(int) $_GET['appointment_id']])->fetch();
        if ($appointment && ($doctorId === null || (int) $appointment['doctor_id'] === $doctorId)) {
            $record['appointment_id'] = $appointment['id'];
            $record['patient_id'] = $appointment['patient_id'];
            $record['doctor_id'] = $appointment['doctor_id'];
            $record['symptoms'] = $appointment['reason'];
        } else {
            $appointment = null;
        }
    }
    if ($action === 'edit') {
        $record = find_record($id);
        if (!can_modify_record($record, $doctorId)) {
            flash('error', 'You can only edit records you wrote.');
            redirect('records.php');
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $record = array_merge($record, array_intersect_key($_POST, array_flip($fields)));
    }

    $pageTitle = $action === 'add' ? 'New Medical Record' : 'Edit Medical Record';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
        <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
        <?php if ($appointment): ?><p class="muted">Consultation for appointment on <?= e(fmt_date($appointment['appointment_date']) . ' ' . $appointment['appointment_time']) ?>. Saving will mark the appointment as completed.</p><?php endif; ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="appointment_id" value="<?= e($record['appointment_id']) ?>">
            <div class="form-grid">
                <div><label>Patient *</label><select name="patient_id" required><option value="">Select patient…</option><?= patient_options((int) $record['patient_id']) ?></select></div>
                <?php if ($doctorId === null): ?>
                    <div><label>Doctor</label><select name="doctor_id"><option value="">—</option><?= doctor_options((int) $record['doctor_id']) ?></select></div>
                <?php endif; ?>
                <div><label>Visit date *</label><input type="date" name="visit_date" value="<?= e($record['visit_date']) ?>" required></div>
                <div class="full"><label>Symptoms / complaints</label><textarea name="symptoms"><?= e($record['symptoms']) ?></textarea></div>
                <div class="full"><label>Diagnosis *</label><textarea name="diagnosis" required><?= e($record['diagnosis']) ?></textarea></div>
                <div class="full"><label>Prescription</label><textarea name="prescription" placeholder="e.g. Paracetamol 500mg — 2 tablets 3x daily for 5 days"><?= e($record['prescription']) ?></textarea></div>
                <div class="full"><label>Notes</label><textarea name="notes"><?= e($record['notes']) ?></textarea></div>
            </div>
            <div class="form-actions">
                <button class="btn">Save record</button>
                <a class="btn btn-outline" href="records.php">Cancel</a>
            </div>
        </form>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'view') {
    $r = find_record($id);
    $pageTitle = 'Medical Record';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="toolbar no-print">
        <a href="records.php">&larr; All records</a>
        <div>
            <button class="btn btn-sm btn-outline" onclick="window.print()">Print</button>
            <?php if (can_modify_record($r, $doctorId)): ?><a class="btn btn-sm" href="records.php?action=edit&id=<?= $r['id'] ?>">Edit</a><?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="invoice-head">
            <div><h2><?= e(APP_NAME) ?></h2><div class="muted">Medical Record #<?= $r['id'] ?></div></div>
            <div><strong><?= e($r['doctor_name'] ?? '—') ?></strong><br><span class="muted"><?= e($r['specialization'] ?? '') ?></span></div>
        </div>
        <dl class="details">
            <dt>Patient</dt><dd><a href="patients.php?action=view&id=<?= $r['patient_id'] ?>"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></a> (<?= e($r['patient_no']) ?>)</dd>
            <dt>Gender / Age</dt><dd><?= e($r['gender']) ?> / <?= e(age($r['date_of_birth'])) ?></dd>
            <dt>Visit date</dt><dd><?= e(fmt_date($r['visit_date'])) ?></dd>
            <dt>Symptoms</dt><dd><?= nl2br(e($r['symptoms'] ?: '—')) ?></dd>
            <dt>Diagnosis</dt><dd><?= nl2br(e($r['diagnosis'])) ?></dd>
            <dt>Prescription</dt><dd><?= nl2br(e($r['prescription'] ?: '—')) ?></dd>
            <dt>Notes</dt><dd><?= nl2br(e($r['notes'] ?: '—')) ?></dd>
        </dl>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// List
$search = trim($_GET['q'] ?? '');
$mine = $doctorId !== null && !empty($_GET['mine']);
$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_no LIKE ? OR r.diagnosis LIKE ?)';
    array_push($params, ...array_fill(0, 4, '%' . $search . '%'));
}
if ($mine) {
    $where[] = 'r.doctor_id = ?';
    $params[] = $doctorId;
}
$records = q('SELECT r.*, p.first_name, p.last_name, p.patient_no, d.name AS doctor_name
              FROM medical_records r
              JOIN patients p ON p.id = r.patient_id
              LEFT JOIN doctors d ON d.id = r.doctor_id
              ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
              ORDER BY r.visit_date DESC, r.id DESC LIMIT 200', $params)->fetchAll();

$pageTitle = 'Medical Records';
require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <form method="get">
        <input name="q" value="<?= e($search) ?>" placeholder="Search patient or diagnosis…">
        <?php if ($doctorId !== null): ?>
            <label style="display:flex;align-items:center;gap:6px;margin:0"><input type="checkbox" name="mine" value="1" style="width:auto"<?= $mine ? ' checked' : '' ?>> Only mine</label>
        <?php endif; ?>
        <button class="btn btn-outline">Search</button>
    </form>
    <a class="btn" href="records.php?action=add">+ New record</a>
</div>
<div class="card">
    <?php if (!$records): ?>
        <p class="muted">No medical records found.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <tr><th>Date</th><th>Patient</th><th>Doctor</th><th>Diagnosis</th><th></th></tr>
            <?php foreach ($records as $r): ?>
                <tr>
                    <td><?= e(fmt_date($r['visit_date'])) ?></td>
                    <td><a href="patients.php?action=view&id=<?= $r['patient_id'] ?>"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></a><br><small class="muted"><?= e($r['patient_no']) ?></small></td>
                    <td><?= e($r['doctor_name'] ?? '—') ?></td>
                    <td><?= e(excerpt($r['diagnosis'])) ?></td>
                    <td class="actions">
                        <a class="btn btn-sm btn-outline" href="records.php?action=view&id=<?= $r['id'] ?>">View</a>
                        <?php if (can_modify_record($r, $doctorId)): ?><a class="btn btn-sm btn-outline" href="records.php?action=edit&id=<?= $r['id'] ?>">Edit</a><?php endif; ?>
                        <?php if (has_role('admin')): ?>
                            <form method="post" action="records.php?action=delete&id=<?= $r['id'] ?>" data-confirm="Delete this record?">
                                <?= csrf_field() ?><button class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table></div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
