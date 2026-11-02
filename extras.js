/* extras.js — admin Settings (notify + archive), faculty notify, student report, low-attendance chips */
(function () {
const $ = id => document.getElementById(id);
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const IN = 'width:100%;padding:9px;border:1px solid #ccc;border-radius:6px;margin:6px 0;box-sizing:border-box';
const BTN = 'padding:10px 18px;border:none;border-radius:6px;background:#3b5cc8;color:#fff;font-weight:600;cursor:pointer';
const ROW = 'padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;margin:6px 0;cursor:pointer;background:#f9fafb';
const X = {};
const st = document.createElement('style');
st.textContent = '.xt{width:100%;border-collapse:collapse}.xt th,.xt td{padding:8px 10px;border-bottom:1px solid #eee;text-align:left}.xt th{background:#f3f4f6}';
document.head.appendChild(st);

const lbl = (n, d) => d ? n + ' – ' + d : n;
const all = () => loadAllClassesData();
const inGroup = (c, g) => norm(c.name) === norm(g.name) && (!g.division || norm(c.division) === norm(g.division));
const pctOf = (c, roll) => { let t = 0, p = 0;
  Object.values(c.attendance || {}).forEach(rs => { const r = (rs || []).find(x => x.student && String(x.student.roll) === String(roll)); if (r) { t++; if (r.present) p++; } });
  return t ? Math.round(p * 100 / t) : null; };
// unique class NAMES (+ their divisions) — never one entry per subject
function groups(list) {
  const m = new Map();
  list.forEach(c => { if (!m.has(c.name)) m.set(c.name, new Set()); if (c.division) m.get(c.name).add(c.division); });
  const out = [];
  [...m.keys()].sort().forEach(n => { const ds = [...m.get(n)].sort();
    out.push({ label: ds.length ? n + ' (all divisions)' : n, name: n, division: null });
    ds.forEach(d => out.push({ label: lbl(n, 'Division ' + d), name: n, division: d })); });
  return out;
}
function studentsOf(list, g) {
  const m = new Map();
  list.filter(c => !g || inGroup(c, g)).forEach(c => (c.students || []).forEach(s => { const k = norm(c.name) + '|' + norm(s.roll); if (!m.has(k)) m.set(k, { roll: s.roll, name: s.name, className: c.name }); }));
  return [...m.values()].sort((a, b) => (parseInt(a.roll) || 0) - (parseInt(b.roll) || 0) || String(a.roll).localeCompare(String(b.roll)));
}
function overlay(title, body) {
  let o = $('xOverlay');
  if (!o) { o = document.createElement('div'); o.id = 'xOverlay'; o.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px'; document.body.appendChild(o); }
  o.innerHTML = `<div style="background:#fff;border-radius:12px;width:min(860px,100%);max-height:90vh;display:flex;flex-direction:column"><div style="display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #eee"><h3 style="margin:0">${title}</h3><button onclick="xClose()" style="border:none;background:none;font-size:24px;cursor:pointer">×</button></div><div id="xTab" style="padding:20px;overflow:auto">${body}</div></div>`;
  o.style.display = 'flex';
}
window.xClose = () => { const o = $('xOverlay'); if (o) o.style.display = 'none'; };

// ---------- notifications ----------
function notifyPanel(role) {
  X.list = all(); X.gs = groups(X.list); X.role = role;
  const opts = X.gs.map((g, i) => `<option value="${i}">${esc(g.label)}</option>`).join('');
  return `<label>Send to</label><select id="xTo" style="${IN}" onchange="xTo()"><option value="all">${role === 'admin' ? 'All students' : 'All my students'}</option><option value="class">A particular class</option>${role === 'faculty' ? '<option value="student">A particular student</option>' : ''}</select>
  <div id="xClsBox" style="display:none"><label>Class</label><select id="xCls" size="${Math.min(Math.max(X.gs.length, 2), 6)}" style="${IN};height:auto" onchange="xCls()">${opts}</select></div>
  <div id="xStuBox" style="display:none"><label>Student</label><select id="xStu" size="6" style="${IN};height:auto"></select></div>
  <label>Message</label><textarea id="xMsg" rows="4" style="${IN}" placeholder="Message..."></textarea>
  <button onclick="xSend()" style="${BTN}">Send notification</button> <span id="xRes" style="margin-left:10px"></span>`;
}
window.xTo = () => { const v = $('xTo').value; $('xClsBox').style.display = v === 'all' ? 'none' : 'block'; $('xStuBox').style.display = v === 'student' ? 'block' : 'none'; xCls(); };
window.xCls = () => { const g = X.gs[$('xCls').value]; if ($('xTo').value !== 'student' || !g) return;
  $('xStu').innerHTML = studentsOf(X.list, g).map((s, i) => `<option value="${i}">${esc(s.roll)} – ${esc(s.name)}</option>`).join(''); };
window.xSend = () => {
  const msg = $('xMsg').value.trim(), to = $('xTo').value; if (!msg) { alert('Please type a message.'); return; }
  let t = [];
  if (to === 'all') t = studentsOf(X.list, null);
  else { const g = X.gs[$('xCls').value]; if (!g) { alert('Please select a class.'); return; }
    t = studentsOf(X.list, g);
    if (to === 'student') { const s = t[$('xStu').value]; if (!s) { alert('Please select a student.'); return; } t = [s]; } }
  if (!t.length) { alert('There are no students in this group.'); return; }
  const from = X.role === 'admin' ? 'Admin' : (getSessionUser().name || 'Faculty'), now = new Date().toISOString();
  const items = t.map(s => ({ id: 'n_' + Math.random().toString(36).slice(2, 9), read: false, date: now, roll: s.roll, studentName: s.name, className: s.className, type: 'message', from, message: '📢 ' + from + ': ' + msg }));
  DB.setItem('notifications', JSON.stringify(items.concat(getAllNotifications()).slice(0, 5000)));
  $('xRes').textContent = '✅ Sent to ' + t.length + ' student(s)'; $('xMsg').value = '';
};

// ---------- admin settings ----------
const byDate = (a, b) => String(dateKeyToISO(a)).localeCompare(String(dateKeyToISO(b)));
const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
const month = off => { const n = new Date(); return [ymd(new Date(n.getFullYear(), n.getMonth() + off, 1)), ymd(new Date(n.getFullYear(), n.getMonth() + off + 1, 0))]; };
window.xRange = off => { const [a, b] = month(off); $('aFrom').value = a; $('aTo').value = b; };
const card = h => '<div style="background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.08);max-width:920px">' + h + '</div>';
window.xSettingsPage = el => { el.innerHTML = card('<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">' + ['🔔 Send Notification', '🗄️ Archive Attendance', '📁 Archived Records'].map((t, i) => `<button onclick="xTabGo(${i})" style="${BTN}">${t}</button>`).join('') + '</div><div id="xPane"></div>'); xTabGo(0); };
window.xNotifyPage = el => { el.innerHTML = card('<div id="xPane"></div>'); $('xPane').innerHTML = notifyPanel('faculty'); };
window.xTabGo = i => {
  if (i === 0) $('xPane').innerHTML = notifyPanel('admin');
  if (i === 1) { X.gs = groups(all()); const [a, b] = month(0);
    $('xPane').innerHTML = `<label>Class</label><select id="aCls" size="${Math.min(X.gs.length + 1, 6)}" style="${IN};height:auto"><option value="-1" selected>All classes</option>${X.gs.map((g, k) => `<option value="${k}">${esc(g.label)}</option>`).join('')}</select>
      <div style="display:flex;gap:10px"><div style="flex:1"><label>From</label><input type="date" id="aFrom" value="${a}" style="${IN}"></div><div style="flex:1"><label>To</label><input type="date" id="aTo" value="${b}" style="${IN}"></div></div>
      <button onclick="xRange(0)" style="${BTN};background:#6b7280">This month</button> <button onclick="xRange(-1)" style="${BTN};background:#6b7280">Last month</button>
      <p style="color:#666;font-size:13px">Attendance in this date range moves out of the dashboards into Archived Records. Nothing is deleted.</p>
      <button onclick="xArchive()" style="${BTN};background:#e07b1f">Archive</button> <span id="xRes"></span>`; }
  if (i === 2) xAv(0);
};
window.xArchive = async () => {
  const from = $('aFrom').value, to = $('aTo').value; if (!from || !to || from > to) { alert('Invalid date range.'); return; }
  const g = X.gs[+$('aCls').value] || null;
  if (!confirm('Archive attendance ' + from + ' to ' + to + ' for ' + (g ? g.label : 'ALL classes') + '?\nNothing will be deleted.')) return;
  const arch = JSON.parse(DB.getItem('attendance_archive') || '[]'), edits = {}; let days = 0, n = 0;
  for (let i = 0; i < DB.length; i++) { const k = DB.key(i); if (!k || !(k === 'classes' || k.startsWith('classes_'))) continue;
    const list = JSON.parse(DB.getItem(k) || '[]'); let ch = false;
    list.forEach(c => { if (g && !inGroup(c, g)) return;
      const ds = Object.keys(c.attendance || {}).filter(d => { const i = dateKeyToISO(d); return i && i >= from && i <= to; }); if (!ds.length) return;
      let e = arch.find(x => x.classId === c.id);
      if (!e) { e = { classId: c.id, className: c.name, division: c.division || '', subject: c.subject, faculty: c.faculty, students: [], records: {} }; arch.push(e); }
      e.students = c.students || e.students;
      ds.forEach(d => { e.records[d] = c.attendance[d]; delete c.attendance[d]; days++; }); n++; ch = true; });
    if (ch) edits[k] = list; }
  if (!days) { $('xRes').textContent = 'No attendance found in this range.'; return; }
  if (!(await DB.setItem('attendance_archive', JSON.stringify(arch)))) return;     // archive saved FIRST
  for (const k in edits) await DB.setItem(k, JSON.stringify(edits[k]));
  $('xRes').textContent = '✅ Archived ' + days + ' day-record(s) from ' + n + ' class(es).';
  if (typeof refreshData === 'function') { refreshData(); renderHome(); }
};
// Archived records: class -> subject -> students -> date-wise
window.xAv = (lv, a, b) => {
  const arch = X.arch = JSON.parse(DB.getItem('attendance_archive') || '[]'); let h = '';
  const back = (l, p, q) => `<button onclick="xAv(${l},${p},${q})" style="${BTN};background:#6b7280;margin-bottom:10px">← Back</button>`;
  if (lv === 0) { X.keys = [...new Set(arch.map(e => lbl(e.className, e.division)))].sort();
    h = X.keys.length ? X.keys.map((k, i) => `<div style="${ROW}" onclick="xAv(1,${i})">📁 <b>${esc(k)}</b></div>`).join('') : '<p>No archived records yet.</p>'; }
  if (lv === 1) { X.k = a; h = back(0, 0, 0) + arch.map((e, i) => lbl(e.className, e.division) === X.keys[a] ? `<div style="${ROW}" onclick="xAv(2,${i})">📘 <b>${esc(e.subject)}</b> • ${esc(e.faculty)} • ${Object.keys(e.records).length} day(s)</div>` : '').join(''); }
  if (lv === 2) { const e = arch[a]; const rows = e.students.map((s, r) => { let t = 0, p = 0;
      Object.values(e.records).forEach(rs => { const x = rs.find(q => q.student && String(q.student.roll) === String(s.roll)); if (x) { t++; if (x.present) p++; } });
      return `<tr style="cursor:pointer" onclick="xAv(3,${a},${r})"><td>${esc(s.roll)}</td><td>${esc(s.name)}</td><td>${p}/${t}</td><td>${t ? Math.round(p * 100 / t) + '%' : '—'}</td></tr>`; }).join('');
    h = back(1, X.k, 0) + `<h4>${esc(e.className)} • ${esc(e.subject)}</h4><table class="xt"><tr><th>Roll</th><th>Name</th><th>Present</th><th>%</th></tr>${rows}</table>`; }
  if (lv === 3) { const e = arch[a], s = e.students[b];
    const rows = Object.keys(e.records).sort(byDate).map(d => { const x = e.records[d].find(q => q.student && String(q.student.roll) === String(s.roll)); return x ? `<tr><td>${d}</td><td style="color:${x.present ? '#16a34a' : '#dc2626'}">${x.present ? 'Present' : 'Absent'}</td></tr>` : ''; }).join('');
    h = back(2, a, 0) + `<h4>${esc(s.roll)} – ${esc(s.name)} (${esc(e.subject)})</h4><table class="xt"><tr><th>Date</th><th>Status</th></tr>${rows}</table>`; }
  $('xPane').innerHTML = h;
};

// ---------- admin: low attendance by class ----------
window.lowAlertChips = list => {
  const rows = []; list.forEach(c => (c.students || []).forEach(s => { const p = pctOf(c, s.roll); if (p !== null && p < 75) rows.push({ g: lbl(c.name, c.division), c, s, p }); }));
  if (!rows.length) return '<p style="text-align:center;color:#888;padding:10px;">No students below 75% attendance. 🎉</p>';
  X.low = rows; X.lowKeys = [...new Set(rows.map(r => r.g))].sort();
  return '<div style="display:flex;flex-wrap:wrap;gap:10px">' + X.lowKeys.map((g, i) => `<div onclick="xLow(${i})" style="cursor:pointer;padding:10px 16px;border-radius:8px;background:#fff5f5;border:1px solid #fecaca;font-weight:600">${esc(g)} <span style="background:#dc3545;color:#fff;border-radius:10px;padding:2px 8px;font-size:12px;margin-left:6px">${rows.filter(r => r.g === g).length}</span></div>`).join('') + '</div>';
};
window.xLow = i => { const g = X.lowKeys[i], rows = X.low.filter(r => r.g === g).sort((a, b) => a.p - b.p);
  overlay(esc(g) + ' — below 75%', `<table class="xt"><tr><th>Roll</th><th>Name</th><th>Subject</th><th>Attendance</th></tr>${rows.map(r => `<tr><td>${esc(r.s.roll)}</td><td>${esc(r.s.name)}</td><td>${esc(r.c.subject)}</td><td style="color:#dc2626;font-weight:700">${r.p}%</td></tr>`).join('')}</table>`); };

// ---------- faculty: one student's attendance ----------
window.xStuReport = (classId, roll) => {
  const c = all().find(x => x.id === classId); if (!c) return;
  const s = (c.students || []).find(x => String(x.roll) === String(roll)) || { roll, name: '' };
  let t = 0, p = 0;
  const rows = Object.keys(c.attendance || {}).sort(byDate).reverse().map(d => { const x = (c.attendance[d] || []).find(q => q.student && String(q.student.roll) === String(roll)); if (!x) return ''; t++; if (x.present) p++;
    return `<tr><td>${d}</td><td style="color:${x.present ? '#16a34a' : '#dc2626'}">${x.present ? 'Present' : 'Absent'}</td></tr>`; }).join('');
  overlay(esc(s.roll) + ' – ' + esc(s.name), `<p><b>${esc(c.name)}</b> • ${esc(c.subject)} — Present ${p}/${t} (<b>${t ? Math.round(p * 100 / t) : 0}%</b>)</p><table class="xt"><tr><th>Date</th><th>Status</th></tr>${rows || '<tr><td colspan="2">No attendance marked yet.</td></tr>'}</table>`);
};

// ---------- faculty: Student Attendance page (pick class -> pick student -> full report) ----------
function allArchive() { try { return JSON.parse(DB.getItem('attendance_archive') || '[]'); } catch (e) { return []; } }
// live days + archived days of one student in one class
function studentRows(c, roll) {
  const rows = [];
  const add = (d, recs, archived) => { const x = (recs || []).find(q => q.student && String(q.student.roll) === String(roll)); if (x) rows.push({ d, present: !!x.present, archived }); };
  Object.keys(c.attendance || {}).forEach(d => add(d, c.attendance[d], false));
  const e = allArchive().find(a => a.classId === c.id);
  if (e) Object.keys(e.records || {}).forEach(d => add(d, e.records[d], true));
  return rows.sort((a, b) => byDate(b.d, a.d));
}
window.xStudentPage = el => {
  X.sl = all().filter(c => (c.students || []).length);
  const opts = X.sl.map((c, i) => `<option value="${i}">${esc(c.name)}${c.division ? ' – ' + esc(c.division) : ''} • ${esc(c.subject || '')}</option>`).join('');
  el.innerHTML = card(`<h3 style="margin-top:0">Student Attendance</h3>
    ${X.sl.length ? `<div style="display:flex;gap:10px;flex-wrap:wrap">
      <div style="flex:1;min-width:220px"><label>Class</label><select id="sClass" style="${IN}" onchange="xSCls()">${opts}</select></div>
      <div style="flex:1;min-width:220px"><label>Search student (roll or name)</label><input id="sFind" style="${IN}" placeholder="Type to filter..." oninput="xSCls()"></div></div>
      <label>Student</label><select id="sStu" style="${IN}" onchange="xSShow()"></select>
      <div style="display:flex;gap:10px;flex-wrap:wrap"><div style="flex:1;min-width:150px"><label>Particular date (optional)</label><input type="date" id="sDay" style="${IN}" onchange="xSShow()"></div>
      <div style="flex:1;min-width:150px"><label>From (optional)</label><input type="date" id="sFrom" style="${IN}" onchange="xSShow()"></div>
      <div style="flex:1;min-width:150px"><label>To (optional)</label><input type="date" id="sTo" style="${IN}" onchange="xSShow()"></div></div>
      <div id="sOut" style="margin-top:14px"></div>` : '<p>No classes with students yet. Add students in Manage Classes first.</p>'}`);
  if (X.sl.length) xSCls();
};
window.xSCls = () => {
  const c = X.sl[$('sClass').value]; if (!c) return;
  const q = norm($('sFind').value);
  X.ss = (c.students || []).filter(s => !q || norm(s.roll).includes(q) || norm(s.name).includes(q))
    .sort((a, b) => (parseInt(a.roll) || 0) - (parseInt(b.roll) || 0) || String(a.roll).localeCompare(String(b.roll)));
  $('sStu').innerHTML = X.ss.map((s, i) => `<option value="${i}">${esc(s.roll)} – ${esc(s.name)}</option>`).join('') || '<option value="">No match</option>';
  xSShow();
};
window.xSShow = () => {
  const c = X.sl[$('sClass').value], s = X.ss && X.ss[$('sStu').value], out = $('sOut');
  if (!c || !s) { out.innerHTML = '<p style="color:#888">No student selected.</p>'; return; }
  const day = $('sDay').value;   // a particular date wins over From / To
  const from = day || $('sFrom').value, to = day || $('sTo').value;
  const rows = studentRows(c, s.roll).filter(r => { const i = dateKeyToISO(r.d); return !i || ((!from || i >= from) && (!to || i <= to)); });
  const t = rows.length, p = rows.filter(r => r.present).length, pct = t ? Math.round(p * 100 / t) : 0;
  const months = {};
  rows.forEach(r => { const i = dateKeyToISO(r.d); const m = i ? i.slice(0, 7) : 'Unknown'; (months[m] = months[m] || { t: 0, p: 0 }).t++; if (r.present) months[m].p++; });
  const box = (n, l, col) => `<div style="flex:1;min-width:110px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:12px;text-align:center"><div style="font-size:22px;font-weight:700;color:${col}">${n}</div><div style="font-size:12px;color:#666">${l}</div></div>`;
  out.innerHTML = `<h4 style="margin:0 0 10px">${esc(s.roll)} – ${esc(s.name)} <span style="font-weight:400;color:#666">(${esc(c.name)} • ${esc(c.subject || '')})</span></h4>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">${box(t, 'Total lectures', '#374151')}${box(p, 'Present', '#16a34a')}${box(t - p, 'Absent', '#dc2626')}${box(pct + '%', 'Attendance', pct < 75 ? '#dc2626' : '#16a34a')}</div>
    ${t ? `<h4>Month-wise</h4><table class="xt"><tr><th>Month</th><th>Present</th><th>Total</th><th>%</th></tr>${Object.keys(months).sort().reverse().map(m => `<tr><td>${m}</td><td>${months[m].p}</td><td>${months[m].t}</td><td>${Math.round(months[m].p * 100 / months[m].t)}%</td></tr>`).join('')}</table>
    <h4>Date-wise</h4><table class="xt"><tr><th>Date</th><th>Status</th></tr>${rows.map(r => `<tr><td>${esc(r.d)}${r.archived ? ' <span style="color:#9ca3af;font-size:12px">(archived)</span>' : ''}</td><td style="color:${r.present ? '#16a34a' : '#dc2626'};font-weight:600">${r.present ? 'Present' : 'Absent'}</td></tr>`).join('')}</table>` : '<p style="color:#888">No attendance marked for this student in the selected range.</p>'}`;
};

// ---------- faculty: Archive page (same tools the admin has, limited to this faculty's classes) ----------
window.xArchivePage = el => {
  el.innerHTML = card('<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">' + ['🗄️ Archive Attendance', '📁 Archived Records'].map((t, i) => `<button onclick="xArcGo(${i})" style="${BTN}">${t}</button>`).join('') + '</div><div id="xPane"></div>');
  xArcGo(0);
};
window.xArcGo = i => xTabGo(i + 1);
})();
