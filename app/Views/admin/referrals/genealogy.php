<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Bounded genealogy inspection and read-only network integrity. Auto-repair is disabled.</p>

<form class="card-premium mb-4" method="get" action="<?= site_url('admin/genealogy') ?>">
    <div class="row g-3 align-items-end">
        <div class="col-md-5">
            <label class="form-label" for="member_id">Member ID</label>
            <input class="form-control" id="member_id" name="member_id" value="<?= esc($memberId) ?>" placeholder="e.g. CPM000001">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="depth">Depth</label>
            <select class="form-select" id="depth" name="depth">
                <?php foreach ([1, 2, 3, 4, 5] as $d): ?>
                    <option value="<?= $d ?>" <?= (int) ($depth ?? 3) === $d ? 'selected' : '' ?>><?= $d ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 d-flex flex-wrap gap-2">
            <button class="btn btn-gold" type="submit">Load Tree</button>
            <a class="btn btn-outline-gold" href="<?= site_url('admin/referrals') ?>">Referral Lookup</a>
        </div>
    </div>
</form>

<?php if (! empty($error)): ?>
    <div class="alert alert-warning"><?= esc($error) ?></div>
<?php endif; ?>

<?php if ($profile !== null && $tree !== null): ?>
    <div class="card-premium mb-4">
        <h2 class="h5 mb-3">Tree for <?= esc($profile->member_id) ?> · Team <?= (int) ($counts['total'] ?? 0) ?></h2>
        <?php if (! empty($tree['truncated'])): ?>
            <div class="alert alert-warning">Tree truncated at safety limit.</div>
        <?php endif; ?>
        <?php
        $byParent = $tree['by_parent'] ?? [];
        $rootId   = (int) $tree['root_user_id'];
        $render = function (int $parentId) use (&$render, $byParent): void {
            $children = $byParent[$parentId] ?? [];
            if ($children === []) {
                echo '<div class="text-secondary">No downline in this depth window.</div>';
                return;
            }
            echo '<ul class="genealogy-list" style="list-style:none;padding-left:1rem">';
            foreach ($children as $node) {
                echo '<li class="mb-2"><strong>' . esc($node['member_id']) . '</strong> '
                    . esc($node['member_name'])
                    . ' <span class="badge-status ' . esc($node['account_status']) . '">' . esc($node['account_status']) . '</span>'
                    . ' <span class="small text-secondary">L' . (int) $node['level'] . '</span>';
                if (isset($byParent[$node['user_id']])) {
                    $render((int) $node['user_id']);
                }
                echo '</li>';
            }
            echo '</ul>';
        };
        $render($rootId);
        ?>
    </div>
<?php endif; ?>

<div class="card-premium">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Network Integrity Report</h2>
        <span class="badge text-bg-dark">Auto-repair: Disabled</span>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="text-secondary">Findings</div><strong><?= (int) $report['summary']['total_findings'] ?></strong></div>
        <div class="col-md-3"><div class="text-secondary">Critical</div><strong><?= (int) $report['summary']['critical'] ?></strong></div>
        <div class="col-md-3"><div class="text-secondary">Warning</div><strong><?= (int) $report['summary']['warning'] ?></strong></div>
        <div class="col-md-3"><div class="text-secondary">Generated</div><strong><?= esc($report['generated_at']) ?></strong></div>
    </div>
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Code</th>
                <th>Severity</th>
                <th>Message</th>
                <th>Member</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($report['findings'] as $finding): ?>
                <tr>
                    <td><code><?= esc($finding['code'] ?? '') ?></code></td>
                    <td><?= esc($finding['severity'] ?? '') ?></td>
                    <td><?= esc($finding['message'] ?? '') ?></td>
                    <td><?= esc((string) ($finding['member_id'] ?? $finding['member_user_id'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
