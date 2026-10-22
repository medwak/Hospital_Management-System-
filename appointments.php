<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$doctorId = current_doctor_id();
$canManage = has_role('admin', 'receptionist');
$errors = [];

/** Load an appointment the current user may act on. */
function find_appointment(int $id, ?int $doctorId): array
{
    $a = q('SELECT * FROM appointments WHERE id = ?', [$id])->fetch();
    if (!$a || ($doctorId !== null && (int) $a['doctor_id'] !== $doctorId)) {
        flash('error', 'Appointment not found.');
        redirect('appointments.php');
    }
    return $a;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'status') {
        $appt = find_appointment($id, $doctorId);
        $status = post('status');
        if (in_array($status, ['scheduled', 'completed', 'cancelled'], true)) {
            q('UPDATE appointments SET status = ? WHERE id = ?', [$status, $id]);
            flash('success', 'Appointment marked as ' . $status . '.');
        }
        $return = (string) post('return');
        redirect(str_starts_with($return, 'appointments.php') ? $return : 'appointments.php');
    }

    if ($action === 'delete') {
        require_role('admin');
        q('DELETE FROM appointments WHERE id = ?', [$id]);
        flash('success', 'Appointment deleted.');
        redirect('appointments.php');
    }

    if (in_array($action, ['add', 'edit'], true)) {
        require_role('admin', 'receptionist');
        $data = [
            'patient_id' => (int) post('patient_id'),
            'doctor_id' => (int) post('doctor_id'),
            'appointment_date' => post('appointment_date'),
            'appointment_time' => post('appointment_time'),
            'reason' => post('reason'),
        ];
        if (!q('SELECT 1 FROM patients WHERE id = ?', [$data['patient_id']])->fetch()) {
            $errors[] = 'Please select a patient.';
        }
        if (!q('SELECT 1 FROM doctors WHERE id = ?', [$data['doctor_id']])->fetch()) {
            $errors[] = 'Please select a doctor.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['appointment_date'])) {
            $errors[] = 'Please choose a valid date.';
        } elseif ($action === 'add' && $data['appointment_date'] < date('Y-m-d')) {
            $errors[] = 'Appointments cannot be booked in the past.';
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $data['appointment_time'])) {
            $errors[] = 'Please choose a valid time.';
        }
        if (!$errors) {
            $clash = q("SELECT 1 FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status <> 'cancelled' AND id <> ?",
                [$data['doctor_id'], $data['appointment_date'], $data['appointment_time'], $id])->fetch();
            if ($clash) {
                $errors[] = 'The doctor already has an appointment at that date and time.';
            }
        }

        if (!$errors) {
            if ($action === 'add') {
                q('INSERT INTO appointments (patient_id, doctor_id, appointment_date, appointment_time, reason) VALUES (?, ?, ?, ?, ?)', array_values($data));
                flash('success', 'Appointment booked.');
            } else {
                q('UPDATE appointments SET patient_id = ?, doctor_id = ?, appointment_date = ?, appointment_time = ?, reason = ? WHERE id = ?', array_merge(array_values($data), [$id]));
                flash('success', 'Appointment updated.');
            }
            redirect('appointments.php');
        }
    }
}

if (in_array($action, ['add', 'edit'], true)) {
    require_role('admin', 'receptionist');
    $appt = ['patient_id' => (int) ($_GET['patient_id'] ?? 0), 'doctor_id' => 0, 'appointment_date' => date('Y-m-d'), 'appointment_time' => '09:00', 'reason' => ''];
    if ($action === 'edit') {
        $appt = find_appointment($id, null);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $appt = array_merge($appt, $data);
    }

    $pageTitle = $action === 'add' ? 'Book Appointment' : 'Edit Appointment';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
        <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div><label>Patient *</label><select name="patient_id" required><option value="">Select patient…</option><?= patient_options((int) $appt['patient_id']) ?></select></div>
                <div><label>Doctor *</label><select name="doctor_id" required><option value="">Select doctor…</option><?= doctor_options((int) $appt['doctor_id']) ?></select></div>
                <div><label>Date *</label><input type="date" name="appointment_date" value="<?= e($appt['appointment_date']) ?>" required></div>
                <div><label>Time *</label><input type="time" name="appointment_time" value="<?= e($appt['appointment_time']) ?>" required></div>
                <div class="full"><label>Reason for visit</label><textarea name="reason"><?= e($appt['reason']) ?></textarea></div>
            </div>
            <div class="form-actions">
                <button class="btn"><?= $action === 'add' ? 'Book appointment' : 'Save changes' ?></button>
                <a class="btn btn-outline" href="appointments.php">Cancel</a>
            </div>
        </form>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// List
$filterDate = $_GET['date'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterDoctor = (int) ($_GET['doctor_id'] ?? 0);

$where = [];
$params = [];
if ($doctorId !== null) {
    $where[] = 'a.doctor_id = ?';
    $params[] = $doctorId;
} elseif ($filterDoctor) {
    $where[] = 'a.doctor_id = ?';
    $params[] = $filterDoctor;
}
if ($filterDate !== '') {
    $where[] = 'a.appointment_date = ?';
    $params[] = $filterDate;
}
if (in_array($filterStatus, ['scheduled', 'completed', 'cancelled'], true)) {
    $where[] = 'a.status = ?';
    $params[] = $filterStatus;
}
$appointments = q('SELECT a.*, p.first_name, p.last_name, p.patient_no, d.name AS doctor_name
                   FROM appointments a
                   JOIN patients p ON p.id = a.patient_id
                   JOIN doctors d ON d.id = a.doctor_id
                   ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
                   ORDER BY a.appointment_date DESC, a.appointment_time DESC
                   LIMIT 200', $params)->fetchAll();

$returnUrl = 'appointments.php?' . http_build_query(array_filter(['date' => $filterDate, 'status' => $filterStatus, 'doctor_id' => $filterDoctor ?: '']));

$pageTitle = $doctorId !== null ? 'My Appointments' : 'Appointments';
require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <form method="get">
        <input type="date" name="date" value="<?= e($filterDate) ?>">
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach (['scheduled', 'completed', 'cancelled'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $filterStatus) ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
        </select>
        <?php if ($doctorId === null): ?>
            <select name="doctor_id"><option value="">All doctors</option><?= doctor_options($filterDoctor) ?></select>
        <?php endif; ?>
        <button class="btn btn-outline">Filter</button>
        <a class="btn btn-outline" href="appointments.php?date=<?= date('Y-m-d') ?>">Today</a>
        <a class="btn btn-outline" href="appointments.php">Reset</a>
    </form>
    <?php if ($canManage): ?><a class="btn" href="appointments.php?action=add">+ Book appointment</a><?php endif; ?>
</div>
<div class="card">
    <?php if (!$appointments): ?>
        <p class="muted">No appointments found.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <tr><th>Date</th><th>Time</th><th>Patient</th><?php if ($doctorId === null): ?><th>Doctor</th><?php endif; ?><th>Reason</th><th>Status</th><th></th></tr>
            <?php foreach ($appointments as $a): ?>
                <tr>
                    <td><?= e(fmt_date($a['appointment_date'])) ?></td>
                    <td><?= e($a['appointment_time']) ?></td>
                    <td><a href="patients.php?action=view&id=<?= $a['patient_id'] ?>"><?= e($a['first_name'] . ' ' . $a['last_name']) ?></a><br><small class="muted"><?= e($a['patient_no']) ?></small></td>
                    <?php if ($doctorId === null): ?><td><?= e($a['doctor_name']) ?></td><?php endif; ?>
                    <td><?= e($a['reason']) ?></td>
                    <td><?= badge($a['status']) ?></td>
                    <td class="actions">
                        <?php if ($a['status'] === 'scheduled'): ?>
                            <?php if (has_role('admin', 'doctor')): ?>
                                <a class="btn btn-sm" href="records.php?action=add&appointment_id=<?= $a['id'] ?>">Consult</a>
                            <?php endif; ?>
                            <form method="post" action="appointments.php?action=status&id=<?= $a['id'] ?>">
                                <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                                <input type="hidden" name="status" value="completed"><button class="btn btn-sm btn-outline">Complete</button>
                            </form>
                            <form method="post" action="appointments.php?action=status&id=<?= $a['id'] ?>" data-confirm="Cancel this appointment?">
                                <?= csrf_field() ?><input type="hidden" name="return" value="<?= e($returnUrl) ?>">
                                <input type="hidden" name="status" value="cancelled"><button class="btn btn-sm btn-outline">Cancel</button>
                            </form>
                            <?php if ($canManage): ?><a class="btn btn-sm btn-outline" href="appointments.php?action=edit&id=<?= $a['id'] ?>">Edit</a><?php endif; ?>
                        <?php endif; ?>
                        <?php if (has_role('admin')): ?>
                            <form method="post" action="appointments.php?action=delete&id=<?= $a['id'] ?>" data-confirm="Delete this appointment?">
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
