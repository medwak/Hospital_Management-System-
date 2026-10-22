<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role('admin', 'receptionist');

$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('admin');

    if ($action === 'add_ward') {
        $name = post('name');
        $beds = max(0, min(200, (int) post('beds')));
        $prefix = post('prefix');
        if ($name === '') {
            flash('error', 'Ward name is required.');
        } elseif (q('SELECT 1 FROM wards WHERE name = ?', [$name])->fetch()) {
            flash('error', 'A ward with that name already exists.');
        } else {
            q('INSERT INTO wards (name, type, daily_rate) VALUES (?, ?, ?)', [$name, post('type'), (float) post('daily_rate')]);
            $wardId = (int) db()->lastInsertId();
            for ($i = 1; $i <= $beds; $i++) {
                q('INSERT INTO beds (ward_id, bed_number) VALUES (?, ?)', [$wardId, $prefix . $i]);
            }
            flash('success', "Ward created with $beds bed(s).");
        }
        redirect('wards.php');
    }

    if ($action === 'edit_ward') {
        if (post('name') === '' || q('SELECT 1 FROM wards WHERE name = ? AND id <> ?', [post('name'), $id])->fetch()) {
            flash('error', 'Ward name is required and must be unique.');
            redirect('wards.php');
        }
        q('UPDATE wards SET name = ?, type = ?, daily_rate = ? WHERE id = ?', [post('name'), post('type'), (float) post('daily_rate'), $id]);
        flash('success', 'Ward updated.');
        redirect('wards.php');
    }

    if ($action === 'add_bed') {
        $number = post('bed_number');
        if ($number === '' || q('SELECT 1 FROM beds WHERE ward_id = ? AND bed_number = ?', [$id, $number])->fetch()) {
            flash('error', 'Bed number is required and must be unique within the ward.');
        } else {
            q('INSERT INTO beds (ward_id, bed_number) VALUES (?, ?)', [$id, $number]);
            flash('success', 'Bed added.');
        }
        redirect('wards.php');
    }

    if ($action === 'delete_bed') {
        if (q("SELECT 1 FROM beds WHERE id = ? AND status = 'occupied'", [$id])->fetch()) {
            flash('error', 'Cannot delete an occupied bed.');
        } else {
            q('DELETE FROM beds WHERE id = ?', [$id]);
            flash('success', 'Bed removed.');
        }
        redirect('wards.php');
    }

    if ($action === 'delete_ward') {
        if (q("SELECT 1 FROM beds WHERE ward_id = ? AND status = 'occupied'", [$id])->fetch()) {
            flash('error', 'Cannot delete a ward with occupied beds.');
        } else {
            q('DELETE FROM wards WHERE id = ?', [$id]);
            flash('success', 'Ward deleted.');
        }
        redirect('wards.php');
    }
}

$wards = q('SELECT * FROM wards ORDER BY name')->fetchAll();
$beds = [];
foreach (q("SELECT b.*, p.first_name, p.last_name
            FROM beds b
            LEFT JOIN admissions a ON a.bed_id = b.id AND a.status = 'admitted'
            LEFT JOIN patients p ON p.id = a.patient_id
            ORDER BY b.id")->fetchAll() as $b) {
    $beds[$b['ward_id']][] = $b;
}

$pageTitle = 'Wards & Beds';
require __DIR__ . '/includes/header.php';
?>
<?php if (has_role('admin')): ?>
<div class="card">
    <h2>Add Ward</h2>
    <form method="post" action="wards.php?action=add_ward">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div><label>Ward name *</label><input name="name" required></div>
            <div><label>Type</label><input name="type" placeholder="General, Private, ICU…"></div>
            <div><label>Daily rate (<?= e(trim(CURRENCY)) ?>)</label><input type="number" step="0.01" min="0" name="daily_rate" value="0"></div>
            <div><label>Number of beds</label><input type="number" min="0" max="200" name="beds" value="4"></div>
            <div><label>Bed number prefix</label><input name="prefix" placeholder="e.g. B" value="B"></div>
        </div>
        <div class="form-actions"><button class="btn">Create ward</button></div>
    </form>
</div>
<?php endif; ?>

<?php if (!$wards): ?><div class="card"><p class="muted">No wards configured.</p></div><?php endif; ?>
<?php foreach ($wards as $w):
    $wardBeds = $beds[$w['id']] ?? [];
    $free = count(array_filter($wardBeds, fn($b) => $b['status'] === 'available'));
    ?>
    <div class="card">
        <div class="toolbar">
            <div>
                <h2 style="margin:0"><?= e($w['name']) ?></h2>
                <span class="muted"><?= e($w['type'] ?: 'General') ?> · <?= e(money($w['daily_rate'])) ?>/day · <?= $free ?> of <?= count($wardBeds) ?> beds available</span>
            </div>
            <?php if (has_role('admin')): ?>
                <form method="post" action="wards.php?action=delete_ward&id=<?= $w['id'] ?>" data-confirm="Delete this ward and all its beds?">
                    <?= csrf_field() ?><button class="btn btn-sm btn-danger">Delete ward</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="beds">
            <?php foreach ($wardBeds as $b): ?>
                <div class="bed <?= e($b['status']) ?>">
                    <strong><?= e($b['bed_number']) ?></strong>
                    <?php if ($b['first_name']): ?> — <?= e($b['first_name'] . ' ' . $b['last_name']) ?><?php else: ?> <span class="muted">free</span><?php endif; ?>
                    <?php if (has_role('admin') && $b['status'] === 'available'): ?>
                        <form method="post" action="wards.php?action=delete_bed&id=<?= $b['id'] ?>" style="display:inline" data-confirm="Remove bed <?= e($b['bed_number']) ?>?">
                            <?= csrf_field() ?><button class="btn btn-sm btn-outline" title="Remove bed" style="padding:0 6px">&times;</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (has_role('admin')): ?>
            <details style="margin-top:14px">
                <summary>Manage ward</summary>
                <form method="post" action="wards.php?action=add_bed&id=<?= $w['id'] ?>" class="toolbar" style="margin-top:10px">
                    <?= csrf_field() ?>
                    <div style="display:flex;gap:8px"><input name="bed_number" placeholder="New bed number" required style="width:auto"><button class="btn btn-sm">Add bed</button></div>
                </form>
                <form method="post" action="wards.php?action=edit_ward&id=<?= $w['id'] ?>">
                    <?= csrf_field() ?>
                    <div class="form-grid">
                        <div><label>Name</label><input name="name" value="<?= e($w['name']) ?>" required></div>
                        <div><label>Type</label><input name="type" value="<?= e($w['type']) ?>"></div>
                        <div><label>Daily rate</label><input type="number" step="0.01" min="0" name="daily_rate" value="<?= e($w['daily_rate']) ?>"></div>
                    </div>
                    <div class="form-actions"><button class="btn btn-sm">Save ward</button></div>
                </form>
            </details>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
