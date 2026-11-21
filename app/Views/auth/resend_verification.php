<?= $this->extend('layouts/auth') ?>
<?= $this->section('content') ?>
<div class="auth-shell">
    <?= view('components/auth_visual', ['appName' => $appName, 'mode' => 'member']) ?>
    <div class="auth-panel">
        <div class="auth-card">
            <h2>Resend Email Verification</h2>
            <p class="subtitle">Confirm your Member ID and password to request a new verification email.</p>
            <?= view('components/alerts') ?>
            <form method="post" action="<?= site_url('resend-verification') ?>">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="member_id">Member ID</label>
                    <input class="form-control" type="text" id="member_id" name="member_id"
                           value="<?= esc(old('member_id') ?? $memberId ?? '') ?>" required autocomplete="username">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">
                </div>
                <button class="btn btn-gold w-100" type="submit">Send Verification Email</button>
            </form>
            <div class="mt-4 small"><a href="<?= site_url('login') ?>">Back to sign in</a></div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
