<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<div class="auth-shell">
    <?= view('components/auth_visual', ['appName' => $appName, 'mode' => 'admin']) ?>
    <div class="auth-panel">
        <div class="auth-card">
            <h2>Admin Login</h2>
            <p class="subtitle">Administrator access only. Members should use the member login page.</p>
            <?= view('components/alerts') ?>
            <form method="post" action="<?= site_url('admin/login') ?>" data-loading data-live-validate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input class="form-control" type="email" id="email" name="email" value="<?= esc(old('email')) ?>" required autocomplete="username"
                           data-validate="required|email" data-label="Email">
                    <div class="field-hint" data-validate-hint></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password"
                           data-validate="required" data-label="Password">
                    <div class="field-hint" data-validate-hint></div>
                </div>
                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" value="1" id="remember" name="remember">
                    <label class="form-check-label" for="remember">Remember Me</label>
                </div>
                <button class="btn btn-gold w-100" type="submit">Enter Admin Panel</button>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
