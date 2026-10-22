<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role('admin');

$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'delete') {
        q('DELETE FROM departments WHERE id = ?', [$id]);
        flash('success', 'Department deleted. Its doctors are now unassigned.');
        redirect('departments.php');
    }

    $name = post('name');
    if ($name === '') {
        flash('error', 'Department name is required.');
    } elseif (q('SELECT 1 FROM departments WHERE name = ? AND id <> ?', [$name, $id])->fetch()) {
        flash('error', 'A department with that name already exists.');
    } elseif ($action === 'edit') {
        q('UPDATE departments SET name = ?, description = ? WHERE id = ?', [$name, post('description'), $id]);
        flash('success', 'Department updated.');
    } else {
        q('INSERT INTO departments (name, description) VALUES (?, ?)', [$name, post('description')]);
        flash('success', 'Department added.');
    }
    redirect('departments.php');
}

$editing = $action === 'edit' ? q('SELECT * FROM departments WHERE id = ?', [$id])->fetch() : null;
$departments = q('SELECT d.*, (SELECT COUNT(*) FROM doctors WHERE department_id = d.id) AS doctor_count FROM departments d ORDER BY d.name')->fetchAll();

$pageTitle = 'Departments';
require __DIR__ . '/includes/header.php';
?>
<div class="grid-2">
    <div class="card">
        <h2><?= $editing ? 'Edit Department' : 'Add Department' ?></h2>
        <form method="post" action="departments.php?action=<?= $editing ? 'edit&id=' . $editing['id'] : 'add' ?>">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="full"><label>Name *</label><input name="name" value="<?= e($editing['name'] ?? '') ?>" required></div>
                <div class="full"><label>Description</label><textarea name="description"><?= e($editing['description'] ?? '') ?></textarea></div>
            </div>
            <div class="form-actions">
                <button class="btn"><?= $editing ? 'Save changes' : 'Add department' ?></button>
                <?php if ($editing): ?><a class="btn btn-outline" href="departments.php">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
    <div class="card">
        <h2>All Departments</h2>
        <div class="table-wrap"><table>
            <tr><th>Name</th><th>Doctors</th><th></th></tr>
            <?php foreach ($departments as $d): ?>
                <tr>
                    <td><strong><?= e($d['name']) ?></strong><br><small class="muted"><?= e($d['description']) ?></small></td>
                    <td><?= (int) $d['doctor_count'] ?></td>
                    <td class="actions">
                        <a class="btn btn-sm btn-outline" href="departments.php?action=edit&id=<?= $d['id'] ?>">Edit</a>
                        <form method="post" action="departments.php?action=delete&id=<?= $d['id'] ?>" data-confirm="Delete this department?">
                            <?= csrf_field() ?><button class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table></div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
