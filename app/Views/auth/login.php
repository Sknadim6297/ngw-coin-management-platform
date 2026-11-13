<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<div class="auth-shell">
    <?= view('components/auth_visual', ['appName' => $appName, 'mode' => 'member']) ?>
    <div class="auth-panel">
        <div class="auth-card">
            <h2>Member Login</h2>
            <p class="subtitle">Sign in with your Member ID to access your dashboard.</p>
            <?= view('components/alerts') ?>
            <form method="post" action="<?= site_url('login') ?>" data-loading data-live-validate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="member_id">Member ID</label>
                    <input class="form-control" type="text" id="member_id" name="member_id" value="<?= esc(old('member_id')) ?>" required autocomplete="username"
                           data-validate="required|max:32" data-label="Member ID">
                    <div class="field-hint" data-validate-hint></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password"
                           data-validate="required" data-label="Password">
                    <div class="field-hint" data-validate-hint></div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="remember" name="remember">
                        <label class="form-check-label" for="remember">Remember Me</label>
                    </div>
                    <a href="<?= site_url('forgot-password') ?>">Forgot Password</a>
                </div>
                <button class="btn btn-gold w-100" type="submit">Sign In</button>
            </form>
            <div class="mt-4 small">
                Already new here? <a href="<?= site_url('register') ?>">Create account</a>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
