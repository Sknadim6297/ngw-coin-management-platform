<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Uses the same validation rules as public registration. Created members start inactive.</p>

<div class="card-premium" style="max-width: 720px;">
    <form method="post" action="<?= site_url('admin/members') ?>" data-loading data-live-validate>
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="member_id">Member ID</label>
            <input class="form-control" type="text" id="member_id" value="<?= esc($previewMemberId) ?>" readonly>
        </div>
        <div class="mb-3">
            <label class="form-label" for="member_name">Member Name</label>
            <input class="form-control" type="text" id="member_name" name="member_name" value="<?= esc(old('member_name')) ?>" required
                   data-validate="required|min:2|max:120" data-label="Member Name">
            <div class="field-hint" data-validate-hint></div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="sponsor_id">Sponsor ID</label>
            <input class="form-control" type="text" id="sponsor_id" name="sponsor_id"
                   value="<?= esc(old('sponsor_id')) ?>" required
                   data-sponsor-id
                   data-lookup-url="<?= site_url('api/sponsor-lookup') ?>"
                   data-validate="required|max:32" data-label="Sponsor ID">
            <div class="field-hint" data-sponsor-hint>Enter a Sponsor ID to validate.</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="sponsor_name">Sponsor Name</label>
            <input class="form-control" type="text" id="sponsor_name" data-sponsor-name readonly>
        </div>
        <div class="mb-3">
            <label class="form-label" for="mobile">Mobile Number</label>
            <input class="form-control" type="text" id="mobile" name="mobile" value="<?= esc(old('mobile')) ?>" required
                   data-validate="required|mobile" data-label="Mobile Number">
            <div class="field-hint" data-validate-hint></div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="email">Email Address</label>
            <input class="form-control" type="email" id="email" name="email" value="<?= esc(old('email')) ?>" required
                   data-validate="required|email" data-label="Email Address">
            <div class="field-hint" data-validate-hint></div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="usdt_wallet">USDT Wallet Address</label>
            <input class="form-control" type="text" id="usdt_wallet" name="usdt_wallet" value="<?= esc(old('usdt_wallet')) ?>" required
                   data-validate="required|max:255" data-label="USDT Wallet Address"
                   placeholder="Enter any wallet address">
            <div class="field-hint" data-validate-hint>Any wallet address format is accepted.</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="password">Password</label>
            <input class="form-control" type="password" id="password" name="password" required
                   data-validate="required|min:8|max:72" data-label="Password">
            <div class="field-hint" data-validate-hint></div>
        </div>
        <div class="mb-4">
            <label class="form-label" for="password_confirm">Confirm Password</label>
            <input class="form-control" type="password" id="password_confirm" name="password_confirm" required
                   data-validate="required|match:#password" data-label="Confirm Password">
            <div class="field-hint" data-validate-hint></div>
        </div>
        <button class="btn btn-gold" type="submit">Create Inactive Member</button>
    </form>
</div>
<?= $this->endSection() ?>
