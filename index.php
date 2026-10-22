<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$today = date('Y-m-d');
$doctorId = current_doctor_id();

$count = fn(string $sql, array $p = []) => (int) q($sql, $p)->fetch()['c'];

if ($doctorId !== null) {
    $stats = [
        "Today's appointments" => $count("SELECT COUNT(*) AS c FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND status <> 'cancelled'", [$doctorId, $today]),
        'Upcoming appointments' => $count("SELECT COUNT(*) AS c FROM appointments WHERE doctor_id = ? AND appointment_date >= ? AND status = 'scheduled'", [$doctorId, $today]),
        'My admitted patients' => $count("SELECT COUNT(*) AS c FROM admissions WHERE doctor_id = ? AND status = 'admitted'", [$doctorId]),
        'Records written' => $count('SELECT COUNT(*) AS c FROM medical_records WHERE doctor_id = ?', [$doctorId]),
    ];
} else {
    $stats = [
        'Patients' => $count('SELECT COUNT(*) AS c FROM patients'),
        'Doctors' => $count('SELECT COUNT(*) AS c FROM doctors'),
        "Today's appointments" => $count("SELECT COUNT(*) AS c FROM appointments WHERE appointment_date = ? AND status <> 'cancelled'", [$today]),
        'Admitted patients' => $count("SELECT COUNT(*) AS c FROM admissions WHERE status = 'admitted'"),
        'Available beds' => $count("SELECT COUNT(*) AS c FROM beds WHERE status = 'available'"),
    ];
    if (has_role('admin', 'receptionist')) {
        $stats['Revenue collected'] = money(q("SELECT COALESCE(SUM(total), 0) AS s FROM invoices WHERE status = 'paid'")->fetch()['s']);
        $stats['Outstanding bills'] = money(q("SELECT COALESCE(SUM(total), 0) AS s FROM invoices WHERE status = 'unpaid'")->fetch()['s']);
    }
}

$apptSql = 'SELECT a.*, p.first_name, p.last_name, p.patient_no, d.name AS doctor_name
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            JOIN doctors d ON d.id = a.doctor_id
            WHERE a.appointment_date = ?' . ($doctorId !== null ? ' AND a.doctor_id = ?' : '') . '
            ORDER BY a.appointment_time';
$todayAppointments = q($apptSql, $doctorId !== null ? [$today, $doctorId] : [$today])->fetchAll();

$recentPatients = q('SELECT * FROM patients ORDER BY id DESC LIMIT 5')->fetchAll();

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="stats">
    <?php foreach ($stats as $label => $value): ?>
        <div class="stat"><div class="label"><?= e($label) ?></div><div class="value"><?= e($value) ?></div></div>
    <?php endforeach; ?>
</div>

<div class="grid-2">
    <div class="card">
        <h2>Today's Appointments (<?= e(fmt_date($today)) ?>)</h2>
        <?php if (!$todayAppointments): ?>
            <p class="muted">No appointments scheduled for today.</p>
        <?php else: ?>
            <div class="table-wrap"><table>
                <tr><th>Time</th><th>Patient</th><?php if ($doctorId === null): ?><th>Doctor</th><?php endif; ?><th>Status</th></tr>
                <?php foreach ($todayAppointments as $a): ?>
                    <tr>
                        <td><?= e($a['appointment_time']) ?></td>
                        <td><a href="patients.php?action=view&id=<?= $a['patient_id'] ?>"><?= e($a['first_name'] . ' ' . $a['last_name']) ?></a></td>
                        <?php if ($doctorId === null): ?><td><?= e($a['doctor_name']) ?></td><?php endif; ?>
                        <td><?= badge($a['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table></div>
        <?php endif; ?>
        <p><a href="appointments.php">View all appointments &rarr;</a></p>
    </div>
    <div class="card">
        <h2>Recently Registered Patients</h2>
        <?php if (!$recentPatients): ?>
            <p class="muted">No patients registered yet.</p>
        <?php else: ?>
            <div class="table-wrap"><table>
                <tr><th>Patient No.</th><th>Name</th><th>Gender</th><th>Phone</th></tr>
                <?php foreach ($recentPatients as $p): ?>
                    <tr>
                        <td><?= e($p['patient_no']) ?></td>
                        <td><a href="patients.php?action=view&id=<?= $p['id'] ?>"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></a></td>
                        <td><?= e($p['gender']) ?></td>
                        <td><?= e($p['phone']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table></div>
        <?php endif; ?>
        <?php if (has_role('admin', 'receptionist')): ?><p><a href="patients.php?action=add">Register a new patient &rarr;</a></p><?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
