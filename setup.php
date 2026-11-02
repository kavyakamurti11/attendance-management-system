<?php
/* setup.php - run once (works on localhost only).
   1) checks the tables  2) stores the admin (config.php) in the admins table with bcrypt
   3) moves faculty/student accounts from the old `users` table (if present)
   4) fills the classes/attendance tables from app_data                           */
require_once __DIR__ . '/includes/lib.php';
header('Content-Type: text/html; charset=utf-8');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1'], true)) { http_response_code(403); exit('Setup only works on localhost.'); }

$out = [];
try {
    $pdo = db();
    $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
        AND table_name IN ('admins','faculty','students','password_resets','login_attempts','app_data','classes','class_students','attendance')")->fetchColumn();
    if ($n < 9) throw new RuntimeException('Tables missing (' . $n . '/9). First import database/schema.sql in phpMyAdmin.');
    $out[] = '✅ All 9 tables found.';

    $hasCol = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'attendance' AND column_name = 'archived'")->fetchColumn();
    if (!$hasCol) { $pdo->exec("ALTER TABLE attendance ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER status"); $out[] = '✅ Column attendance.archived added.'; }

    ensure_mirror_schema();
    ensure_plain_schema();
    $out[] = '✅ Password columns are plain text now and the <b>student_details</b> table exists.';
    // the admin password always comes from config.php (an old bcrypt hash cannot be turned back into text)
    if (ADMIN_EMAIL !== '' && ADMIN_PASSWORD !== '')
        $pdo->prepare('UPDATE admins SET password = ? WHERE email = ?')->execute([ADMIN_PASSWORD, norm(ADMIN_EMAIL)]);
    ensure_admin_seed();
    $a = $pdo->query('SELECT email FROM admins LIMIT 1')->fetchColumn();
    $out[] = '✅ Admin ready: ' . htmlspecialchars((string)$a) . ' (password stored as plain text, taken from config.php).';

    $old = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn();
    if ($old) {
        $moved = 0;
        foreach ($pdo->query("SELECT name, email, role, roll, division, password_hash AS pw FROM users")->fetchAll() as $r) {
            if ($r['pw'] === '' || $r['pw'] === null) continue;        // fakt bcrypt hashes
            if ($r['role'] === 'faculty' && $r['email']) {
                $q = $pdo->prepare('INSERT IGNORE INTO faculty (name, email, password) VALUES (?,?,?)');
                $q->execute([$r['name'], norm($r['email']), $r['pw']]); $moved += $q->rowCount();
            } elseif ($r['role'] === 'student' && $r['roll'] && $r['division']) {
                $q = $pdo->prepare('INSERT IGNORE INTO students (name, roll, division, password) VALUES (?,?,?,?)');
                $q->execute([$r['name'], $r['roll'], $r['division'], $r['pw']]); $moved += $q->rowCount();
            }
        }
        $out[] = "✅ Moved $moved account(s) from the old `users` table to the new faculty / students tables. (The old admin account was not copied - use the admin from config.php. You can delete the old `users` table.)";
    }

    // fill student_details from the students table
    $legacy = 0; $sd = 0;
    foreach ($pdo->query('SELECT name, roll, division, password FROM students')->fetchAll() as $r) {
        $info = resolve_student_class_info((string)$r['roll'], (string)$r['division']);
        if (is_legacy_hash((string)$r['password'])) $legacy++;
        upsert_student_details((string)$r['name'], (string)$r['roll'], (string)$r['division'], $info['division'] ?? '', '', (string)$r['password']);
        $sd++;
    }
    $out[] = "✅ student_details filled for $sd student(s)." . ($legacy ? " ⚠️ $legacy old account(s) still have an old bcrypt hash: the original password cannot be recovered from a hash. Their password shows after the student logs in once (or ask them to reset / re-register)." : '');

    $pdo->beginTransaction();
    $k = rebuild_all_class_tables();
    $pdo->commit();
    $c = (int)$pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn();
    $s = (int)$pdo->query('SELECT COUNT(*) FROM class_students')->fetchColumn();
    $t = (int)$pdo->query('SELECT COUNT(*) FROM attendance')->fetchColumn();
    $out[] = "✅ Filled the tables from $k faculty record(s) in app_data: classes=$c, class_students=$s, attendance=$t.";
    $out[] = '👉 Now open <a href="admin_login.html">admin_login.html</a>. After setup, delete the <b>setup.php</b> file.';
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $out[] = '❌ ' . htmlspecialchars($e->getMessage());
}
echo '<!doctype html><meta charset="utf-8"><title>Setup</title><body style="font-family:Segoe UI,sans-serif;max-width:720px;margin:40px auto;line-height:1.7"><h2>Attendance setup</h2><p>' . implode('</p><p>', $out) . '</p>';
