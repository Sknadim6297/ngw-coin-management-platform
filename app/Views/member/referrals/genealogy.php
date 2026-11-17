<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Sponsor-based team hierarchy. Bounded depth prevents runaway loads. No earnings are calculated here.</p>

<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="card-premium my-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h5 mb-0">Level Summary</h2>
        <form method="get" class="d-flex flex-wrap gap-2 align-items-end">
            <div>
                <label class="form-label mb-0" for="depth">Tree depth</label>
                <select class="form-select form-select-sm" id="depth" name="depth" onchange="this.form.submit()">
                    <?php foreach ([1, 2, 3, 4, 5] as $d): ?>
                        <option value="<?= $d ?>" <?= (int) $depth === $d ? 'selected' : '' ?>><?= $d ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <a class="btn btn-sm btn-outline-gold" href="<?= site_url('member/referrals') ?>">Direct Referrals</a>
        </form>
    </div>
    <div class="row g-3">
        <?php foreach (($counts['levels'] ?? []) as $level => $stats): ?>
            <div class="col-md-4">
                <div class="text-secondary">Level <?= (int) $level ?></div>
                <strong><?= (int) $stats['total'] ?></strong>
                <div class="small text-secondary">Active <?= (int) $stats['active'] ?> · Inactive <?= (int) $stats['inactive'] ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card-premium">
    <h2 class="h5 mb-3">Genealogy Tree — <?= esc($profile->member_id) ?></h2>
    <?php if (! empty($tree['truncated'])): ?>
        <div class="alert alert-warning">Tree truncated at the safety node limit. Narrow depth or contact support for large-network exports.</div>
    <?php endif; ?>
    <?php
    $byParent = $tree['by_parent'] ?? [];
    $rootId   = (int) ($tree['root_user_id'] ?? 0);
    $renderChildren = function (int $parentId, int $indent) use (&$renderChildren, $byParent): void {
        $children = $byParent[$parentId] ?? [];
        if ($children === []) {
            if ($indent === 0) {
                echo '<div class="text-secondary">No downline members in this depth window.</div>';
            }
            return;
        }
        echo '<ul class="genealogy-list" style="list-style:none;padding-left:' . ($indent === 0 ? '0' : '1.25rem') . '">';
        foreach ($children as $node) {
            $statusClass = $node['account_status'] === 'active' ? 'active' : 'inactive';
            echo '<li class="mb-2">';
            echo '<div class="d-flex flex-wrap gap-2 align-items-center">';
            echo '<strong>' . esc($node['member_id']) . '</strong>';
            echo '<span>' . esc($node['member_name']) . '</span>';
            echo '<span class="badge-status ' . esc($statusClass) . '">' . esc($node['account_status']) . '</span>';
            echo '<span class="small text-secondary">L' . (int) $node['level'] . ' · ' . esc($node['registered_at']) . '</span>';
            echo '</div>';
            $renderChildren((int) $node['user_id'], $indent + 1);
            echo '</li>';
        }
        echo '</ul>';
    };
    $renderChildren($rootId, 0);
    ?>
</div>
<?= $this->endSection() ?>
