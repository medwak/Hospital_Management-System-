<?php
/**
 * General helpers: escaping, flash messages, CSRF, auth and formatting.
 */

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function post(string $key, $default = '')
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $value;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Invalid or expired form submission. Please go back and try again.');
    }
}

/* ---------- Auth ---------- */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function has_role(string ...$roles): bool
{
    $user = current_user();
    return $user !== null && in_array($user['role'], $roles, true);
}

function require_login(): void
{
    if (!current_user()) {
        redirect('login.php');
    }
}

function require_role(string ...$roles): void
{
    require_login();
    if (!has_role(...$roles)) {
        http_response_code(403);
        flash('error', 'You do not have permission to access that page.');
        redirect('index.php');
    }
}

/** Doctor profile id linked to the logged-in user (null for non-doctors). */
function current_doctor_id(): ?int
{
    if (!has_role('doctor')) {
        return null;
    }
    $row = q('SELECT id FROM doctors WHERE user_id = ?', [current_user()['id']])->fetch();
    return $row ? (int) $row['id'] : 0;
}

/* ---------- Formatting ---------- */

function money($amount): string
{
    return CURRENCY . number_format((float) $amount, 2);
}

function fmt_date(?string $date): string
{
    return $date ? date('d M Y', strtotime($date)) : '—';
}

function fmt_datetime(?string $date): string
{
    return $date ? date('d M Y, H:i', strtotime($date)) : '—';
}

function age(?string $dob): string
{
    if (!$dob) {
        return '—';
    }
    return (string) (new DateTime($dob))->diff(new DateTime('today'))->y;
}

function badge(string $status): string
{
    return '<span class="badge badge-' . e($status) . '">' . e(ucfirst($status)) . '</span>';
}

function selected($a, $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

/** Generate the next sequential code, e.g. PT-000123. */
function next_code(string $table, string $column, string $prefix): string
{
    $row = q("SELECT MAX(id) AS m FROM $table")->fetch();
    $n = (int) ($row['m'] ?? 0) + 1;
    do {
        $code = $prefix . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
        $exists = q("SELECT 1 FROM $table WHERE $column = ?", [$code])->fetch();
        $n++;
    } while ($exists);
    return $code;
}

function patient_options(?int $selectedId = null): string
{
    $html = '';
    foreach (q('SELECT id, patient_no, first_name, last_name FROM patients ORDER BY first_name, last_name')->fetchAll() as $p) {
        $html .= '<option value="' . $p['id'] . '"' . selected($p['id'], $selectedId) . '>'
            . e($p['first_name'] . ' ' . $p['last_name'] . ' (' . $p['patient_no'] . ')') . '</option>';
    }
    return $html;
}

function doctor_options(?int $selectedId = null): string
{
    $html = '';
    foreach (q('SELECT id, name, specialization FROM doctors ORDER BY name')->fetchAll() as $d) {
        $html .= '<option value="' . $d['id'] . '"' . selected($d['id'], $selectedId) . '>'
            . e($d['name'] . ($d['specialization'] ? ' — ' . $d['specialization'] : '')) . '</option>';
    }
    return $html;
}

function excerpt(?string $text, int $length = 80): string
{
    $text = (string) $text;
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $length, '…');
    }
    return strlen($text) > $length ? substr($text, 0, $length - 1) . '…' : $text;
}
