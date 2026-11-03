<?php
declare(strict_types=1);
// Lightweight, fast, fully responsive sign-in page.
// Automatically identifies the user's role (Student, Admin, Officer, Tax Consultant)
// from their credentials without requiring any manual role selection.

$u = current_user();
if ($u && isset(PORTALS[$u['role']])) {
    redirect(PORTALS[$u['role']]['home']);
}

$error = null;

if (is_post()) {
    csrf_check();
    $username = post('username');
    $password = $_POST['password'] ?? '';
    $error = attempt_login(null, $username, $password);
    if ($error === null) {
        $u = current_user();
        if ($u && isset(PORTALS[$u['role']])) {
            redirect(PORTALS[$u['role']]['home']);
        }
        redirect('index.php');
    }
}

page_start('Sign in');
?>
<style>
/* Clean, lightweight, ultra-responsive login */
.sh-login-container {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: calc(100vh - 140px);
  padding: 24px 16px;
}

.sh-login-card {
  width: 100%;
  max-width: 440px;
  background: var(--surface, #ffffff);
  border: 1px solid var(--line, #d8e1ea);
  border-radius: 16px;
  padding: 36px 32px 28px;
  box-shadow: 0 4px 20px rgba(18, 48, 75, 0.08);
}

.sh-card-header {
  text-align: center;
  margin-bottom: 24px;
}

.sh-brand-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 58px;
  height: 58px;
  border-radius: 14px;
  background: var(--accent-soft, #e2f0f9);
  border: 1px solid rgba(15, 93, 158, 0.2);
  margin-bottom: 12px;
}

.sh-brand-badge svg {
  width: 32px;
  height: 36px;
}

.sh-card-title {
  font-family: var(--f-serif);
  font-size: 22px;
  font-weight: 700;
  color: var(--head, #12304B);
  margin: 0;
  line-height: 1.25;
}

.sh-card-title i {
  font-weight: 400;
  font-style: italic;
  color: var(--brand-2, #1392cb);
}

.sh-welcome-text {
  font-size: 13.5px;
  color: var(--muted, #56687a);
  margin: 6px 0 0;
  font-weight: 500;
}

.sh-error-banner {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--bad-soft, #fce4e1);
  border: 1px solid rgba(180, 35, 24, 0.3);
  color: var(--bad, #b42318);
  border-radius: 10px;
  padding: 10px 14px;
  font-size: 13.5px;
  margin-bottom: 20px;
  font-weight: 500;
}

.sh-form {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.sh-input-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.sh-label {
  font-size: 13px;
  font-weight: 600;
  color: var(--fg, #1e2a36);
}

.sh-input-wrap {
  position: relative;
  display: flex;
  align-items: center;
}

.sh-input-wrap svg.icon-lead {
  position: absolute;
  left: 14px;
  width: 18px;
  height: 18px;
  color: var(--brand, #0f5d9e);
  opacity: 0.75;
  pointer-events: none;
}

.sh-input {
  width: 100%;
  height: 46px;
  background: #ffffff;
  border: 1.5px solid var(--line, #d8e1ea);
  border-radius: 10px;
  padding: 0 42px;
  color: var(--fg, #1e2a36);
  font-size: 14.5px;
  font-family: inherit;
  outline: none;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;
  box-sizing: border-box;
}

.sh-input:focus {
  border-color: var(--brand, #0f5d9e);
  box-shadow: 0 0 0 3px rgba(15, 93, 158, 0.15);
}

.sh-eye-btn {
  position: absolute;
  right: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  background: transparent;
  border: none;
  border-radius: 6px;
  color: var(--muted, #56687a);
  cursor: pointer;
  padding: 0;
  transition: color 0.15s ease, background 0.15s ease;
}

.sh-eye-btn:hover {
  color: var(--brand, #0f5d9e);
  background: var(--sunk, #e8edf2);
}

.sh-eye-btn svg {
  width: 18px;
  height: 18px;
}

.sh-submit-btn {
  width: 100%;
  height: 48px;
  border: none;
  border-radius: 10px;
  background: var(--brand, #0f5d9e);
  color: #ffffff;
  font-size: 15px;
  font-weight: 700;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: background-color 0.15s ease, transform 0.1s ease;
  margin-top: 4px;
}

.sh-submit-btn:hover {
  background: var(--brand-2, #1392cb);
}

.sh-submit-btn:active {
  transform: translateY(1px);
}

.sh-submit-btn svg {
  width: 18px;
  height: 18px;
}

.sh-card-footer {
  margin-top: 22px;
  padding-top: 16px;
  border-top: 1px solid var(--line, #d8e1ea);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
  text-align: center;
}

.sh-ssl-tag {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--muted, #56687a);
  font-weight: 500;
}

.sh-ssl-tag svg {
  width: 14px;
  height: 14px;
  color: var(--ok, #17703a);
}

.sh-country-pills {
  font-size: 11.5px;
  color: var(--muted, #56687a);
}

@media (max-width: 480px) {
  .sh-login-card {
    padding: 26px 18px 20px;
    border-radius: 14px;
  }
  .sh-card-title {
    font-size: 20px;
  }
}
</style>

<div class="sh-login-container">
  <div class="sh-login-card">
    <div class="sh-card-header">
      <div class="sh-brand-badge">
        <?= brand_mark() ?>
      </div>
      <h1 class="sh-card-title">ScholarshipHive <i>Edutancy</i></h1>
      <p class="sh-welcome-text">Sign in to your PMS account</p>
    </div>

    <?php if ($error): ?>
      <div class="sh-error-banner" role="alert">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        <div><?= h($error) ?></div>
      </div>
    <?php endif; ?>

    <form method="post" class="sh-form" autocomplete="on">
      <?= csrf_field() ?>

      <div class="sh-input-group">
        <label class="sh-label" for="username">Username</label>
        <div class="sh-input-wrap">
          <svg class="icon-lead" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <input class="sh-input" type="text" name="username" id="username" required autocomplete="username" placeholder="Enter your username" value="<?= h(post('username')) ?>" autofocus>
        </div>
      </div>

      <div class="sh-input-group">
        <label class="sh-label" for="password">Password</label>
        <div class="sh-input-wrap">
          <svg class="icon-lead" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <input class="sh-input" type="password" name="password" id="password" required autocomplete="current-password" placeholder="Enter your password">
          <button type="button" class="sh-eye-btn" id="shEyeBtn" aria-label="Toggle password visibility" title="Show/Hide password">
            <svg id="shEyeShow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg id="shEyeHide" style="display:none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" x2="22" y1="2" y2="22"/></svg>
          </button>
        </div>
      </div>

      <button type="submit" class="sh-submit-btn" id="shSubmitBtn">
        <span>Sign In to Portal</span>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
      </button>
    </form>

    <div class="sh-card-footer">
      <div class="sh-ssl-tag">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
        <span>256-bit SSL Secure Session · ScholarshipHive ERP</span>
      </div>
      <div class="sh-country-pills">
        Italy · Germany · France · Czech Republic · Austria
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const passInput = document.getElementById('password');
  const eyeBtn = document.getElementById('shEyeBtn');
  const eyeShow = document.getElementById('shEyeShow');
  const eyeHide = document.getElementById('shEyeHide');

  if (eyeBtn && passInput) {
    eyeBtn.addEventListener('click', function () {
      const isPass = passInput.type === 'password';
      passInput.type = isPass ? 'text' : 'password';
      eyeShow.style.display = isPass ? 'none' : 'block';
      eyeHide.style.display = isPass ? 'block' : 'none';
      passInput.focus();
    });
  }
});
</script>
<?php
page_end();
