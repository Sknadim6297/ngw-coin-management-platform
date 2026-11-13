<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<div class="auth-shell">
    <?= view('components/auth_visual', ['appName' => $appName, 'mode' => 'forgot']) ?>
    <div class="auth-panel">
        <div class="auth-card">
            <h2>Reset Password</h2>
            <p class="subtitle">Choose a new password for your account.</p>
            <?= view('components/alerts') ?>
            <form method="post" action="<?= site_url('reset-password/' . $token) ?>" data-loading data-live-validate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="password">New Password</label>
                    <input class="form-control" type="password" id="password" name="password" required autocomplete="new-password"
                           data-validate="required|min:8|max:72" data-label="Password">
                    <div class="field-hint" data-validate-hint></div>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="password_confirm">Confirm Password</label>
                    <input class="form-control" type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password"
                           data-validate="required|match:#password" data-label="Confirm Password">
                    <div class="field-hint" data-validate-hint></div>
                </div>
                <button class="btn btn-gold w-100" type="submit">Update Password</button>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
