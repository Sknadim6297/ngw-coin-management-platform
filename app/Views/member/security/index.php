<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<div class="card-premium" style="max-width: 560px;">
    <h2 class="h5 mb-3">Change Password</h2>
    <form method="post" action="<?= site_url('member/security/password') ?>" data-loading data-live-validate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="current_password">Current Password</label>
            <input class="form-control" type="password" id="current_password" name="current_password" required
                   data-validate="required" data-label="Current Password">
            <div class="field-hint" data-validate-hint></div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">New Password</label>
            <input class="form-control" type="password" id="password" name="password" required
                   data-validate="required|min:8|max:72" data-label="New Password">
            <div class="field-hint" data-validate-hint></div>
        </div>
        <div class="mb-4">
            <label class="form-label" for="password_confirm">Confirm Password</label>
            <input class="form-control" type="password" id="password_confirm" name="password_confirm" required
                   data-validate="required|match:#password" data-label="Confirm Password">
            <div class="field-hint" data-validate-hint></div>
        </div>
        <button class="btn btn-gold" type="submit">Update Password</button>
    </form>
</div>
<?= $this->endSection() ?>
