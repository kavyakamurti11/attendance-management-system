<?php
/* ============================================================
   api/index.php  -  single entry point:  api/index.php?r=<route>
   GET  health | sync | export
   POST register | login | logout | save | delete | restore
   POST forgot | forgot_verify | forgot_reset (admin, faculty, student) | change_password
   ============================================================ */
require_once __DIR__ . '/../includes/lib.php';

mb_internal_encoding('UTF-8');
set_exception_handler(function (Throwable $e) {
    error_log('[attendance] ' . $e->getMessage());
    json_out(500, ['error' => 'Server error. ' . (APP_DEBUG ? $e->getMessage() : 'Check the database setup and includes/config.php.')]);
});

$route  = (string)($_GET['r'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// plain-password schema (renames old password_hash columns, creates student_details) - only for routes that touch accounts
if (in_array($route, ['register', 'login', 'forgot', 'forgot_verify', 'forgot_reset', 'admin_forgot', 'admin_verify', 'admin_reset', 'change_password', 'export', 'restore'], true)) {
    try { ensure_plain_schema(); } catch (PDOException $e) { /* reported by the route itself */ }
}

// CSRF guard: every POST must be JSON (a cross-site <form> cannot send that without CORS approval)
if ($method === 'POST' && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === false)
    json_out(415, ['error' => 'Content-Type must be application/json.']);

switch ($route) {

// ------------------------------------------------------------------
case 'health':
    try {
        $n = db()->query("SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema = DATABASE()
                            AND table_name IN ('admins','faculty','students','password_resets','login_attempts','app_data','classes','class_students','attendance')")->fetchColumn();
        if ((int)$n < 9) json_out(503, ['ok' => false, 'error' => 'Tables missing. Import database/schema.sql.']);
        ensure_admin_seed();
        json_out(200, ['ok' => true]);
    } catch (PDOException $e) {
        json_out(503, ['ok' => false, 'error' => 'Cannot connect to MySQL / database. Check includes/config.php.']);
    }
    break;

// ------------------------------------------------------------------
case 'class_options':                          // public list for the student sign-up / login drop-down
    json_out(200, ['classes' => public_class_options()]);
    break;

// ------------------------------------------------------------------
case 'register':                               // faculty and student only. Admin accounts cannot be created here.
    if ($method !== 'POST') json_out(405, ['error' => 'POST only.']);
    $b = request_body();
    $name = trim((string)($b['name'] ?? ''));
    $role = norm($b['role'] ?? '');
    $password = (string)($b['password'] ?? '');
    if ($role === 'admin') json_out(403, ['error' => 'Admin accounts cannot be created. The admin is set in includes/config.php.']);
    if ($name === '' || mb_strlen($name) > 150) json_out(400, ['error' => 'Enter a valid name.']);
    if (strlen($password) < 8 || strlen($password) > 200) json_out(400, ['error' => 'Password must contain at least 8 characters.']);
    if (!in_array($role, ['faculty', 'student'], true)) json_out(400, ['error' => 'Invalid account type.']);

    $pdo = db();
    try {
        if ($role === 'student') {
            $roll = trim((string)($b['roll'] ?? ''));
            $division = trim((string)($b['division'] ?? ''));
            if ($roll === '' || mb_strlen($roll) > 80)           json_out(400, ['error' => 'Enter your username / roll number.']);
            if ($division === '' || mb_strlen($division) > 150)  json_out(400, ['error' => 'Enter your class / division.']);
            $email = norm($b['email'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) json_out(400, ['error' => 'Enter a valid email address.']);
            $info = resolve_student_class_info($roll, $division);
            $canon = $info === null ? null : $info['name'];
            if ($canon === null) json_out(404, ['error' => 'Account NOT created: roll number "' . $roll . '" is not added in class "' . $division . '" by faculty. Ask your faculty to add you to that class first, then pick the class from the list.']);
            $division = $canon;      // store the real class name
            $st = $pdo->prepare('SELECT 1 FROM students WHERE roll = ? AND division = ?');
            $st->execute([$roll, $division]);
            if ($st->fetchColumn()) json_out(409, ['error' => 'An account for this roll number and division already exists.']);
            $pdo->prepare('INSERT INTO students (name, roll, division, password) VALUES (?,?,?,?)')
                ->execute([$name, $roll, $division, store_password($password)]);
            upsert_student_details($name, $roll, $division, $info['division'], $email, $password);
            $row = ['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'email' => null, 'role' => 'student', 'roll' => $roll, 'division' => $division];
        } else {
            $email = norm($b['email'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) json_out(400, ['error' => 'Enter a valid email address.']);
            $st = $pdo->prepare('SELECT 1 FROM faculty WHERE email = ?');
            $st->execute([$email]);
            if ($st->fetchColumn()) json_out(409, ['error' => 'This email is already registered.']);
            $pdo->prepare('INSERT INTO faculty (name, email, password) VALUES (?,?,?)')
                ->execute([$name, $email, store_password($password)]);
            $row = ['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'faculty'];
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') json_out(409, ['error' => 'This account already exists.']);
        throw $e;
    }
    json_out(201, ['message' => 'Account created successfully.', 'user' => public_user($row)]);
    break;

// ------------------------------------------------------------------
case 'login':
    if ($method !== 'POST') json_out(405, ['error' => 'POST only.']);
    $b = request_body();
    $role = norm($b['role'] ?? '');
    $password = (string)($b['password'] ?? '');
    if ($password === '' || !in_array($role, ['admin', 'faculty', 'student'], true))
        json_out(400, ['error' => 'Please fill in all fields.']);

    $pdo = db();
    if ($role === 'admin') ensure_admin_seed();
    if ($role === 'student') {
        $roll = trim((string)($b['roll'] ?? '')); $division = trim((string)($b['division'] ?? ''));
        if ($roll === '' || $division === '') json_out(400, ['error' => 'Enter roll number, class / division and password.']);
        $info = resolve_student_class_info($roll, $division);
        if ($info !== null) $division = $info['name'];
        $ident = 'student:' . norm($roll) . '|' . norm($division);
        $find = $pdo->prepare("SELECT id, name, NULL AS email, 'student' AS role, roll, division, password FROM students WHERE roll = ? AND division = ?");
        $find->execute([$roll, $division]);
        $bad = 'Invalid roll number, class / division or password.';
    } else {
        $email = norm($b['email'] ?? '');
        if ($email === '') json_out(400, ['error' => 'Enter email and password.']);
        $ident = $role . ':' . $email;
        $tbl = account_table($role);
        $find = $pdo->prepare("SELECT id, name, email, '$role' AS role, NULL AS roll, NULL AS division, password FROM $tbl WHERE email = ?");
        $find->execute([$email]);
        $bad = 'Invalid email, password or account type.';
    }
    // lock for 15 minutes after 5 wrong attempts
    $pdo->exec("DELETE FROM login_attempts WHERE attempt_at < (NOW() - INTERVAL 1 DAY)");
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ident = ? AND attempt_at > (NOW() - INTERVAL 15 MINUTE)');
    $cnt->execute([$ident]);
    if ((int)$cnt->fetchColumn() >= 5) json_out(429, ['error' => 'Too many failed attempts. Please wait 15 minutes and try again.']);

    $row = $find->fetch();
    $ok = $row ? check_password($password, (string)$row['password']) : false;
    if (!$ok) {
        $pdo->prepare('INSERT INTO login_attempts (ident, ip) VALUES (?, ?)')->execute([$ident, $_SERVER['REMOTE_ADDR'] ?? null]);
        json_out(401, ['error' => $bad]);
    }
    $pdo->prepare('DELETE FROM login_attempts WHERE ident = ?')->execute([$ident]);
    if (is_legacy_hash((string)$row['password'])) {          // old bcrypt value -> replace with the plain password
        $pdo->prepare('UPDATE ' . account_table($role) . ' SET password = ? WHERE id = ?')->execute([store_password($password), $row['id']]);
    }
    if ($role === 'student') upsert_student_details((string)$row['name'], (string)$row['roll'], (string)$row['division'], $info['division'] ?? '', '', $password);
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$row['id'];
    $_SESSION['role'] = $role;
    $_SESSION['last'] = time();
    session_write_close();
    unset($row['password']);
    json_out(200, ['user' => public_user($row)]);
    break;

// ------------------------------------------------------------------
case 'logout':
    if ($method !== 'POST') json_out(405, ['error' => 'POST only.']);
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    json_out(200, ['message' => 'Logged out.']);
    break;

// FORGOT PASSWORD (admin / faculty / student): email -> verification code -> new password
//   admin, faculty : identified by email
//   student        : identified by roll number + the email saved in student_details
case 'forgot':
case 'admin_forgot':
    if ($method !== 'POST') json_out(405, ['error' => 'POST only.']);
    $b = request_body();
    $role  = norm($b['role'] ?? 'admin');
    $email = norm($b['email'] ?? '');
    $roll  = trim((string)($b['roll'] ?? ''));
    if (!in_array($role, ['admin', 'faculty', 'student'], true)) json_out(400, ['error' => 'Invalid account type.']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(400, ['error' => 'Enter a valid email address.']);
    if ($role === 'student' && $roll === '') json_out(400, ['error' => 'Enter your roll number.']);
    $pdo = db();
    if ($role === 'admin') ensure_admin_seed();
    $ident = 'reset:' . $role . ':' . norm($roll) . ':' . $email;
    $pdo->exec("DELETE FROM login_attempts WHERE attempt_at < (NOW() - INTERVAL 1 DAY)");
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ident = ? AND attempt_at > (NOW() - INTERVAL 15 MINUTE)');
    $cnt->execute([$ident]);
    if ((int)$cnt->fetchColumn() >= 5) json_out(429, ['error' => 'Too many requests. Please wait 15 minutes.']);
    $pdo->prepare('INSERT INTO login_attempts (ident, ip) VALUES (?, ?)')->execute([$ident, $_SERVER['REMOTE_ADDR'] ?? null]);

    $generic = ['message' => 'If these details match an account, a verification code has been sent to the email. It is valid for ' . RESET_CODE_MINUTES . ' minutes.'];
    if ($role === 'student') {
        $st = $pdo->prepare('SELECT 1 FROM student_details WHERE roll_number = ? AND email = ? LIMIT 1');
        $st->execute([$roll, $email]);
    } else {
        $st = $pdo->prepare('SELECT 1 FROM ' . account_table($role) . ' WHERE email = ?');
        $st->execute([$email]);
    }
    if (!$st->fetchColumn()) json_out(200, $generic);        // do not reveal whether the account exists

    $rkey = reset_key($role, $email, $roll);
    $code = (string)random_int(100000, 999999);
    $pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$rkey]);
    $pdo->prepare('INSERT INTO password_resets (email, `code`, expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL ' . (int)RESET_CODE_MINUTES . ' MINUTE))')
        ->execute([$rkey, $code]);
    $sent = send_mail($email, ucfirst($role) . ' password reset code',
        "Your Attendance System verification code is: $code\n\nThis code is valid for " . RESET_CODE_MINUTES . " minutes.\nIf you did not request this, ignore this email.");
    if (!$sent) json_out(500, ['error' => 'Could not send the email. Check the SMTP settings in includes/config.php (MAIL_MODE, SMTP_USER, SMTP_PASS).']);
    if (MAIL_MODE !== 'smtp' && MAIL_DEV_SHOW_CODE) $generic['dev_code'] = $code;
    json_out(200, $generic);
    break;

case 'forgot_verify':
case 'admin_verify':
    if ($method !== 'POST') json_out(405, ['error' => 'POST only.']);
    $b = request_body();
    $role  = norm($b['role'] ?? 'admin');
    $email = norm($b['email'] ?? '');
    $roll  = trim((string)($b['roll'] ?? ''));
    $code  = trim((string)($b['code'] ?? ''));
    if (!in_array($role, ['admin', 'faculty', 'student'], true)) json_out(400, ['error' => 'Invalid account type.']);
    $rkey = reset_key($role, $email, $roll);
    $pdo = db();
    $st = $pdo->prepare('SELECT id, `code`, attempts FROM password_resets WHERE email = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
    $st->execute([$rkey]);
    $r = $st->fetch();
    if (!$r) json_out(400, ['error' => 'The code has expired or is invalid. Request a new code.']);
    if ((int)$r['attempts'] >= 5) {
        $pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$rkey]);
        json_out(429, ['error' => 'Too many wrong attempts. Request a new code.']);
    }
    if (!hash_equals((string)$r['code'], $code)) {
        $pdo->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?')->execute([$r['id']]);
        json_out(400, ['error' => 'The verification code is incorrect.']);
    }
    $pdo->prepare('DELETE FROM password_resets WHERE email = ?')->execute([$rkey]);
    start_session();
    $_SESSION['reset_role']  = $role;
    $_SESSION['reset_email'] = $email;
    $_SESSION['reset_roll']  = $roll;
    $_SESSION['reset_until'] = time() + 600;
    session_write_close();
    json_out(200, ['ok' => true, 'message' => 'Code verified. Now set a new password.']);
    break;

case 'forgot_reset':
case 'admin_reset':
    if ($method !== 'POST') json_out(405, ['error' => 'POST only.']);
    $newPw = (string)(request_body()['new_password'] ?? '');
    start_session();
    $role  = (string)($_SESSION['reset_role'] ?? 'admin');
    $email = (string)($_SESSION['reset_email'] ?? '');
    $roll  = (string)($_SESSION['reset_roll'] ?? '');
    $until = (int)($_SESSION['reset_until'] ?? 0);
    if ($email === '' || time() > $until) { session_write_close(); json_out(403, ['error' => 'Please verify the email code first.']); }
    if (strlen($newPw) < 8 || strlen($newPw) > 200) { session_write_close(); json_out(400, ['error' => 'Password must contain at least 8 characters.']); }
    $pdo = db();
    if ($role === 'student') {
        $pdo->prepare('UPDATE students s JOIN student_details d ON s.roll = d.roll_number AND s.division = d.class_name
                       SET s.password = ? WHERE d.email = ? AND d.roll_number = ?')->execute([store_password($newPw), $email, $roll]);
        $pdo->prepare('UPDATE student_details SET password = ? WHERE email = ? AND roll_number = ?')->execute([store_password($newPw), $email, $roll]);
        $cl = $pdo->prepare('SELECT class_name FROM student_details WHERE email = ? AND roll_number = ?');
        $cl->execute([$email, $roll]);
        foreach ($cl->fetchAll(PDO::FETCH_COLUMN) as $cn)
            $pdo->prepare('DELETE FROM login_attempts WHERE ident = ?')->execute(['student:' . norm($roll) . '|' . norm((string)$cn)]);   // also clear the login lock
    } else {
        $pdo->prepare('UPDATE ' . account_table($role) . ' SET password = ? WHERE email = ?')->execute([store_password($newPw), $email]);
        $pdo->prepare('DELETE FROM login_attempts WHERE ident = ?')->execute([$role . ':' . $email]);
    }
    unset($_SESSION['reset_role'], $_SESSION['reset_email'], $_SESSION['reset_roll'], $_SESSION['reset_until']);
    session_write_close();
    json_out(200, ['message' => 'Password changed. Now login with the new password.']);
    break;

// ------------------------------------------------------------------
case 'change_password':                         // logged-in admin / faculty / student
    if ($method !== 'POST') json_out(405, ['error' => 'POST only.']);
    $u = require_user();
    $b = request_body();
    $old = (string)($b['old_password'] ?? ''); $new = (string)($b['new_password'] ?? '');
    if (strlen($new) < 8 || strlen($new) > 200) json_out(400, ['error' => 'New password must contain at least 8 characters.']);
    $tbl = account_table($u['role']);
    $st = db()->prepare("SELECT password FROM $tbl WHERE id = ?");
    $st->execute([$u['id']]);
    if (!check_password($old, (string)$st->fetchColumn())) json_out(400, ['error' => 'The old password is incorrect.']);
    db()->prepare("UPDATE $tbl SET password = ? WHERE id = ?")->execute([store_password($new), $u['id']]);
    if ($u['role'] === 'student') upsert_student_details((string)$u['name'], (string)$u['roll'], (string)$u['division'], '', '', $new);
    json_out(200, ['message' => 'Password changed.']);
    break;

// ------------------------------------------------------------------
case 'sync':                                   // everything this user is allowed to see
    $u = require_user(empty($_GET['poll']));   // background polling must not keep the session alive
    $view = build_view($u);
    json_out(200, ['user' => public_user($u), 'data' => $view['data'], 'rolls' => $view['rolls']]);
    break;

// ------------------------------------------------------------------
case 'save':
    if ($method !== 'POST') json_out(405, ['error' => 'POST only.']);
    $u = require_user();
    $b = request_body();
    $key = (string)($b['key'] ?? '');
    if (!isset($b['value']) || !is_string($b['value'])) json_out(400, ['error' => 'Data must contain valid JSON.']);
    [$status, $payload] = save_key($u, $key, $b['value']);
    json_out($status, $payload);
    break;

// ------------------------------------------------------------------
case 'delete':
    if ($method !== 'POST') json_out(405, ['error' => 'POST only.']);
    $u = require_user();
    $key = (string)(request_body()['key'] ?? '');
    if ($key === 'users' || !key_is_valid($key) || !allowed_write_key($key, $u))
        json_out(403, ['error' => 'You do not have permission to change this data.']);
    $pdo = db();
    if (is_classes_key($key) || $key === 'attendance_archive') ensure_mirror_schema();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM app_data WHERE data_key = ?')->execute([$key]);
        if (is_classes_key($key) || $key === 'attendance_archive') {
            mirror_safely(function () use ($pdo, $key) {
                if (is_classes_key($key)) $pdo->prepare('DELETE FROM classes WHERE faculty_email = ?')->execute([faculty_email_from_key($key)]);
                rebuild_archive_rows();
            });
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    json_out(200, ['ok' => true]);
    break;

// ------------------------------------------------------------------
case 'export':                                 // admin backup (JSON download)
    $u = require_user();
    if ($u['role'] !== 'admin') json_out(403, ['error' => 'Admin only.']);
    $data = [];
    foreach (db()->query('SELECT data_key, data_value FROM app_data')->fetchAll() as $r) $data[$r['data_key']] = $r['data_value'];
    $users = db()->query("SELECT name, email, 'faculty' AS role, NULL AS roll, NULL AS division, password, created_at FROM faculty
                          UNION ALL SELECT name, NULL, 'student', roll, division, password, created_at FROM students")->fetchAll();
    json_out(200, ['format' => 'attendance-management-backup-v4', 'exported_at' => gmdate('c'), 'data' => (object)$data, 'users' => $users]);
    break;

// ------------------------------------------------------------------
case 'restore':                                // admin restore (faculty + student accounts + data)
    if ($method !== 'POST') json_out(405, ['error' => 'POST only.']);
    $u = require_user();
    if ($u['role'] !== 'admin') json_out(403, ['error' => 'Admin only.']);
    $b = request_body();
    $data = $b['data'] ?? null;
    if (!is_array($data) || !$data) json_out(400, ['error' => 'Backup must contain a data object.']);
    foreach ($data as $k => $v) {
        if (!key_is_valid((string)$k) || !is_string($v)) json_out(400, ['error' => 'Backup contains an invalid key or value.']);
        json_decode($v);
        if (json_last_error() !== JSON_ERROR_NONE) json_out(400, ['error' => 'Backup contains an invalid key or value.']);
    }
    $pdo = db();
    ensure_mirror_schema();
    $pdo->beginTransaction();
    $addedUsers = 0;
    try {
        foreach ($data as $k => $v) put_value((string)$k, $v);
        foreach (($b['users'] ?? []) as $r) {
            if (!is_array($r) || (empty($r['password']) && empty($r['password_hash']))) continue;
            $role = norm($r['role'] ?? '');
            if (!in_array($role, ['faculty', 'student'], true)) continue;     // admin comes from config.php
            $ph = (string)($r['password'] ?? $r['password_hash']);      // old backups: bcrypt value (still accepted at login)
            $nm = trim((string)($r['name'] ?? '')) ?: 'User';
            if ($role === 'student') {
                $roll = (string)($r['roll'] ?? ''); $div = (string)($r['division'] ?? '');
                if ($roll === '' || $div === '') continue;
                $q = $pdo->prepare('INSERT IGNORE INTO students (name, roll, division, password) VALUES (?,?,?,?)');
                $q->execute([$nm, $roll, $div, $ph]);
                upsert_student_details($nm, $roll, $div, '', norm($r['email'] ?? ''), $ph);
            } else {
                $email = norm($r['email'] ?? '');
                if ($email === '') continue;
                $q = $pdo->prepare('INSERT IGNORE INTO faculty (name, email, password) VALUES (?,?,?)');
                $q->execute([$nm, $email, $ph]);
            }
            $addedUsers += $q->rowCount();
        }
        foreach ($data as $k => $v) {
            if (is_classes_key((string)$k)) sync_class_tables(faculty_email_from_key((string)$k), decode_list($v));
        }
        rebuild_archive_rows();
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    json_out(200, ['message' => 'Backup restored.', 'keys_restored' => count($data), 'users_added' => $addedUsers]);
    break;

// ------------------------------------------------------------------
default:
    json_out(404, ['error' => 'API endpoint not found.']);
}
