<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_role('admin', 'receptionist');

$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$errors = [];

function find_invoice(int $id): array
{
    $inv = q('SELECT i.*, p.first_name, p.last_name, p.patient_no, p.phone, p.address
              FROM invoices i JOIN patients p ON p.id = i.patient_id WHERE i.id = ?', [$id])->fetch();
    if (!$inv) {
        flash('error', 'Invoice not found.');
        redirect('billing.php');
    }
    return $inv;
}

/** Collect valid line items from the submitted form. */
function posted_items(): array
{
    $items = [];
    foreach ((array) ($_POST['items'] ?? []) as $row) {
        $desc = trim((string) ($row['description'] ?? ''));
        $qty = max(1, (int) ($row['quantity'] ?? 1));
        $price = max(0, (float) ($row['unit_price'] ?? 0));
        if ($desc !== '') {
            $items[] = ['description' => $desc, 'quantity' => $qty, 'unit_price' => $price];
        }
    }
    return $items;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'pay') {
        $method = post('payment_method') ?: 'Cash';
        q("UPDATE invoices SET status = 'paid', payment_method = ?, paid_at = ? WHERE id = ? AND status = 'unpaid'", [$method, date('Y-m-d H:i:s'), $id]);
        flash('success', 'Payment recorded.');
        redirect('billing.php?action=view&id=' . $id);
    }

    if ($action === 'delete') {
        require_role('admin');
        q('DELETE FROM invoices WHERE id = ?', [$id]);
        flash('success', 'Invoice deleted.');
        redirect('billing.php');
    }

    if (in_array($action, ['add', 'edit'], true)) {
        $patientId = (int) post('patient_id');
        $issueDate = post('issue_date');
        $items = posted_items();

        if (!q('SELECT 1 FROM patients WHERE id = ?', [$patientId])->fetch()) {
            $errors[] = 'Please select a patient.';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) {
            $errors[] = 'Please choose a valid issue date.';
        }
        if (!$items) {
            $errors[] = 'Add at least one line item.';
        }
        if ($action === 'edit' && find_invoice($id)['status'] === 'paid') {
            $errors[] = 'Paid invoices cannot be edited.';
        }

        if (!$errors) {
            $total = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $items));
            db()->beginTransaction();
            if ($action === 'add') {
                q('INSERT INTO invoices (invoice_no, patient_id, issue_date, total, notes) VALUES (?, ?, ?, ?, ?)',
                    [next_code('invoices', 'invoice_no', 'INV-'), $patientId, $issueDate, $total, post('notes')]);
                $id = (int) db()->lastInsertId();
            } else {
                q('UPDATE invoices SET patient_id = ?, issue_date = ?, total = ?, notes = ? WHERE id = ?', [$patientId, $issueDate, $total, post('notes'), $id]);
                q('DELETE FROM invoice_items WHERE invoice_id = ?', [$id]);
            }
            foreach ($items as $item) {
                q('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price) VALUES (?, ?, ?, ?)',
                    [$id, $item['description'], $item['quantity'], $item['unit_price']]);
            }
            db()->commit();
            flash('success', $action === 'add' ? 'Invoice created.' : 'Invoice updated.');
            redirect('billing.php?action=view&id=' . $id);
        }
    }
}

if (in_array($action, ['add', 'edit'], true)) {
    $invoice = ['patient_id' => (int) ($_GET['patient_id'] ?? 0), 'issue_date' => date('Y-m-d'), 'notes' => ''];
    $items = [];

    if ($action === 'edit') {
        $invoice = find_invoice($id);
        if ($invoice['status'] === 'paid') {
            flash('error', 'Paid invoices cannot be edited.');
            redirect('billing.php?action=view&id=' . $id);
        }
        $items = q('SELECT description, quantity, unit_price FROM invoice_items WHERE invoice_id = ? ORDER BY id', [$id])->fetchAll();
    } elseif (!empty($_GET['admission_id'])) {
        $adm = q('SELECT a.*, b.bed_number, w.name AS ward_name, w.daily_rate FROM admissions a JOIN beds b ON b.id = a.bed_id JOIN wards w ON w.id = b.ward_id WHERE a.id = ?', [(int) $_GET['admission_id']])->fetch();
        if ($adm) {
            $end = $adm['discharged_at'] ? strtotime($adm['discharged_at']) : time();
            $days = max(1, (int) ceil(($end - strtotime($adm['admitted_at'])) / 86400));
            $invoice['patient_id'] = $adm['patient_id'];
            $invoice['notes'] = 'Inpatient stay ' . fmt_date($adm['admitted_at']) . ' – ' . fmt_date($adm['discharged_at'] ?? date('Y-m-d'));
            $items[] = ['description' => 'Bed charges — ' . $adm['ward_name'] . ' (' . $adm['bed_number'] . ')', 'quantity' => $days, 'unit_price' => $adm['daily_rate']];
            if ($adm['doctor_id']) {
                $doc = q('SELECT name, consultation_fee FROM doctors WHERE id = ?', [$adm['doctor_id']])->fetch();
                if ($doc) {
                    $items[] = ['description' => 'Doctor review — ' . $doc['name'], 'quantity' => $days, 'unit_price' => $doc['consultation_fee']];
                }
            }
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $invoice = array_merge($invoice, ['patient_id' => (int) post('patient_id'), 'issue_date' => post('issue_date'), 'notes' => post('notes')]);
        $items = posted_items();
    }
    if (!$items) {
        $items[] = ['description' => 'Consultation fee', 'quantity' => 1, 'unit_price' => ''];
    }

    $pageTitle = $action === 'add' ? 'New Invoice' : 'Edit Invoice ' . $invoice['invoice_no'];
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="card">
        <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div><label>Patient *</label><select name="patient_id" required><option value="">Select patient…</option><?= patient_options((int) $invoice['patient_id']) ?></select></div>
                <div><label>Issue date *</label><input type="date" name="issue_date" value="<?= e($invoice['issue_date']) ?>" required></div>
            </div>

            <h2 style="margin-top:22px">Line items</h2>
            <div class="table-wrap"><table id="items">
                <thead><tr><th>Description</th><th style="width:110px">Qty</th><th style="width:150px">Unit price</th><th style="width:130px" class="text-right">Amount</th><th style="width:50px"></th></tr></thead>
                <tbody>
                <?php foreach ($items as $i => $item): ?>
                    <tr>
                        <td><input name="items[<?= $i ?>][description]" value="<?= e($item['description']) ?>"></td>
                        <td><input type="number" min="1" name="items[<?= $i ?>][quantity]" value="<?= e($item['quantity']) ?>"></td>
                        <td><input type="number" min="0" step="0.01" name="items[<?= $i ?>][unit_price]" value="<?= e($item['unit_price']) ?>"></td>
                        <td class="text-right amount">0.00</td>
                        <td><button type="button" class="btn btn-sm btn-outline remove">&times;</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th colspan="3" class="text-right">Total</th><th class="text-right" id="total">0.00</th><th></th></tr></tfoot>
            </table></div>
            <p><button type="button" class="btn btn-sm btn-outline" id="add-item">+ Add item</button></p>

            <div class="form-grid">
                <div class="full"><label>Notes</label><textarea name="notes"><?= e($invoice['notes']) ?></textarea></div>
            </div>
            <div class="form-actions">
                <button class="btn">Save invoice</button>
                <a class="btn btn-outline" href="billing.php">Cancel</a>
            </div>
        </form>
    </div>
    <script>
    (function () {
        var tbody = document.querySelector('#items tbody');
        var counter = tbody.rows.length;
        function recalc() {
            var total = 0;
            Array.prototype.forEach.call(tbody.rows, function (row) {
                var inputs = row.querySelectorAll('input');
                var amount = (parseFloat(inputs[1].value) || 0) * (parseFloat(inputs[2].value) || 0);
                row.querySelector('.amount').textContent = amount.toFixed(2);
                total += amount;
            });
            document.getElementById('total').textContent = total.toFixed(2);
        }
        document.getElementById('add-item').addEventListener('click', function () {
            var row = tbody.rows[0].cloneNode(true);
            row.querySelectorAll('input').forEach(function (input) {
                input.name = input.name.replace(/items\[\d+\]/, 'items[' + counter + ']');
                input.value = input.type === 'number' && input.name.indexOf('quantity') > -1 ? 1 : '';
            });
            counter++;
            tbody.appendChild(row);
            recalc();
        });
        tbody.addEventListener('click', function (ev) {
            if (ev.target.classList.contains('remove') && tbody.rows.length > 1) {
                ev.target.closest('tr').remove();
                recalc();
            }
        });
        tbody.addEventListener('input', recalc);
        recalc();
    })();
    </script>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($action === 'view') {
    $inv = find_invoice($id);
    $items = q('SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id', [$id])->fetchAll();

    $pageTitle = 'Invoice ' . $inv['invoice_no'];
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="toolbar no-print">
        <a href="billing.php">&larr; All invoices</a>
        <div>
            <button class="btn btn-sm btn-outline" onclick="window.print()">Print</button>
            <?php if ($inv['status'] === 'unpaid'): ?><a class="btn btn-sm" href="billing.php?action=edit&id=<?= $id ?>">Edit</a><?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="invoice-head">
            <div>
                <h2><?= e(APP_NAME) ?></h2>
                <div class="muted">Invoice <?= e($inv['invoice_no']) ?></div>
                <div class="muted">Issued <?= e(fmt_date($inv['issue_date'])) ?></div>
            </div>
            <div>
                <strong>Bill to:</strong><br>
                <?= e($inv['first_name'] . ' ' . $inv['last_name']) ?> (<?= e($inv['patient_no']) ?>)<br>
                <?= e($inv['phone']) ?><br>
                <?= nl2br(e($inv['address'])) ?>
            </div>
            <div><?= badge($inv['status']) ?>
                <?php if ($inv['status'] === 'paid'): ?><br><small class="muted">Paid <?= e(fmt_datetime($inv['paid_at'])) ?> via <?= e($inv['payment_method']) ?></small><?php endif; ?>
            </div>
        </div>
        <div class="table-wrap"><table>
            <tr><th>Description</th><th class="text-right">Qty</th><th class="text-right">Unit price</th><th class="text-right">Amount</th></tr>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item['description']) ?></td>
                    <td class="text-right"><?= (int) $item['quantity'] ?></td>
                    <td class="text-right"><?= e(money($item['unit_price'])) ?></td>
                    <td class="text-right"><?= e(money($item['quantity'] * $item['unit_price'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr><th colspan="3" class="text-right">Total</th><th class="text-right"><?= e(money($inv['total'])) ?></th></tr>
        </table></div>
        <?php if ($inv['notes']): ?><p class="muted"><?= nl2br(e($inv['notes'])) ?></p><?php endif; ?>
    </div>
    <?php if ($inv['status'] === 'unpaid'): ?>
        <div class="card no-print">
            <h2>Record Payment</h2>
            <form method="post" action="billing.php?action=pay&id=<?= $id ?>" class="toolbar" style="justify-content:flex-start">
                <?= csrf_field() ?>
                <div style="display:flex;gap:8px">
                    <select name="payment_method" style="width:auto">
                        <?php foreach (['Cash', 'Card', 'Mobile Money', 'Insurance', 'Bank Transfer'] as $m): ?><option><?= $m ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn">Mark as paid (<?= e(money($inv['total'])) ?>)</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// List
$search = trim($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? '', ['paid', 'unpaid'], true) ? $_GET['status'] : '';
$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(i.invoice_no LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_no LIKE ?)';
    array_push($params, ...array_fill(0, 4, '%' . $search . '%'));
}
if ($status !== '') {
    $where[] = 'i.status = ?';
    $params[] = $status;
}
$invoices = q('SELECT i.*, p.first_name, p.last_name, p.patient_no FROM invoices i JOIN patients p ON p.id = i.patient_id '
    . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY i.id DESC LIMIT 200', $params)->fetchAll();
$totals = q("SELECT
                COALESCE(SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END), 0) AS paid,
                COALESCE(SUM(CASE WHEN status = 'unpaid' THEN total ELSE 0 END), 0) AS unpaid
             FROM invoices")->fetch();

$pageTitle = 'Billing';
require __DIR__ . '/includes/header.php';
?>
<div class="stats">
    <div class="stat"><div class="label">Collected</div><div class="value"><?= e(money($totals['paid'])) ?></div></div>
    <div class="stat"><div class="label">Outstanding</div><div class="value"><?= e(money($totals['unpaid'])) ?></div></div>
</div>
<div class="toolbar">
    <form method="get">
        <input name="q" value="<?= e($search) ?>" placeholder="Invoice no. or patient…">
        <select name="status">
            <option value="">All</option>
            <option value="unpaid"<?= selected('unpaid', $status) ?>>Unpaid</option>
            <option value="paid"<?= selected('paid', $status) ?>>Paid</option>
        </select>
        <button class="btn btn-outline">Filter</button>
    </form>
    <a class="btn" href="billing.php?action=add">+ New invoice</a>
</div>
<div class="card">
    <?php if (!$invoices): ?>
        <p class="muted">No invoices found.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <tr><th>Invoice No.</th><th>Patient</th><th>Date</th><th class="text-right">Total</th><th>Status</th><th></th></tr>
            <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td><a href="billing.php?action=view&id=<?= $inv['id'] ?>"><?= e($inv['invoice_no']) ?></a></td>
                    <td><?= e($inv['first_name'] . ' ' . $inv['last_name']) ?><br><small class="muted"><?= e($inv['patient_no']) ?></small></td>
                    <td><?= e(fmt_date($inv['issue_date'])) ?></td>
                    <td class="text-right"><?= e(money($inv['total'])) ?></td>
                    <td><?= badge($inv['status']) ?></td>
                    <td class="actions">
                        <a class="btn btn-sm btn-outline" href="billing.php?action=view&id=<?= $inv['id'] ?>">View</a>
                        <?php if (has_role('admin')): ?>
                            <form method="post" action="billing.php?action=delete&id=<?= $inv['id'] ?>" data-confirm="Delete this invoice?">
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
