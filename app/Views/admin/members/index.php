<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <p class="page-subtitle mb-0">Member accounts activate on first approved coin purchase.</p>
    <a class="btn btn-gold" href="<?= site_url('admin/members/create') ?>">Register Member</a>
</div>

<?php
$statusKey = $status ?? 'all';
if ($statusKey === null || $statusKey === '') {
    $statusKey = 'all';
}
$canImpersonate = ! empty($canImpersonate);
?>
<?= view('components/admin_tabs', [
    'active' => $statusKey,
    'tabs'   => [
        ['key' => 'all', 'label' => 'All Members', 'url' => site_url('admin/members')],
        ['key' => 'active', 'label' => 'Active Members', 'url' => site_url('admin/members?status=active')],
        ['key' => 'inactive', 'label' => 'Inactive Members', 'url' => site_url('admin/members?status=inactive')],
    ],
]) ?>

<div class="card-premium mb-3">
    <form method="get" class="row g-2 align-items-end">
        <?php if ($statusKey !== 'all'): ?>
            <input type="hidden" name="status" value="<?= esc($statusKey) ?>">
        <?php endif; ?>
        <div class="col-md-6">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" type="search" id="q" name="q" value="<?= esc($search ?? '') ?>"
                   placeholder="Member ID, name, or mobile">
        </div>
        <div class="col-md-3">
            <button class="btn btn-gold" type="submit">Search</button>
        </div>
    </form>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Member ID</th>
                <th>Name</th>
                <th>Sponsor</th>
                <th>Status</th>
                <th>Joined</th>
                <th>Purchased Coins</th>
                <th class="text-nowrap">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($members === []): ?>
                <tr><td colspan="7" class="text-secondary">No members found.</td></tr>
            <?php else: ?>
                <?php foreach ($members as $member): ?>
                    <?php
                    $uid = (int) $member->user_id;
                    $sponsor = $sponsors[$uid] ?? '—';
                    $coins = $purchasedCoins[$uid] ?? '0';
                    ?>
                    <tr>
                        <td><?= esc($member->member_id) ?></td>
                        <td><?= esc($member->member_name) ?></td>
                        <td><?= esc($sponsor) ?></td>
                        <td><span class="badge-status <?= esc($member->account_status) ?>"><?= esc(ucfirst($member->account_status)) ?></span></td>
                        <td class="text-nowrap"><?= ! empty($member->created_at) ? esc(date('d M Y, H:i', strtotime((string) $member->created_at))) : '—' ?></td>
                        <td><?= esc($coins) ?></td>
                        <td class="text-nowrap">
                            <a class="btn btn-sm btn-outline-gold" href="<?= site_url('admin/members/' . $member->member_id) ?>">View</a>
                            <?php if ($canImpersonate): ?>
                                <button
                                    class="btn btn-sm btn-gold"
                                    type="button"
                                    data-bs-toggle="modal"
                                    data-bs-target="#impersonateModal"
                                    data-member-id="<?= esc($member->member_id) ?>"
                                    data-member-name="<?= esc($member->member_name) ?>"
                                    title="Login as Member">
                                    <i class="bi bi-box-arrow-up-right"></i><span class="d-none d-lg-inline"> Login as Member</span>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3"><?= $pager->links() ?></div>
</div>

<?php if ($canImpersonate): ?>
    <?= view('components/impersonate_modal', [
        'modalId'    => 'impersonateModal',
        'memberId'   => '',
        'memberName' => '',
    ]) ?>
<?php endif; ?>
<?= $this->endSection() ?>

<?php if ($canImpersonate): ?>
<?= $this->section('scripts') ?>
<script>
(() => {
  const modal = document.getElementById('impersonateModal');
  if (!modal) return;
  const form = modal.querySelector('[data-impersonate-form]');
  const idEl = modal.querySelector('[data-impersonate-member-id]');
  const nameEl = modal.querySelector('[data-impersonate-member-name]');
  const pwd = modal.querySelector('input[name="admin_password"]');
  const base = form?.dataset.actionBase || '';

  modal.addEventListener('show.bs.modal', (event) => {
    const btn = event.relatedTarget;
    if (!btn || !form) return;
    const memberId = btn.getAttribute('data-member-id') || '';
    const memberName = btn.getAttribute('data-member-name') || '';
    if (idEl) idEl.textContent = memberId;
    if (nameEl) nameEl.textContent = memberName;
    form.action = base.replace(/\/$/, '') + '/' + encodeURIComponent(memberId) + '/impersonate';
    if (pwd) pwd.value = '';
  });
})();
</script>
<?= $this->endSection() ?>
<?php endif; ?>
