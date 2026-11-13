<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<div class="auth-shell">
    <?= view('components/auth_visual', ['appName' => $appName, 'mode' => 'forgot']) ?>
    <div class="auth-panel">
        <div class="auth-card">
            <h2>Forgot Password</h2>
            <p class="subtitle">Enter your email and we will send a reset link if an account exists.</p>
            <?= view('components/alerts') ?>
            <form method="post" action="<?= site_url('forgot-password') ?>" data-loading data-live-validate>
                <?= csrf_field() ?>
                <div class="mb-4">
                    <label class="form-label" for="email">Email Address</label>
                    <input class="form-control" type="email" id="email" name="email" value="<?= esc(old('email')) ?>" required
                           data-validate="required|email" data-label="Email Address">
                    <div class="field-hint" data-validate-hint></div>
                </div>
                <button class="btn btn-gold w-100" type="submit">Send Reset Link</button>
            </form>
            <div class="mt-4 small">
                <a href="<?= site_url('login') ?>">Back to login</a>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
