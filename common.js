/* ============================================================
   common.js - shared helpers for the Attendance Management System
   BACKEND VERSION: PHP + MySQL (api/index.php)

   The page code does not need to change: the interface is the same
     DB.getItem / setItem / removeItem / flush / key / length
     apiPost('/api/login' | '/api/register' | '/api/logout')
   Data is now saved in MySQL, not in the browser. Permission /
   privacy / roll-number rules server (includes/lib.php) var enforce hotat.
   ============================================================ */

// ---------- tiny helpers ----------
function norm(s) { return String(s == null ? '' : s).trim().toLowerCase(); }
function isClassesKey(k)    { return k === 'classes'    || k.startsWith('classes_'); }
function isTimetablesKey(k) { return k === 'timetables' || k.startsWith('timetables_'); }
function decodeList(raw) {
  try { const d = JSON.parse(raw || '[]'); return Array.isArray(d) ? d : []; } catch (e) { return []; }
}
function getSessionUser() {
  try { return JSON.parse(sessionStorage.getItem('currentUser') || 'null'); } catch (e) { return null; }
}

// ---------- API ----------
function apiUrl(route) {
  return 'api/index.php?r=' + encodeURIComponent(String(route).replace(/^\/?(api\/)?/, ''));
}
async function apiPost(url, body) {
  const route = String(url).replace(/^\/?(api\/)?/, '');
  const res = await fetch(apiUrl(route), {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body || {})
  });
  let data = null;
  try { data = await res.json(); }
  catch (e) { data = { error: 'Invalid response from the server (possibly a PHP error). Check Apache, MySQL and the config.' }; }
  return { ok: res.ok, status: res.status, data };
}

// ---------- roll numbers of OTHER faculty's classes (sent by the server) ----------
let ROLL_PAIRS = [];      // [[roll, className], ...]

// ---------- DB : same interface as before, backed by the PHP API ----------
const DB = (function () {
  let cache = new Map();
  let lastWarn = 0;
  let lastWriteOk = true;
  const dirty = new Map();          // key -> { op, value, waiters:[] }   (writes not yet sent)
  const latest = {};                // key -> sequence number of the newest local write
  let seq = 0, timer = null, running = null;

  function warn(msg) {
    const now = Date.now();
    if (now - lastWarn > 8000) { lastWarn = now; alert(msg); }
  }
  function sessionExpired() {
    const u = getSessionUser();
    sessionStorage.removeItem('currentUser');
    warn('Your session expired. Please login again.');
    location.href = (u && u.role ? u.role : 'student') + '_login.html';
  }

  // merge a fresh server view into the cache, but never overwrite keys that have unsent local changes
  function mergeView(data) {
    data = data || {};
    Object.keys(data).forEach(k => { if (!dirty.has(k)) cache.set(k, data[k]); });
    Array.from(cache.keys()).forEach(k => { if (!(k in data) && !dirty.has(k)) cache.delete(k); });
  }

  async function send(k, e) {
    const mySeq = e.seq;
    try {
      const r = await apiPost(e.op === 'delete' ? '/api/delete' : '/api/save',
                              e.op === 'delete' ? { key: k } : { key: k, value: e.value });
      if (r.status === 401) { sessionExpired(); return false; }
      if (!r.ok) {
        lastWriteOk = false;
        warn((r.data && r.data.error) || 'Could not save the data.');
        try { const v = await fetchView(true); if (v) mergeView(v.data); } catch (x) {}   // put the cache back to the server state
        return false;
      }
      // the server may have merged / filtered the value (notifications) - use it unless a newer local write exists
      if (e.op !== 'delete' && r.data && typeof r.data.value === 'string' && latest[k] === mySeq && !dirty.has(k))
        cache.set(k, r.data.value);
      if (r.data && r.data.warning) warn('Saved, but the database tables could not be updated: ' + r.data.warning);
      lastWriteOk = true;
      return true;
    } catch (err) {
      lastWriteOk = false;
      warn('⚠️ Cannot connect to the server. Check that Apache/MySQL are running. Your last change may not be saved.');
      return false;
    }
  }

  function startRun() {
    if (running) return;
    running = (async function () {
      while (dirty.size) {
        const [k, e] = dirty.entries().next().value;
        dirty.delete(k);
        const ok = await send(k, e);
        e.waiters.forEach(w => w(ok));
      }
    })().finally(() => { running = null; if (dirty.size) schedule(); });
  }
  function schedule() {
    if (timer || running) return;
    timer = setTimeout(() => { timer = null; startRun(); }, 30);   // small delay => many quick writes of one key become ONE request
  }
  function enqueue(k, op, value) {
    let e = dirty.get(k);
    if (!e) { e = { op, value, waiters: [], seq: 0 }; dirty.set(k, e); }
    e.op = op; e.value = value; e.seq = ++seq; latest[k] = e.seq;
    const p = new Promise(res => e.waiters.push(res));
    schedule();
    return p;
  }

  window.addEventListener('beforeunload', function (ev) {
    if (dirty.size || running) { ev.preventDefault(); ev.returnValue = ''; }
  });

  return {
    getItem(k) { return cache.has(k) ? cache.get(k) : null; },

    setItem(k, v) {
      v = String(v);
      if (!getSessionUser()) { sessionExpired(); return Promise.resolve(false); }
      cache.set(k, v);                       // optimistic: the page sees its change immediately
      return enqueue(k, 'save', v);          // resolves true / false when the server answered
    },

    removeItem(k) {
      if (!getSessionUser()) { sessionExpired(); return Promise.resolve(false); }
      cache.delete(k);
      return enqueue(k, 'delete', null);
    },

    async flush() {                          // wait until every pending write reached the server
      if (timer) { clearTimeout(timer); timer = null; }
      while (dirty.size || running) {
        if (!running) startRun();
        await running;
      }
      return lastWriteOk;
    },

    key(i) { return Array.from(cache.keys())[i] ?? null; },
    get length() { return cache.size; },
    _replace(obj) { mergeView(obj); },
    _hardReplace(obj) { cache = new Map(Object.entries(obj || {})); },
    get _pending() { return dirty.size + (running ? 1 : 0); }
  };
})();

// ---------- load what this user may see ----------
function fetchViewSync() {                    // used ONCE, before the page's own script runs
  const x = new XMLHttpRequest();
  x.open('GET', apiUrl('sync'), false);
  try { x.send(); } catch (e) { return { status: 0 }; }
  let data = null; try { data = JSON.parse(x.responseText); } catch (e) {}
  return { status: x.status, data };
}
async function fetchView(quiet) {             // background refresh
  const res = await fetch(apiUrl('sync') + (quiet ? '&poll=1' : ''), { credentials: 'same-origin' });
  if (res.status === 401) return null;
  const d = await res.json();
  if (d && d.rolls) ROLL_PAIRS = d.rolls;
  return d;
}

// ---------- Session helpers ----------
function clearSession() {
  sessionStorage.removeItem('currentUser');
  try {
    fetch(apiUrl('logout'), { method: 'POST', credentials: 'same-origin', keepalive: true,
      headers: { 'Content-Type': 'application/json' }, body: '{}' });
  } catch (e) {}
}

// ---------- Initial load (dashboard pages only: <script src="common.js" data-sync="role">) ----------
(function bootstrap() {
  const tag = document.currentScript;
  if (!tag || !tag.hasAttribute('data-sync')) return;

  const role = tag.getAttribute('data-sync');
  const loginPage = role + '_login.html';
  function fail() { sessionStorage.removeItem('currentUser'); window.location.href = loginPage; }

  const cu = getSessionUser();
  if (!cu || cu.role !== role) { fail(); return; }

  const r = fetchViewSync();
  if (r.status === 0 || r.status >= 500) {
    alert('Cannot connect to the server. Start Apache and MySQL in XAMPP and open the page at http://localhost/attendance/');
    fail(); return;
  }
  if (r.status !== 200 || !r.data || !r.data.user || r.data.user.role !== role) { fail(); return; }

  const u = r.data.user;                      // the server is the source of truth for who is logged in
  sessionStorage.setItem('currentUser', JSON.stringify(u));
  ROLL_PAIRS = r.data.rolls || [];
  DB._hardReplace(r.data.data);

  // Optional auto-refresh (data-poll="seconds")
  const poll = parseInt(tag.getAttribute('data-poll') || '0', 10);
  if (poll > 0) {
    setInterval(async function () {
      if (document.hidden) return;
      try {
        const v = await fetchView(true);
        if (!v) { fail(); return; }
        DB._replace(v.data);
        window.dispatchEvent(new Event('storage'));   // pages re-render on this event
      } catch (e) { /* server temporarily unreachable - try again next time */ }
    }, poll * 1000);
  }
})();

// ---------- Session timeout (auto-logout after inactivity) ----------
function initSessionTimeout(role, loginPage, minutes) {
  const timeoutMs = (minutes || 30) * 60 * 1000;
  let timer = null;

  function doLogout() {
    clearSession();
    alert('You were logged out due to inactivity. Please login again.');
    window.location.href = loginPage;
  }
  function resetTimer() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(doLogout, timeoutMs);
  }
  ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(evt => {
    document.addEventListener(evt, resetTimer, { passive: true });
  });
  resetTimer();
}

// ---------- Attendance date keys ----------
// Faculty saves attendance under new Date().toLocaleDateString() ("24/10/2026" in India, "10/24/2026" in the US ...).
// dateKeyToISO() turns any such key into "YYYY-MM-DD" so dates can be compared / sorted.
function dateKeyToISO(k, loc) {
  k = String(k).trim();
  const f = (y, m, d) => (+m >= 1 && +m <= 12 && +d >= 1 && +d <= 31) ? y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0') : null;
  let m = k.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
  if (m) return f(m[1], m[2], m[3]);
  m = k.match(/^(\d{1,4})\D+(\d{1,2})\D+(\d{1,4})$/);
  if (m) {
    const order = (new Date(2026, 11, 25).toLocaleDateString(loc).match(/\d+/g) || []).map(n => +n === 2026 ? 'y' : +n === 12 ? 'm' : 'd');
    const v = { y: null, m: null, d: null };
    if (order.length === 3) order.forEach((o, i) => { v[o] = m[i + 1]; });
    let r = (v.y && v.m && v.d) ? f(v.y, v.m, v.d) : null;
    if (!r && v.y && v.m && v.d) r = f(v.y, v.d, v.m);   // locale guess was wrong -> swap day / month
    if (r) return r;
  }
  const d = new Date(k);
  return isNaN(d) ? null : d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

// ---------- Roll-number rule (UI side; the server re-checks on every save) ----------
// A roll number may appear in several classes ONLY when those classes have the SAME class name
// (e.g. BCAIII-Java and BCAIII-Python). The same roll in different class names (BCAII vs BCAIII) is refused.
function allClassObjects() {
  const out = [];
  for (let i = 0; i < DB.length; i++) {
    const k = DB.key(i);
    if (k && isClassesKey(k)) decodeList(DB.getItem(k)).forEach(c => { if (c) out.push(c); });
  }
  return out;
}
// Returns the name of ANOTHER class (different class name) that already has this roll, or null.
function findRollInOtherClass(roll, className) {
  const r = norm(roll), n = norm(className);
  const mine = allClassObjects().find(c => norm(c.name) !== n && (c.students || []).some(s => s && norm(s.roll) === r));
  if (mine) return mine.name;
  const other = ROLL_PAIRS.find(p => norm(p[1]) !== n && norm(p[0]) === r);   // other faculty's classes
  return other ? other[1] : null;
}

// ---------- Cross-faculty data scanning (used by admin + student) ----------
// Scans every DB key that starts with "classes_" (one per faculty email) and returns a
// de-duplicated combined array, plus the legacy "classes" key for backward compatibility.
function loadAllClassesData() {
  let allClasses = [];
  for (let i = 0; i < DB.length; i++) {
    const key = DB.key(i);
    if (key && key.startsWith('classes_')) {
      try { allClasses = allClasses.concat(JSON.parse(DB.getItem(key) || '[]')); } catch (e) {}
    }
  }
  try { allClasses = allClasses.concat(JSON.parse(DB.getItem('classes') || '[]')); } catch (e) {}

  const seen = new Set();
  return allClasses.filter(c => {
    if (seen.has(c.id)) return false;
    seen.add(c.id);
    return true;
  });
}

function loadAllTimetablesData() {
  let allTT = [];
  for (let i = 0; i < DB.length; i++) {
    const key = DB.key(i);
    if (key && key.startsWith('timetables_')) {
      try { allTT = allTT.concat(JSON.parse(DB.getItem(key) || '[]')); } catch (e) {}
    }
  }
  try { allTT = allTT.concat(JSON.parse(DB.getItem('timetables') || '[]')); } catch (e) {}

  const seen = new Set();
  return allTT.filter(t => {
    if (seen.has(t.classId)) return false;
    seen.add(t.classId);
    return true;
  });
}

// ---------- Notifications (absent-marking + low monthly attendance) ----------
// Stored as one shared "notifications" array, tagged with the student's roll number
// so each student's login only receives their own (the server filters this).
function getAllNotifications() {
  try { return JSON.parse(DB.getItem('notifications') || '[]'); }
  catch (e) { return []; }
}

function saveAllNotifications(list) {
  DB.setItem('notifications', JSON.stringify(list));
}

function pushNotification(notif) {
  const list = getAllNotifications();
  list.unshift({
    id: 'n_' + Math.random().toString(36).slice(2, 9),
    read: false,
    date: new Date().toISOString(),
    ...notif
  });
  saveAllNotifications(list.slice(0, 5000));   // keep the list from growing forever
}

function getNotificationsForRoll(roll) {
  const r = String(roll).trim().toLowerCase();
  return getAllNotifications().filter(n => String(n.roll).trim().toLowerCase() === r);
}

function markNotificationRead(id) {
  const list = getAllNotifications();
  const n = list.find(x => x.id === id);
  if (n) { n.read = true; saveAllNotifications(list); }
}

function markAllNotificationsRead(roll) {
  const r = String(roll).trim().toLowerCase();
  const list = getAllNotifications();
  list.forEach(n => { if (String(n.roll).trim().toLowerCase() === r) n.read = true; });
  saveAllNotifications(list);
}

// Notify a student they were marked absent on a given date, unless we've
// already sent that exact notification (avoids duplicates on re-save).
function notifyAbsent(cls, student, dateKey) {
  const dup = getAllNotifications().some(n =>
    n.type === 'absent' && n.classId === cls.id &&
    String(n.roll) === String(student.roll) && n.dateKey === dateKey
  );
  if (dup) return;
  pushNotification({
    roll: student.roll,
    studentName: student.name,
    classId: cls.id,
    className: cls.name,
    faculty: cls.faculty,
    type: 'absent',
    dateKey: dateKey,
    message: `You were marked ABSENT in ${cls.name} (${cls.subject || ''}) on ${dateKey}.`
  });
}

// Checks this calendar month's attendance % for a student in a class; if it's
// below 75%, sends one low-attendance alert per student/class/month (not one
// per lecture, so it doesn't spam).
function checkAndNotifyLowMonthlyAttendance(cls, student) {
  const now = new Date();
  const monthKey = `${now.getFullYear()}-${now.getMonth() + 1}`;

  let total = 0, present = 0;
  if (cls.attendance) {
    Object.keys(cls.attendance).forEach(dateKey => {
      const d = new Date(dateKey);
      if (isNaN(d)) return;
      if (d.getFullYear() !== now.getFullYear() || d.getMonth() !== now.getMonth()) return;
      const rec = cls.attendance[dateKey].find(r => String(r.student.roll) === String(student.roll));
      if (rec) { total++; if (rec.present) present++; }
    });
  }
  if (total === 0) return;
  const pct = Math.round((present / total) * 100);
  if (pct >= 75) return;

  const alreadySent = getAllNotifications().some(n =>
    n.type === 'low_attendance' && n.classId === cls.id &&
    String(n.roll) === String(student.roll) && n.monthKey === monthKey
  );
  if (alreadySent) return;

  pushNotification({
    roll: student.roll,
    studentName: student.name,
    classId: cls.id,
    className: cls.name,
    faculty: cls.faculty,
    type: 'low_attendance',
    monthKey: monthKey,
    message: `⚠️ Your attendance in ${cls.name} this month is ${pct}% — below the 75% requirement.`
  });
}

// ---------- Admin backup / restore (browser console or phpMyAdmin) ----------
async function exportAllData() {
  const u = getSessionUser();
  if (!u || u.role !== 'admin') { alert('Export failed (admin only).'); return; }
  const res = await fetch(apiUrl('export'), { credentials: 'same-origin' });
  if (!res.ok) { alert('Export failed.'); return; }
  const blob = new Blob([JSON.stringify(await res.json(), null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `attendance_backup_${new Date().toISOString().split('T')[0]}.json`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

// Also accepts a backup file made by the OLD static (localStorage) version -> data + accounts are migrated.
function restoreAllData(file, onDone) {
  const reader = new FileReader();
  reader.onload = async function (e) {
    try {
      const u = getSessionUser();
      if (!u || u.role !== 'admin') throw new Error('Admin only.');
      const parsed = JSON.parse(e.target.result);
      const body = { data: parsed.data || parsed, users: Array.isArray(parsed.users) ? parsed.users : [] };
      const r = await apiPost('/api/restore', body);
      if (!r.ok) throw new Error((r.data && r.data.error) || 'Restore failed.');
      const v = await fetchView(true);
      if (v) DB._replace(v.data);
      if (onDone) onDone(true, r.data);
    } catch (err) {
      if (onDone) onDone(false, { error: err.message || String(err) });
    }
  };
  reader.readAsText(file);
}


// ---------- Archived attendance: merge archived days back into (copies of) the class list ----------
// Read-only helper for reports (never save the result). Marks archived dates in copy.__arch.
function mergeArchiveInto(classList) {
  let arch = [];
  try { arch = JSON.parse(DB.getItem('attendance_archive') || '[]'); } catch (e) {}
  if (!Array.isArray(arch) || !arch.length) return classList;
  return classList.map(c => {
    const es = arch.filter(a => a && a.classId === c.id);
    if (!es.length) return c;
    const copy = Object.assign({}, c, { attendance: Object.assign({}, c.attendance || {}), __arch: {} });
    es.forEach(en => Object.keys(en.records || {}).forEach(d => {
      if (!copy.attendance[d]) { copy.attendance[d] = en.records[d]; copy.__arch[d] = true; }
    }));
    return copy;
  });
}

// ---------- Show / hide password (eye button on every password box) ----------
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('input[type="password"]').forEach(inp => {
    if (inp.dataset.eye) return; inp.dataset.eye = '1';
    const wrap = document.createElement('span');
    wrap.style.cssText = 'position:relative;display:block;width:100%';
    inp.parentNode.insertBefore(wrap, inp); wrap.appendChild(inp);
    inp.style.paddingRight = '42px';
    const b = document.createElement('button');
    b.type = 'button'; b.textContent = '\u{1F441}'; b.title = 'Show / hide password';
    b.style.cssText = 'position:absolute;right:8px;top:50%;transform:translateY(-50%);border:none;background:none;cursor:pointer;font-size:18px;opacity:.6;padding:2px 4px;width:auto;margin:0';
    b.onclick = () => { const show = inp.type === 'password'; inp.type = show ? 'text' : 'password'; b.style.opacity = show ? '1' : '.6'; };
    wrap.appendChild(b);
  });
});
