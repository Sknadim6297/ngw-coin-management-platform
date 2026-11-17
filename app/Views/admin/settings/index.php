<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?php
$tab = $tab ?? 'general';
$settings = $settings ?? [];
?>

<?= view('components/admin_tabs', ['active' => $tab, 'tabs' => $tabs]) ?>

<?php if ($tab === 'general'): ?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-premium">
            <h2 class="h5 mb-3">General</h2>
            <form method="post" action="<?= site_url('admin/settings') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="group" value="general">
                <div class="mb-3">
                    <label class="form-label" for="platform_name">Platform Name</label>
                    <input class="form-control" id="platform_name" name="platform_name" required maxlength="120"
                           value="<?= esc(old('platform_name', $settings['platform_name'] ?? '')) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="contact_email">Contact Email</label>
                    <input class="form-control" type="email" id="contact_email" name="contact_email" maxlength="255"
                           value="<?= esc(old('contact_email', $settings['contact_email'] ?? '')) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="support_contact">Support Contact</label>
                    <input class="form-control" id="support_contact" name="support_contact" maxlength="255"
                           value="<?= esc(old('support_contact', $settings['support_contact'] ?? '')) ?>"
                           placeholder="Email or phone">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="timezone">Timezone</label>
                    <select class="form-select" id="timezone" name="timezone" required>
                        <?php foreach ($timezones as $tz): ?>
                            <option value="<?= esc($tz) ?>" <?= ($settings['timezone'] ?? '') === $tz ? 'selected' : '' ?>><?= esc($tz) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="date_format">Date Format</label>
                    <select class="form-select" id="date_format" name="date_format" required>
                        <?php foreach ($dateFormats as $fmt): ?>
                            <option value="<?= esc($fmt) ?>" <?= ($settings['date_format'] ?? '') === $fmt ? 'selected' : '' ?>><?= esc($fmt) ?> · <?= esc(date($fmt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-gold" type="submit">Save General</button>
            </form>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card-premium">
            <h2 class="h5 mb-3">Platform Logo</h2>
            <?php if (! empty($settings['platform_logo'])): ?>
                <div class="mb-3">
                    <img src="<?= site_url('media/platform-logo') ?>" alt="Platform logo" style="max-height: 72px; max-width: 100%;">
                </div>
            <?php else: ?>
                <div class="text-secondary mb-3">No logo uploaded.</div>
            <?php endif; ?>
            <form method="post" action="<?= site_url('admin/settings/logo') ?>" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="platform_logo">Upload Logo</label>
                    <input class="form-control" type="file" id="platform_logo" name="platform_logo" accept="image/png,image/jpeg,image/webp,image/gif" required>
                </div>
                <button class="btn btn-outline-gold" type="submit">Upload</button>
            </form>
        </div>
    </div>
</div>

<?php elseif ($tab === 'registration'): ?>
<div class="card-premium">
    <h2 class="h5 mb-3">Member Registration</h2>
    <form method="post" action="<?= site_url('admin/settings') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="group" value="registration">
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="registration_enabled" name="registration_enabled" value="1"
                <?= ! empty($settings['registration_enabled']) && $settings['registration_enabled'] === '1' ? 'checked' : '' ?>>
            <label class="form-check-label" for="registration_enabled">Registration Enabled</label>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="sponsor_required" name="sponsor_required" value="1"
                <?= ! empty($settings['sponsor_required']) && $settings['sponsor_required'] === '1' ? 'checked' : '' ?>>
            <label class="form-check-label" for="sponsor_required">Sponsor Requirement</label>
        </div>
        <div class="mb-3">
            <label class="form-label" for="registration_instructions">Registration Instructions</label>
            <textarea class="form-control" id="registration_instructions" name="registration_instructions" rows="4" maxlength="2000"><?= esc(old('registration_instructions', $settings['registration_instructions'] ?? '')) ?></textarea>
        </div>
        <div class="text-secondary small mb-3">Activation rules are unchanged. New members still require a qualifying purchase to activate.</div>
        <button class="btn btn-gold" type="submit">Save Registration</button>
    </form>
</div>

<?php elseif ($tab === 'notifications'): ?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-premium">
            <h2 class="h5 mb-3">Notification Preferences</h2>
            <form method="post" action="<?= site_url('admin/settings') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="group" value="notifications">
                <?php
                $checks = [
                    'notify_admin_alerts'       => 'Admin alerts',
                    'notify_member_events'      => 'Member notifications',
                    'notify_payment_submission' => 'Payment submission notifications',
                    'notify_purchase_approval'  => 'Purchase approval notifications',
                ];
                foreach ($checks as $key => $label):
                ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="<?= esc($key) ?>" name="<?= esc($key) ?>" value="1"
                            <?= ($settings[$key] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="<?= esc($key) ?>"><?= esc($label) ?></label>
                    </div>
                <?php endforeach; ?>
                <button class="btn btn-gold mt-3" type="submit">Save Notifications</button>
            </form>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card-premium">
            <h2 class="h5 mb-3">Delivery Status</h2>
            <div class="d-flex justify-content-between mb-2"><span class="text-secondary">Finance email switch</span><strong><?= ! empty($notifyInfra['email_enabled']) ? 'On' : 'Off' ?></strong></div>
            <div class="d-flex justify-content-between mb-2"><span class="text-secondary">Delivery configured</span><strong><?= ! empty($notifyInfra['configured']) ? 'Yes' : 'No' ?></strong></div>
            <div class="d-flex justify-content-between"><span class="text-secondary">Min severity</span><strong><?= esc($notifyInfra['min_severity'] ?? '—') ?></strong></div>
            <a class="btn btn-sm btn-outline-gold mt-3" href="<?= site_url('admin/finance/notifications') ?>">Open Outbox</a>
        </div>
    </div>
</div>

<?php elseif ($tab === 'security'): ?>
<div class="card-premium">
    <h2 class="h5 mb-3">Security Controls</h2>
    <div class="table-responsive">
        <table class="table-premium">
            <thead><tr><th>Control</th><th>Value</th></tr></thead>
            <tbody>
            <?php foreach ($security as $row): ?>
                <tr>
                    <td><?= esc($row['label']) ?></td>
                    <td><?= esc($row['value']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3">
        <a class="btn btn-outline-gold btn-sm" href="<?= site_url('member/security') ?>">Member password changes use /member/security</a>
    </div>
</div>

<?php else: ?>
<?php
$auditRows = $audit['rows'] ?? [];
$auditTotal = (int) ($audit['total'] ?? 0);
?>
<div class="card-premium">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Audit History</h2>
        <div class="text-secondary small"><?= number_format($auditTotal) ?> changes</div>
    </div>
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Setting</th>
                <th>Previous</th>
                <th>New</th>
                <th>Changed By</th>
                <th>Changed At</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($auditRows === []): ?>
                <tr><td colspan="5" class="text-secondary">No setting changes recorded yet.</td></tr>
            <?php else: ?>
                <?php foreach ($auditRows as $row): ?>
                    <tr>
                        <td><code><?= esc($row['setting_key'] ?? '') ?></code></td>
                        <td class="small text-break"><?= esc($row['previous_value'] ?? '') ?></td>
                        <td class="small text-break"><?= esc($row['new_value'] ?? '') ?></td>
                        <td><?= esc($row['member_name'] ?? $row['member_id'] ?? ('#' . ($row['changed_by'] ?? ''))) ?></td>
                        <td><?= esc($row['changed_at'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?= $this->endSection() ?>
