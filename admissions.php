<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$doctorId = current_doctor_id();
$canManage = has_role('admin', 'receptionist');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('admin', 'receptionist');

    if ($action === 'admit') {
        $patientId = (int) post('patient_id');
        $bedId = (int) post('bed_id');
        $doc = (int) post('doctor_id') ?: null;

        if (!q('SELECT 1 FROM patients WHERE id = ?', [$patientId])->fetch()) {
            $errors[] = 'Please select a patient.';
        } elseif (q("SELECT 1 FROM admissions WHERE patient_id = ? AND status = 'admitted'", [$patientId])->fetch()) {
            $errors[] = 'This patient is already admitted.';
        }
        if (!q("SELECT 1 FROM beds WHERE id = ? AND status = 'available'", [$bedId])->fetch()) {
            $errors[] = 'Please select an available bed.';
        }

        if (!$errors) {
            db()->beginTransaction();
            q('INSERT INTO admissions (patient_id, bed_id, doctor_id, admitted_at, reason) VALUES (?, ?, ?, ?, ?)',
                [$patientId, $bedId, $doc, date('Y-m-d H:i:s'), post('reason')]);
            q("UPDATE beds SET status = 'occupied' WHERE id = ?", [$bedId]);
            db()->commit();
            flash('success', 'Patient admitted.');
            redirect('admissions.php');
        }
        $action = 'admit';
    }

    if ($action === 'discharge') {
        $adm = q("SELECT * FROM admissions WHERE id = ? AND status = 'admitted'", [$id])->fetch();
        if ($adm) {
            db()->beginTransaction();
            q("UPDATE admissions SET status = 'discharged', discharged_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $id]);
            q("UPDATE beds SET status = 'available' WHERE id = ?", [$adm['bed_id']]);
            db()->commit();
            flash('success', 'Patient discharged. You can now create the invoice for the stay.');
            redirect('billing.php?action=add&admission_id=' . $id);
        }
        redirect('admissions.php');
    }
}

if ($action === 'admit') {
    require_role('admin', 'receptionist');
    $availableBeds = q("SELECT b.id, b.bed_number, w.name AS ward_name, w.daily_rate FROM beds b JOIN wards w ON w.id = b.ward_id WHERE b.status = 'available' ORDER BY w.name, b.id")->fetchAll();

    $pageTitle = 'Admit Patient';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
        <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
        <?php if (!$availableBeds): ?>
            <div class="alert alert-error">There are no available beds. Discharge a patient or add beds under Wards &amp; Beds.</div>
        <?php endif; ?>
        <form method="post" action="admissions.php?action=admit">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div><label>Patient *</label><select name="patient_id" required><option value="">Select patient…</option><?= patient_options((int) ($_POST['patient_id'] ?? $_GET['patient_id'] ?? 0)) ?></select></div>
                <div><label>Bed *</label>
                    <select name="bed_id" required>
                        <option value="">Select bed…</option>
                        <?php foreach ($availableBeds as $b): ?>
                            <option value="<?= $b['id'] ?>"<?= selected($b['id'], $_POST['bed_id'] ?? '') ?>><?= e($b['ward_name'] . ' — ' . $b['bed_number'] . ' (' . money($b['daily_rate']) . '/day)') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Attending doctor</label><select name="doctor_id"><option value="">—</option><?= doctor_options((int) ($_POST['doctor_id'] ?? 0)) ?></select></div>
                <div class="full"><label>Reason for admission</label><textarea name="reason"><?= e($_POST['reason'] ?? '') ?></textarea></div>
            </div>
            <div class="form-actions">
                <button class="btn">Admit patient</button>
                <a class="btn btn-outline" href="admissions.php">Cancel</a>
            </div>
        </form>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// List
$status = ($_GET['status'] ?? 'admitted') === 'discharged' ? 'discharged' : 'admitted';
$where = 'a.status = ?';
$params = [$status];
if ($doctorId !== null) {
    $where .= ' AND a.doctor_id = ?';
    $params[] = $doctorId;
}
$admissions = q("SELECT a.*, p.first_name, p.last_name, p.patient_no, b.bed_number, w.name AS ward_name, w.daily_rate, d.name AS doctor_name
                 FROM admissions a
                 JOIN patients p ON p.id = a.patient_id
                 JOIN beds b ON b.id = a.bed_id
                 JOIN wards w ON w.id = b.ward_id
                 LEFT JOIN doctors d ON d.id = a.doctor_id
                 WHERE $where
                 ORDER BY a.admitted_at DESC LIMIT 200", $params)->fetchAll();

$pageTitle = 'Admissions';
require __DIR__ . '/includes/header.php';
?>
<div class="toolbar">
    <div>
        <a class="btn <?= $status === 'admitted' ? '' : 'btn-outline' ?>" href="admissions.php?status=admitted">Currently admitted</a>
        <a class="btn <?= $status === 'discharged' ? '' : 'btn-outline' ?>" href="admissions.php?status=discharged">Discharged</a>
    </div>
    <?php if ($canManage): ?><a class="btn" href="admissions.php?action=admit">+ Admit patient</a><?php endif; ?>
</div>
<div class="card">
    <?php if (!$admissions): ?>
        <p class="muted">No <?= $status ?> patients.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <tr><th>Patient</th><th>Ward / Bed</th><th>Doctor</th><th>Admitted</th><?php if ($status === 'discharged'): ?><th>Discharged</th><?php endif; ?><th>Reason</th><th></th></tr>
            <?php foreach ($admissions as $a): ?>
                <tr>
                    <td><a href="patients.php?action=view&id=<?= $a['patient_id'] ?>"><?= e($a['first_name'] . ' ' . $a['last_name']) ?></a><br><small class="muted"><?= e($a['patient_no']) ?></small></td>
                    <td><?= e($a['ward_name'] . ' / ' . $a['bed_number']) ?></td>
                    <td><?= e($a['doctor_name'] ?? '—') ?></td>
                    <td><?= e(fmt_datetime($a['admitted_at'])) ?></td>
                    <?php if ($status === 'discharged'): ?><td><?= e(fmt_datetime($a['discharged_at'])) ?></td><?php endif; ?>
                    <td><?= e($a['reason']) ?></td>
                    <td class="actions">
                        <?php if ($canManage && $status === 'admitted'): ?>
                            <form method="post" action="admissions.php?action=discharge&id=<?= $a['id'] ?>" data-confirm="Discharge this patient?">
                                <?= csrf_field() ?><button class="btn btn-sm">Discharge</button>
                            </form>
                        <?php elseif ($canManage): ?>
                            <a class="btn btn-sm btn-outline" href="billing.php?action=add&admission_id=<?= $a['id'] ?>">Bill stay</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table></div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
