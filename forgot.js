/* forgot.js - "Forgot password?" for faculty and student login pages.
   Usage: <script src="forgot.js" data-role="faculty"></script>  (after common.js; the page needs <form id="loginForm">) */
(function () {
  const role = (document.currentScript && document.currentScript.dataset.role) || 'faculty';
  const isStudent = role === 'student';
  const loginForm = document.getElementById('loginForm');
  if (!loginForm) return;
  const color = isStudent ? '#2563eb' : '#1fa67a';

  const box = document.createElement('div');
  box.style.display = 'none';
  box.innerHTML =
    '<div id="fpMsg" style="font-size:13px;padding:9px 12px;border-radius:6px;margin:10px 0;display:none;white-space:pre-wrap"></div>' +
    '<form id="fpForm1"><h3 style="margin-top:0">Password reset</h3>' +
      (isStudent ? '<div class="grp"><label>Roll Number</label><input type="text" id="fpRoll" required></div>' : '') +
      '<div class="grp"><label>' + (isStudent ? 'Email saved in your account' : 'Email') + '</label><input type="email" id="fpEmail" required></div>' +
      '<button type="submit" class="btn">Send verification code</button></form>' +
    '<form id="fpForm2" style="display:none"><h3 style="margin-top:0">Verification code</h3>' +
      '<div class="grp"><label>6-digit code sent to your email</label><input type="text" id="fpCode" inputmode="numeric" maxlength="6" required></div>' +
      '<button type="submit" class="btn">Verify</button>' +
      '<p class="muted"><a href="#" id="fpResend">Resend code</a></p></form>' +
    '<form id="fpForm3" style="display:none"><h3 style="margin-top:0">Set a new password</h3>' +
      '<div class="grp"><label>New password (minimum 8 characters)</label><input type="password" id="fpNew" minlength="8" required></div>' +
      '<div class="grp"><label>Confirm password</label><input type="password" id="fpNew2" minlength="8" required></div>' +
      '<button type="submit" class="btn">Change password</button></form>' +
    '<p class="muted"><a href="#" id="fpBack">← Back to login</a></p>';
  loginForm.parentNode.insertBefore(box, loginForm.nextSibling);

  const link = document.createElement('p');
  link.className = 'muted'; link.style.textAlign = 'right';
  link.innerHTML = '<a href="#" id="fpShow">Forgot password?</a>';
  loginForm.appendChild(link);

  const $ = id => document.getElementById(id);
  const say = (t, type) => {
    const m = $('fpMsg'); m.textContent = t || ''; m.style.display = t ? 'block' : 'none';
    const c = { error: ['#fff0f0', '#9b1c1c'], success: ['#edfff4', '#126b3a'], info: ['#e8f0fe', '#1a3c8f'] }[type || 'error'];
    m.style.background = c[0]; m.style.color = c[1];
  };
  const step = n => [1, 2, 3].forEach(i => $('fpForm' + i).style.display = i === n ? 'block' : 'none');
  const ids = () => ({ role, email: $('fpEmail').value.trim().toLowerCase(), roll: isStudent ? $('fpRoll').value.trim() : '' });

  $('fpShow').onclick = e => {
    e.preventDefault(); say('');
    const le = $('email'); if (le && $('fpEmail')) $('fpEmail').value = le.value;
    if (isStudent && $('roll')) $('fpRoll').value = $('roll').value;
    loginForm.style.display = 'none'; box.style.display = 'block'; step(1);
  };
  $('fpBack').onclick = e => { e.preventDefault(); say(''); box.style.display = 'none'; loginForm.style.display = 'block'; };

  async function send() {
    const r = await apiPost('/api/forgot', ids());
    if (!r.ok) { say(r.data.error || 'Could not send the code.'); return false; }
    let t = r.data.message || 'Code sent.';
    if (r.data.dev_code) t += '  [TEST MODE - code: ' + r.data.dev_code + ']';
    say(t, 'info'); step(2); return true;
  }
  $('fpForm1').onsubmit = async e => {
    e.preventDefault(); const b = e.target.querySelector('button'); b.disabled = true;
    try { await send(); } finally { b.disabled = false; }
  };
  $('fpResend').onclick = async e => { e.preventDefault(); await send(); };
  $('fpForm2').onsubmit = async e => {
    e.preventDefault(); const b = e.target.querySelector('button'); b.disabled = true;
    try {
      const r = await apiPost('/api/forgot_verify', Object.assign(ids(), { code: $('fpCode').value.trim() }));
      if (r.ok) { say(r.data.message, 'success'); step(3); } else say(r.data.error || 'Verification failed.');
    } finally { b.disabled = false; }
  };
  $('fpForm3').onsubmit = async e => {
    e.preventDefault();
    if ($('fpNew').value !== $('fpNew2').value) { say('The two passwords do not match.'); return; }
    const b = e.target.querySelector('button'); b.disabled = true;
    try {
      const r = await apiPost('/api/forgot_reset', { new_password: $('fpNew').value });
      if (r.ok) {
        const d = ids();
        box.style.display = 'none'; loginForm.style.display = 'block';
        if (isStudent) { if ($('roll')) $('roll').value = d.roll; } else if ($('email')) $('email').value = d.email;
        if ($('password')) { $('password').value = ''; $('password').focus(); }
        $('fpForm1').reset(); $('fpForm2').reset(); $('fpForm3').reset();
        alert(r.data.message || 'Password changed.');
      } else say(r.data.error || 'Could not change the password.');
    } finally { b.disabled = false; }
  };
})();
