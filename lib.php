<?php
/* ============================================================
   lib.php - shared helpers + business rules for the Attendance API
   (permissions, privacy filters, roll-number rule, notification merge,
    archive protection). Everything is enforced on the server, not in the browser.
   Requires PHP 7.4+
   ============================================================ */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

// ---------- small helpers ----------
function starts_with(string $h, string $n): bool { return strncmp($h, $n, strlen($n)) === 0; }
function norm($s): string {
    $s = trim((string)($s ?? ''));
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}
function enc($v): string {
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}
function arr_is_list(array $a): bool {
    $i = 0;
    foreach ($a as $k => $_) { if ($k !== $i++) return false; }
    return true;
}
function decode_list($raw): array {
    $d = json_decode((string)($raw === null || $raw === '' ? '[]' : $raw), true);
    return (is_array($d) && arr_is_list($d)) ? $d : [];
}
function key_is_valid(string $k): bool { return (bool)preg_match('/^[A-Za-z0-9_@.+\-]{1,255}$/', $k); }
function is_classes_key(string $k): bool    { return $k === 'classes'    || starts_with($k, 'classes_'); }
function is_timetables_key(string $k): bool { return $k === 'timetables' || starts_with($k, 'timetables_'); }
function str_id($v): string { return (string)($v ?? ''); }

// ---------- database ----------
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}
function get_value(string $key): ?string {
    $st = db()->prepare('SELECT data_value FROM app_data WHERE data_key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}
function put_value(string $key, string $value): void {
    $st = db()->prepare('INSERT INTO app_data (data_key, data_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE data_value = VALUES(data_value)');
    $st->execute([$key, $value]);
}
// every classes / classes_<email> row (optionally skipping one key)
function class_rows(?string $exceptKey = null): array {
    $sql = "SELECT data_key, data_value FROM app_data WHERE (data_key = 'classes' OR data_key LIKE 'classes\\_%')";
    $args = [];
    if ($exceptKey !== null) { $sql .= ' AND data_key <> ?'; $args[] = $exceptKey; }
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

// ---------- HTTP / JSON ----------
function json_out(int $status, array $data) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
function request_body(): array {
    $d = json_decode((string)file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

// ---------- sessions ----------
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('ATTSESS');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}
function public_user(array $r): array {
    $u = ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => !empty($r['email']) ? $r['email'] : null, 'role' => $r['role']];
    if ($r['role'] === 'student') { $u['roll'] = $r['roll']; $u['division'] = $r['division']; }
    return $u;
}
// One table per role: admins / faculty / students
function account_table(string $role): string {
    return ['admin' => 'admins', 'faculty' => 'faculty', 'student' => 'students'][$role];
}
function find_account(string $role, int $id): ?array {
    if ($role === 'student') $sql = "SELECT id, name, NULL AS email, 'student' AS role, roll, division FROM students WHERE id = ?";
    else $sql = "SELECT id, name, email, '" . $role . "' AS role, NULL AS roll, NULL AS division FROM " . account_table($role) . ' WHERE id = ?';
    $st = db()->prepare($sql);
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}
// Returns the logged-in user row, or null. $touch=false => polling must not extend the idle timer.
function current_user(bool $touch = true): ?array {
    start_session();
    $id = $_SESSION['uid'] ?? null;
    $role = $_SESSION['role'] ?? '';
    if (!$id || !in_array($role, ['admin', 'faculty', 'student'], true)) { session_write_close(); return null; }
    if (time() - (int)($_SESSION['last'] ?? 0) > SESSION_MINUTES * 60) {
        session_unset(); session_destroy(); return null;
    }
    $row = find_account($role, (int)$id);
    if (!$row) { session_unset(); session_destroy(); return null; }
    if ($touch) $_SESSION['last'] = time();
    session_write_close();       // don't block parallel requests of the same user
    return $row;
}
function require_user(bool $touch = true): array {
    $u = current_user($touch);
    if (!$u) json_out(401, ['error' => 'Session expired. Please login again.']);
    return $u;
}

// ---------- admin seed (admin from config.php -> admins table, bcrypt hash) ----------
function ensure_admin_seed(): void {
    ensure_plain_schema();
    $pdo = db();
    if ((int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) return;
    if (ADMIN_EMAIL === '' || ADMIN_PASSWORD === '') return;
    $pdo->prepare('INSERT INTO admins (name, email, password) VALUES (?,?,?)')
        ->execute([ADMIN_NAME, norm(ADMIN_EMAIL), ADMIN_PASSWORD]);
}

// ---------- passwords : stored as PLAIN TEXT (no hashing) ----------
// Old bcrypt values ($2y$...) cannot be turned back into the original password. They are still accepted at login
// (checked once with password_verify) and are then replaced by the plain password automatically.
function store_password(string $pw): string { return $pw; }
function is_legacy_hash(string $stored): bool { return (bool)preg_match('/^\$2[aby]\$\d{2}\$.{53}$/', $stored); }
function check_password(string $pw, string $stored): bool {
    if (is_legacy_hash($stored)) return password_verify($pw, $stored);
    return $stored !== '' && hash_equals($stored, $pw);
}

// Creates / renames what the plain-password version needs (safe to call many times, must run OUTSIDE a transaction).
function ensure_plain_schema(): void {
    static $done = false;
    if ($done) return;
    $pdo = db();
    $has = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    foreach ([['admins', 'password_hash', 'password'], ['faculty', 'password_hash', 'password'],
              ['students', 'password_hash', 'password'], ['password_resets', 'code_hash', 'code']] as [$t, $old, $new]) {
        if ($has($t, $old) && !$has($t, $new)) $pdo->exec("ALTER TABLE `$t` CHANGE `$old` `$new` VARCHAR(255) NOT NULL");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_details (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(150) NOT NULL,
        roll_number VARCHAR(80)  NOT NULL,
        class_name  VARCHAR(150) NOT NULL,
        division    VARCHAR(150) NOT NULL DEFAULT '',
        email       VARCHAR(190) NOT NULL DEFAULT '',
        password    VARCHAR(255) NOT NULL DEFAULT '',
        created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sd (roll_number, class_name)
    ) ENGINE=InnoDB");
    $done = true;
}
// key stored in password_resets.email for one reset request
function reset_key(string $role, string $email, string $roll = ''): string {
    return $role . ':' . md5(norm($email) . '|' . norm($roll));
}
// Adds / updates one row of student_details. An empty email or division never overwrites a saved value.
function upsert_student_details(string $name, string $roll, string $className, string $division, string $email, string $password): void {
    $shown = is_legacy_hash($password) ? '' : $password;      // an old hash is not a password: show nothing until the next login
    db()->prepare("INSERT INTO student_details (name, roll_number, class_name, division, email, password) VALUES (?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE name = VALUES(name), password = VALUES(password),
                   division = IF(VALUES(division) = '', division, VALUES(division)),
                   email    = IF(VALUES(email) = '', email, VALUES(email))")
        ->execute([mb_substr($name, 0, 150), mb_substr($roll, 0, 80), mb_substr($className, 0, 150),
                   mb_substr($division, 0, 150), mb_substr($email, 0, 190), $shown]);
}

// ---------- permission rules ----------
function can_read_key(string $key, array $u): bool {
    if ($u['role'] === 'admin') return true;
    $em = norm($u['email']);
    if ($u['role'] === 'faculty') {
        if (in_array($key, ['notifications', 'classes', 'timetables', 'attendance_archive'], true)) return true;
        return $key === 'classes_' . $em || $key === 'timetables_' . $em;
    }
    return in_array($key, ['classes', 'timetables', 'notifications', 'attendance_archive'], true)
        || is_classes_key($key) || is_timetables_key($key);
}
function allowed_write_key(string $key, array $u): bool {
    if ($u['role'] === 'admin') return true;
    $em = norm($u['email']);
    if ($u['role'] === 'faculty') return in_array($key, ['classes_' . $em, 'timetables_' . $em, 'notifications', 'attendance_archive'], true);
    return $key === 'notifications';
}

// ---------- student privacy filters ----------
// Does the text typed by the student ("BCAIII", "A", "BCAIII - A", "BCAIII A") point to this class?
function class_matches_label($c, string $input): bool {
    if (!is_array($c)) return false;
    $in  = preg_replace('/\s+/', ' ', norm($input));
    $in2 = trim(preg_replace('/\s*[-\x{2013}\x{2014}\/]\s*/u', ' ', $in));
    $name = norm($c['name'] ?? '');
    $div  = norm($c['division'] ?? '');
    $cands = [$name];
    if ($div !== '') { $cands[] = $div; $cands[] = $name . ' ' . $div; $cands[] = $div . ' ' . $name; }
    foreach ($cands as $x) { if ($x !== '' && ($x === $in || $x === $in2)) return true; }
    return false;
}
function class_has_roll($c, string $roll): bool {
    $roll = norm($roll);
    foreach (($c['students'] ?? []) as $s) {
        if (is_array($s) && norm($s['roll'] ?? '') === $roll) return true;
    }
    return false;
}
// Returns ['name' => canonical class name, 'division' => division] or null
function resolve_student_class_info(string $roll, string $input): ?array {
    foreach (class_rows() as $r) {
        foreach (decode_list($r['data_value']) as $c) {
            if (class_matches_label($c, $input) && class_has_roll($c, $roll))
                return ['name' => (string)($c['name'] ?? ''), 'division' => trim((string)($c['division'] ?? ''))];
        }
    }
    return null;
}
// Returns the canonical class NAME (what is stored in students.division) or null
function resolve_student_class(string $roll, string $input): ?string {
    $i = resolve_student_class_info($roll, $input);
    return $i === null ? null : $i['name'];
}
// Distinct "class name + divisions" list for the sign-up / login drop-down
function public_class_options(): array {
    $map = [];
    foreach (class_rows() as $r) {
        foreach (decode_list($r['data_value']) as $c) {
            if (!is_array($c) || trim((string)($c['name'] ?? '')) === '') continue;
            $k = norm($c['name']);
            if (!isset($map[$k])) $map[$k] = ['name' => (string)$c['name'], 'divisions' => []];
            $d = trim((string)($c['division'] ?? ''));
            if ($d !== '' && !in_array($d, $map[$k]['divisions'], true)) $map[$k]['divisions'][] = $d;
        }
    }
    return array_values($map);
}
function class_has_student($c, array $u): bool {
    if (!is_array($c)) return false;
    return class_matches_label($c, (string)($u['division'] ?? '')) && class_has_roll($c, (string)($u['roll'] ?? ''));
}
function student_class_ids(array $all, array $u): array {
    $ids = [];
    foreach ($all as $k => $raw) {
        if (!is_classes_key((string)$k)) continue;
        foreach (decode_list($raw) as $c) {
            if (class_has_student($c, $u)) $ids[str_id($c['id'] ?? '')] = true;
        }
    }
    return $ids;
}
function filter_student_classes($raw, array $u): string {
    $roll = norm($u['roll']);
    $out = [];
    foreach (decode_list($raw) as $c) {
        if (!class_has_student($c, $u)) continue;
        $copy = $c;
        $copy['students'] = array_values(array_filter($c['students'] ?? [], function ($s) use ($roll) {
            return is_array($s) && norm($s['roll'] ?? '') === $roll;
        }));
        $att = [];
        if (isset($c['attendance']) && is_array($c['attendance'])) {
            foreach ($c['attendance'] as $date => $recs) {
                $recs = is_array($recs) ? $recs : [];
                $att[$date] = array_values(array_filter($recs, function ($r) use ($roll) {
                    return is_array($r) && isset($r['student']) && is_array($r['student'])
                        && norm($r['student']['roll'] ?? '') === $roll;
                }));
            }
        }
        $copy['attendance'] = $att ?: new stdClass();      // keep {} (not [])
        $out[] = $copy;
    }
    return enc($out);
}
function filter_student_timetables($raw, array $ids): string {
    return enc(array_values(array_filter(decode_list($raw), function ($t) use ($ids) {
        return is_array($t) && isset($ids[str_id($t['classId'] ?? '')]);
    })));
}
function filter_notifications($raw, array $u): string {
    $arr = decode_list($raw);
    if ($u['role'] !== 'student') return enc($arr);
    $roll = norm($u['roll']); $div = norm($u['division']);
    return enc(array_values(array_filter($arr, function ($n) use ($roll, $div) {
        return is_array($n) && norm($n['roll'] ?? '') === $roll
            && (!isset($n['className']) || norm($n['className']) === $div);
    })));
}
function ids_from_classes_raw($raw): array {
    $ids = [];
    foreach (decode_list($raw) as $c) { if (is_array($c)) $ids[str_id($c['id'] ?? '')] = true; }
    return $ids;
}
// A faculty member only sees archive entries of their own classes
function filter_archive_for_ids($raw, array $ids): string {
    return enc(array_values(array_filter(decode_list($raw), function ($e) use ($ids) {
        return is_array($e) && isset($ids[str_id($e['classId'] ?? '')]);
    })));
}
// A student only gets his own rows of the archive (own roll, own classes)
function filter_student_archive($raw, array $u, array $ids): string {
    $roll = norm($u['roll']);
    $out = [];
    foreach (decode_list($raw) as $e) {
        if (!is_array($e) || !isset($ids[str_id($e['classId'] ?? '')])) continue;
        $copy = $e;
        $copy['students'] = array_values(array_filter($e['students'] ?? [], function ($s) use ($roll) {
            return is_array($s) && norm($s['roll'] ?? '') === $roll;
        }));
        $rec = [];
        foreach (($e['records'] ?? []) as $date => $rows) {
            $rows = is_array($rows) ? $rows : [];
            $mine = array_values(array_filter($rows, function ($r) use ($roll) {
                return is_array($r) && isset($r['student']) && is_array($r['student']) && norm($r['student']['roll'] ?? '') === $roll;
            }));
            if ($mine) $rec[$date] = $mine;
        }
        $copy['records'] = $rec ?: new stdClass();
        $out[] = $copy;
    }
    return enc($out);
}
function apply_read_filters(string $key, $raw, array $u, array $classIds): string {
    if ($key === 'notifications') return filter_notifications($raw, $u);
    if ($key === 'attendance_archive' && $u['role'] === 'faculty') return filter_archive_for_ids($raw, $classIds);
    if ($key === 'attendance_archive' && $u['role'] === 'student') return filter_student_archive($raw, $u, $classIds);
    if ($u['role'] === 'student') {
        if (is_classes_key($key))    return filter_student_classes($raw, $u);
        if (is_timetables_key($key)) return filter_student_timetables($raw, $classIds);
    }
    return (string)$raw;
}

// ---------- what this user is allowed to see (GET sync) ----------
function build_view(array $u): array {
    $pdo = db();
    if ($u['role'] === 'admin') {
        $st = $pdo->query('SELECT data_key, data_value FROM app_data');
    } elseif ($u['role'] === 'faculty') {
        $em = norm($u['email']);
        $st = $pdo->prepare('SELECT data_key, data_value FROM app_data WHERE data_key IN (?,?,?,?,?,?)');
        $st->execute(['notifications', 'classes', 'timetables', 'classes_' . $em, 'timetables_' . $em, 'attendance_archive']);
    } else {
        $st = $pdo->query("SELECT data_key, data_value FROM app_data
                           WHERE data_key IN ('notifications','attendance_archive') OR data_key LIKE 'classes%' OR data_key LIKE 'timetables%'");
    }
    $all = [];
    foreach ($st->fetchAll() as $r) $all[(string)$r['data_key']] = $r['data_value'];

    $ids = [];
    if ($u['role'] === 'student') $ids = student_class_ids($all, $u);
    elseif ($u['role'] === 'faculty') $ids = ids_from_classes_raw($all['classes_' . norm($u['email'])] ?? '[]');
    $data = [];
    foreach ($all as $k => $raw) {
        if (!can_read_key($k, $u)) continue;
        $val = apply_read_filters($k, $raw, $u, $ids);
        if ($u['role'] === 'student' && $val === '[]' && (is_classes_key($k) || is_timetables_key($k))) continue;
        $data[$k] = $val;
    }
    if ($u['role'] === 'admin') {
        $rows = $pdo->query("SELECT id, name, email, 'admin' AS role, NULL AS roll, NULL AS division, created_at FROM admins
                             UNION ALL SELECT id, name, email, 'faculty', NULL, NULL, created_at FROM faculty
                             UNION ALL SELECT id, name, NULL, 'student', roll, division, created_at FROM students")->fetchAll();
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; } unset($r);
        $data['users'] = enc($rows);
    }

    // faculty: (roll, class name) pairs of OTHER faculty's classes, so the "same roll in a different class" check works in the UI
    $rolls = [];
    if ($u['role'] === 'faculty') {
        $seen = [];
        foreach (class_rows('classes_' . norm($u['email'])) as $r) {
            foreach (decode_list($r['data_value']) as $c) {
                if (!is_array($c)) continue;
                foreach (($c['students'] ?? []) as $s) {
                    if (!is_array($s)) continue;
                    $k = norm($s['roll'] ?? '') . '|' . norm($c['name'] ?? '');
                    if (isset($seen[$k])) continue;
                    $seen[$k] = true;
                    $rolls[] = [(string)($s['roll'] ?? ''), (string)($c['name'] ?? '')];
                }
            }
        }
    }
    return ['data' => (object)$data, 'rolls' => $rolls];
}

// ---------- roll-number rule ----------
// A roll number may appear in several classes ONLY when the class NAME is the same
// (BCAIII-Java and BCAIII-Python). Same roll in BCAII and BCAIII is refused.
function roll_conflict_on_save(string $key, array $list): ?string {
    $had = [];
    foreach (decode_list(get_value($key)) as $c) {
        if (!is_array($c)) continue;
        foreach (($c['students'] ?? []) as $s) {
            if (is_array($s)) $had[norm($c['name'] ?? '') . '|' . norm($s['roll'] ?? '')] = true;
        }
    }
    $others = [];                       // roll -> class name, all OTHER keys
    foreach (class_rows($key) as $r) {
        foreach (decode_list($r['data_value']) as $c) {
            if (!is_array($c)) continue;
            foreach (($c['students'] ?? []) as $s) {
                if (!is_array($s)) continue;
                $rr = norm($s['roll'] ?? '');
                if (!isset($others[$rr]) || $others[$rr] === '') $others[$rr] = (string)($c['name'] ?? '');
            }
        }
    }
    $mine = [];                         // roll -> class name inside this save
    foreach ($list as $c) {
        if (!is_array($c)) continue;
        $cn = (string)($c['name'] ?? '');
        foreach (($c['students'] ?? []) as $s) {
            if (!is_array($s)) continue;
            $rr = norm($s['roll'] ?? '');
            $rawRoll = (string)($s['roll'] ?? '');
            if (isset($mine[$rr]) && norm($mine[$rr]) !== norm($cn))
                return 'Roll number "' . $rawRoll . '" is in two different classes ("' . $mine[$rr] . '" and "' . $cn . '"). A roll number must belong to one class name only.';
            $mine[$rr] = $cn;
            if (isset($had[norm($cn) . '|' . $rr])) continue;
            if (isset($others[$rr]) && norm($others[$rr]) !== norm($cn))
                return 'Roll number "' . $rawRoll . '" already exists in class "' . $others[$rr] . '". The same roll number cannot be in a different class.';
        }
    }
    return null;
}

// A stale faculty page must not bring already-archived days back.
function strip_archived_days(string $json): string {
    $arch = json_decode((string)(get_value('attendance_archive') ?? '[]'));
    $list = json_decode($json);                       // objects (not arrays) so {} stays {}
    if (!is_array($arch) || !is_array($list)) return $json;
    foreach ($list as $c) {
        if (!is_object($c) || !isset($c->attendance) || !is_object($c->attendance)) continue;
        foreach ($arch as $e) {
            if (!is_object($e) || !isset($e->classId) || !isset($c->id) || $e->classId !== $c->id) continue;
            if (!isset($e->records) || !is_object($e->records)) continue;
            foreach (get_object_vars($e->records) as $d => $recs) {
                if (isset($c->attendance->{$d}) && enc($c->attendance->{$d}) === enc($recs)) unset($c->attendance->{$d});
            }
        }
    }
    return enc($list);
}

// ---------- notifications: MERGED so two users never wipe each other's alerts ----------
function merge_notifications(string $existingRaw, string $incomingRaw, array $u): string {
    $existing = decode_list($existingRaw);
    $incoming = array_values(array_filter(decode_list($incomingRaw), 'is_array'));
    if ($u['role'] === 'student') {
        $roll = norm($u['roll']);
        $incoming = array_values(array_filter($incoming, function ($n) use ($roll) { return norm($n['roll'] ?? '') === $roll; }));
    }
    $byId = [];
    foreach ($existing as $n) {
        if (!is_array($n)) continue;
        $id = isset($n['id']) ? (string)$n['id'] : 'x' . bin2hex(random_bytes(4));
        $byId[$id] = $n;
    }
    foreach ($incoming as $n) {
        $id = isset($n['id']) ? (string)$n['id'] : 'n' . bin2hex(random_bytes(4));
        if ($u['role'] === 'student' && !isset($byId[$id])) { $n['id'] = $id; $byId[$id] = $n; continue; }
        if ($u['role'] === 'student' && norm($byId[$id]['roll'] ?? '') !== norm($u['roll'])) continue; // never touch others'
        $n['id'] = $id; $byId[$id] = $n;
    }
    $merged = array_values($byId);
    usort($merged, function ($a, $b) { return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')); });
    return enc(array_slice($merged, 0, 5000));
}

// ---------- save / delete one key (mirrors the rules the dashboards expect) ----------
// Returns [status, payload]
function save_key(array $u, string $key, string $v): array {
    if ($key === 'users' || !key_is_valid($key) || !allowed_write_key($key, $u))
        return [403, ['error' => 'You do not have permission to change this data.']];
    $parsed = json_decode($v, true);
    if (json_last_error() !== JSON_ERROR_NONE) return [400, ['error' => 'Data must contain valid JSON.']];
    $needsArray = is_classes_key($key) || is_timetables_key($key) || $key === 'notifications' || $key === 'attendance_archive';
    if ($needsArray && (!is_array($parsed) || substr(ltrim($v), 0, 1) !== '['))
        return [400, ['error' => 'This data must be a JSON array.']];

    $pdo = db();
    if (is_classes_key($key) || $key === 'attendance_archive') ensure_mirror_schema();
    $warning = null;
    $pdo->beginTransaction();
    try {
        if (is_classes_key($key)) {
            $msg = roll_conflict_on_save($key, $parsed);
            if ($msg) { $pdo->rollBack(); return [409, ['error' => $msg]]; }
            $v = strip_archived_days($v);
        }
        $syncList = null;
        if (is_classes_key($key)) $syncList = decode_list($v);
        $cacheValue = $v;
        if ($key === 'notifications' && $u['role'] !== 'admin') {
            $st = $pdo->prepare('SELECT data_value FROM app_data WHERE data_key = ? FOR UPDATE');
            $st->execute([$key]);
            $cur = $st->fetchColumn();
            $v = merge_notifications($cur === false ? '[]' : (string)$cur, $v, $u);
            $cacheValue = filter_notifications($v, $u);
        }
        if ($key === 'attendance_archive' && $u['role'] === 'faculty') {
            // merge: keep other faculty's entries, replace only entries of this faculty's own classes
            $own = ids_from_classes_raw(get_value('classes_' . norm($u['email'])) ?? '[]');
            $st = $pdo->prepare('SELECT data_value FROM app_data WHERE data_key = ? FOR UPDATE');
            $st->execute([$key]);
            $cur = $st->fetchColumn();
            $keep = array_values(array_filter(decode_list($cur === false ? '[]' : (string)$cur), function ($e) use ($own) {
                return is_array($e) && !isset($own[str_id($e['classId'] ?? '')]);
            }));
            $mine = array_values(array_filter($parsed, function ($e) use ($own) {
                return is_array($e) && isset($own[str_id($e['classId'] ?? '')]);
            }));
            $v = enc(array_merge($keep, $mine));
            $cacheValue = enc($mine);
        }
        put_value($key, $v);
        if ($syncList !== null || $key === 'attendance_archive') {
            $warning = mirror_safely(function () use ($syncList, $key) {
                if ($syncList !== null) sync_class_tables(faculty_email_from_key($key), $syncList);
                rebuild_archive_rows();
            });
        }
        $pdo->commit();
        $out = ['ok' => true, 'value' => $cacheValue];
        if ($warning !== null) $out['warning'] = $warning;
        return [200, $out];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}


// ============================================================
//  PROPER TABLES: classes / class_students / attendance
//  (the dashboards keep using JSON, and the data is also written to these tables)
// ============================================================
function faculty_email_from_key(string $key): string {
    return $key === 'classes' ? '' : norm(substr($key, strlen('classes_')));
}
// "27/10/2026", "2026-10-27", "10/27/2026" -> "2026-10-27" (or null)
function date_key_to_iso(string $k): ?string {
    $k = trim($k);
    if (preg_match('/^(\d{4})\D(\d{1,2})\D(\d{1,2})/', $k, $m)) { $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3]; }
    elseif (preg_match('/^(\d{1,2})\D+(\d{1,2})\D+(\d{4})/', $k, $m)) {
        $a = (int)$m[1]; $b = (int)$m[2]; $y = (int)$m[3];
        if ($a > 12)      { $d = $a; $mo = $b; }     // 27/10/2026
        elseif ($b > 12)  { $mo = $a; $d = $b; }     // 10/27/2026
        else              { $d = $a; $mo = $b; }     // India: dd/mm/yyyy
    } else {
        $t = strtotime($k);
        return $t ? date('Y-m-d', $t) : null;
    }
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}
function faculty_display_name(string $email): ?string {
    if ($email === '') return null;
    $st = db()->prepare('SELECT name FROM faculty WHERE email = ?');
    $st->execute([$email]);
    $n = $st->fetchColumn();
    return $n === false ? null : (string)$n;
}
// Rewrites one faculty member's (classes_<email>) data into the tables. Call inside a transaction.
function sync_class_tables(string $facEmail, array $list): void {
    $pdo = db();
    $pdo->prepare('DELETE FROM classes WHERE faculty_email = ?')->execute([$facEmail]);   // students + attendance CASCADE
    $facName = faculty_display_name($facEmail);
    $insC = $pdo->prepare('INSERT INTO classes (class_id, faculty_email, faculty_name, class_name, division, subject)
                           VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE faculty_email=VALUES(faculty_email), faculty_name=VALUES(faculty_name),
                           class_name=VALUES(class_name), division=VALUES(division), subject=VALUES(subject)');
    $insS = $pdo->prepare('INSERT IGNORE INTO class_students (class_id, roll, student_name) VALUES (?,?,?)');
    $insA = $pdo->prepare('INSERT IGNORE INTO attendance (class_id, faculty_email, class_name, subject, att_date, date_key, roll, student_name, status)
                           VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($list as $c) {
        if (!is_array($c)) continue;
        $cid = substr(str_id($c['id'] ?? ''), 0, 64);
        if ($cid === '') continue;
        $cname = mb_substr((string)($c['name'] ?? ''), 0, 150);
        $subj  = mb_substr((string)($c['subject'] ?? ''), 0, 150);
        $insC->execute([$cid, $facEmail, $facName ?? (isset($c['faculty']) ? mb_substr((string)$c['faculty'], 0, 150) : null),
                        $cname, ($c['division'] ?? '') !== '' ? mb_substr((string)$c['division'], 0, 150) : null, $subj !== '' ? $subj : null]);
        foreach (($c['students'] ?? []) as $s) {
            if (!is_array($s) || trim((string)($s['roll'] ?? '')) === '') continue;
            $insS->execute([$cid, mb_substr((string)$s['roll'], 0, 80), mb_substr((string)($s['name'] ?? ''), 0, 150)]);
        }
        foreach (($c['attendance'] ?? []) as $dateKey => $recs) {
            if (!is_array($recs)) continue;
            $dk = mb_substr((string)$dateKey, 0, 40);
            $iso = date_key_to_iso($dk);
            foreach ($recs as $r) {
                if (!is_array($r) || !is_array($r['student'] ?? null)) continue;
                $roll = trim((string)($r['student']['roll'] ?? ''));
                if ($roll === '') continue;
                $insA->execute([$cid, $facEmail, $cname, $subj !== '' ? $subj : null, $iso, $dk, mb_substr($roll, 0, 80),
                                mb_substr((string)($r['student']['name'] ?? ''), 0, 150), !empty($r['present']) ? 'Present' : 'Absent']);
            }
        }
    }
}
// Rebuilds the tables from the classes data in app_data (used by setup.php / admin restore)
function rebuild_all_class_tables(): int {
    $n = 0;
    foreach (class_rows() as $r) {
        sync_class_tables(faculty_email_from_key((string)$r['data_key']), decode_list($r['data_value']));
        $n++;
    }
    rebuild_archive_rows();
    return $n;
}

// Archived days stay visible in the `attendance` table (archived = 1). Call inside a transaction.
function rebuild_archive_rows(): void {
    $pdo = db();
    $pdo->exec('DELETE FROM attendance WHERE archived = 1');
    $arch = decode_list(get_value('attendance_archive') ?? '[]');
    if (!$arch) return;
    $cls = [];
    foreach ($pdo->query('SELECT class_id, faculty_email, class_name, subject FROM classes')->fetchAll() as $r) $cls[$r['class_id']] = $r;
    $ins = $pdo->prepare('INSERT IGNORE INTO attendance (class_id, faculty_email, class_name, subject, att_date, date_key, roll, student_name, status, archived)
                          VALUES (?,?,?,?,?,?,?,?,?,1)');
    foreach ($arch as $e) {
        if (!is_array($e)) continue;
        $cid = substr(str_id($e['classId'] ?? ''), 0, 64);
        if ($cid === '' || !isset($cls[$cid]) || !is_array($e['records'] ?? null)) continue;
        $c = $cls[$cid];
        foreach ($e['records'] as $dateKey => $recs) {
            if (!is_array($recs)) continue;
            $dk = mb_substr((string)$dateKey, 0, 40);
            $iso = date_key_to_iso($dk);
            foreach ($recs as $r) {
                if (!is_array($r) || !is_array($r['student'] ?? null)) continue;
                $roll = trim((string)($r['student']['roll'] ?? ''));
                if ($roll === '') continue;
                $ins->execute([$cid, $c['faculty_email'], $c['class_name'], $c['subject'], $iso, $dk, mb_substr($roll, 0, 80),
                               mb_substr((string)($r['student']['name'] ?? ''), 0, 150), !empty($r['present']) ? 'Present' : 'Absent']);
            }
        }
    }
}


// ============================================================
//  Self-healing schema: if the tables / the `archived` column are missing (older schema.sql imported),
//  create them automatically. Must run OUTSIDE a transaction (DDL commits implicitly).
// ============================================================
function ensure_mirror_schema(): void {
    static $done = false;
    if ($done) return;
    $pdo = db();
    $have = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()
                         AND table_name IN ('classes','class_students','attendance')")->fetchAll(PDO::FETCH_COLUMN);
    if (count($have) < 3) {
        $sql = @file_get_contents(__DIR__ . '/../database/schema.sql');
        if ($sql !== false) {
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            foreach (explode(';', $sql) as $stmt) {
                $stmt = trim($stmt);
                if ($stmt !== '' && preg_match('/^CREATE\s+(TABLE\s+IF\s+NOT\s+EXISTS\s+(classes|class_students|attendance)\b|OR\s+REPLACE\s+VIEW)/i', $stmt)) {
                    try { $pdo->exec($stmt); } catch (Throwable $e) { error_log('[attendance] schema: ' . $e->getMessage()); }
                }
            }
        }
    }
    $col = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
                        AND table_name = 'attendance' AND column_name = 'archived'")->fetchColumn();
    if ((int)$col === 0 && in_array('attendance', $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll(PDO::FETCH_COLUMN), true)) {
        $pdo->exec('ALTER TABLE attendance ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER status');
    }
    $done = true;
}
// Run a mirror-table update inside the open transaction. A failure must not block saving the dashboard JSON:
// roll back to the savepoint, log the reason and return it (null = ok).
function mirror_safely(callable $fn): ?string {
    $pdo = db();
    try {
        $pdo->exec('SAVEPOINT mirror_sp');
        $fn();
        $pdo->exec('RELEASE SAVEPOINT mirror_sp');
        return null;
    } catch (Throwable $e) {
        try { $pdo->exec('ROLLBACK TO SAVEPOINT mirror_sp'); } catch (Throwable $x) {}
        error_log('[attendance] mirror tables: ' . $e->getMessage());
        return $e->getMessage();
    }
}
