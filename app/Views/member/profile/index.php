<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card-premium mb-3">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h2 class="h5 mb-1"><?= esc($profile->member_name) ?></h2>
                    <div class="text-secondary"><?= esc($profile->member_id) ?></div>
                </div>
                <span class="badge-status <?= esc($profile->account_status) ?>"><?= esc(ucfirst($profile->account_status)) ?></span>
            </div>
            <div class="row g-3">
                <div class="col-12">
                    <div class="text-secondary mb-1">Member ID</div>
                    <strong><?= esc($profile->member_id) ?></strong>
                </div>
                <div class="col-12">
                    <div class="text-secondary mb-1">Sponsor ID</div>
                    <strong><?= esc($relationship['sponsor_member_id'] ?? '—') ?></strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Registered</div>
                    <strong><?= esc($profile->created_at) ?></strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Activation Date</div>
                    <strong><?= esc($profile->activated_at ?? '—') ?></strong>
                </div>
                <div class="col-12">
                    <div class="text-secondary mb-1">Login Email</div>
                    <strong><?= esc($email ?? '—') ?></strong>
                </div>
                <?php if (! empty($profile->pending_email)): ?>
                <div class="col-12">
                    <div class="alert alert-warning mb-0 py-2">
                        Pending email: <strong><?= esc($profile->pending_email) ?></strong>
                        <form method="post" action="<?= site_url('member/profile/cancel-email-change') ?>" class="d-inline ms-2">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-gold">Cancel</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
                <div class="col-12">
                    <div class="text-secondary mb-1">Profile Completion</div>
                    <strong><?= esc((string) $completion) ?>%</strong>
                </div>
                <?php if (! empty($walletCooldownUntil)): ?>
                <div class="col-12">
                    <div class="text-secondary mb-1">Withdrawal destination cooldown</div>
                    <strong><?= esc($walletCooldownUntil) ?></strong>
                    <div class="field-hint">Applies when withdrawals are activated. Withdrawals are not available now.</div>
                </div>
                <?php endif; ?>
            </div>
            <div class="mt-3">
                <a class="btn btn-outline-gold btn-sm" href="<?= site_url('member/security') ?>">Change Password</a>
            </div>
        </div>

        <?php if (! empty($walletEvents)): ?>
        <div class="card-premium">
            <h2 class="h6 mb-2">Wallet Change History</h2>
            <div class="table-responsive">
                <table class="table table-dark-premium table-sm mb-0">
                    <thead><tr><th>When</th><th>New Address</th></tr></thead>
                    <tbody>
                    <?php foreach ($walletEvents as $ev): ?>
                        <tr>
                            <td><?= esc($ev['created_at'] ?? '') ?></td>
                            <td><code><?= esc(substr((string) ($ev['new_wallet'] ?? ''), 0, 10)) ?>…</code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-7">
        <div class="card-premium">
            <h2 class="h5 mb-3">Edit Profile</h2>
            <form method="post" action="<?= site_url('member/profile') ?>" data-loading data-live-validate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="member_name">Full Name</label>
                    <input class="form-control" type="text" id="member_name" name="member_name" required maxlength="120"
                           value="<?= esc(old('member_name', $profile->member_name)) ?>"
                           data-validate="required|min:2|max:120" data-label="Full Name">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="mobile">Mobile Number</label>
                    <input class="form-control" type="text" id="mobile" name="mobile" required maxlength="20"
                           value="<?= esc(old('mobile', $profile->mobile)) ?>"
                           data-validate="required|min:8|max:20" data-label="Mobile Number">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="email">Email Address</label>
                    <input class="form-control" type="email" id="email" name="email" required maxlength="255"
                           value="<?= esc(old('email', $email ?? '')) ?>"
                           data-validate="required|email" data-label="Email Address">
                    <div class="field-hint">A new email requires verification before your login identity changes.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="usdt_wallet">USDT Wallet Address (BEP20)</label>
                    <input class="form-control" type="text" id="usdt_wallet" name="usdt_wallet" required maxlength="255"
                           value="<?= esc(old('usdt_wallet', $profile->usdt_wallet)) ?>"
                           pattern="^0x[a-fA-F0-9]{40}$">
                    <div class="field-hint">Must be a BEP20 address. Changing destination starts a withdrawal cooldown when withdrawals are enabled.</div>
                </div>
                <div class="mb-3 form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="confirm_wallet_change" name="confirm_wallet_change"
                        <?= old('confirm_wallet_change') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="confirm_wallet_change">
                        I confirm changing my USDT wallet destination (required only when the address changes)
                    </label>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="current_password">Current Password</label>
                    <input class="form-control" type="password" id="current_password" name="current_password" required
                           autocomplete="current-password"
                           data-validate="required" data-label="Current Password">
                    <div class="field-hint">Required to confirm any profile change.</div>
                </div>
                <button class="btn btn-gold" type="submit">Save Profile</button>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
