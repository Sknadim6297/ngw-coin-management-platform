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

<style>
    .gt-wrap { overflow-x: auto; padding: .25rem .25rem 1rem; }
    .gt-legend { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; font-size: .85rem; color: var(--text-secondary); margin-bottom: 1rem; }
    .gt-dot { display: inline-block; width: .6rem; height: .6rem; border-radius: 50%; margin-right: .35rem; vertical-align: middle; }
    .gt-dot.is-active { background: #3ddc84; box-shadow: 0 0 6px rgba(61, 220, 132, .55); }
    .gt-dot.is-inactive { background: transparent; border: 1px solid var(--text-secondary); }

    .gt-root-card {
        display: inline-flex; flex-wrap: wrap; align-items: center; gap: .6rem;
        padding: .75rem 1rem; border-radius: var(--radius);
        background: linear-gradient(135deg, rgba(244, 215, 123, .16), rgba(212, 175, 55, .06));
        border: 1px solid var(--gold);
        color: var(--gold-bright);
    }
    .gt-root-label { font-size: .7rem; letter-spacing: .08em; text-transform: uppercase; color: var(--gold-muted); }

    .gt-list { list-style: none; margin: 0; padding: 0 0 0 2.25rem; }
    .gt-list > li { position: relative; padding-top: .65rem; }
    .gt-list > li::before {
        content: ""; position: absolute; left: -1.25rem; top: 0; bottom: 0;
        border-left: 1px solid rgba(212, 175, 55, .35);
    }
    .gt-list > li:last-child::before { bottom: auto; height: 2.2rem; }
    .gt-list > li::after {
        content: ""; position: absolute; left: -1.25rem; top: 2.2rem; width: 1.25rem;
        border-top: 1px solid rgba(212, 175, 55, .35);
    }
    .gt-list details > summary { list-style: none; cursor: pointer; }
    .gt-list details > summary::-webkit-details-marker { display: none; }

    .gt-card {
        display: inline-flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem;
        padding: .5rem .85rem; min-height: 2.9rem;
        border-radius: 10px; border: 1px solid var(--border);
        background: rgba(255, 255, 255, .03);
        transition: border-color .15s ease, background-color .15s ease;
    }
    .gt-card:hover { border-color: rgba(212, 175, 55, .6); background: rgba(212, 175, 55, .06); }
    .gt-card.is-active { border-left: 3px solid #3ddc84; }
    .gt-card.is-inactive { border-left: 3px solid #6b6b75; opacity: .85; }
    .gt-id { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-weight: 700; color: var(--gold-bright); }
    .gt-name { color: var(--text); }
    .gt-meta { font-size: .78rem; color: var(--text-secondary); }
    .gt-count { font-size: .75rem; padding: .1rem .5rem; border-radius: 999px; border: 1px solid rgba(212, 175, 55, .45); color: var(--gold-bright); }
    .gt-more { font-size: .75rem; color: var(--text-secondary); font-style: italic; }
    .gt-chevron { display: inline-block; margin-right: .35rem; color: var(--gold-muted); transition: transform .15s ease; }
    details[open] > summary .gt-chevron { transform: rotate(90deg); }
    @media (max-width: 576px) {
        .gt-list { padding-left: 1.5rem; }
        .gt-list > li::before { left: -.9rem; }
        .gt-list > li::after { left: -.9rem; width: .9rem; }
    }
</style>

<div class="card-premium">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h5 mb-0">Genealogy Tree — <?= esc($profile->member_id) ?></h2>
        <div class="gt-legend">
            <span><span class="gt-dot is-active"></span>Active</span>
            <span><span class="gt-dot is-inactive"></span>Inactive</span>
            <span>Click a card to collapse or expand its downline</span>
        </div>
    </div>
    <?php if (! empty($tree['truncated'])): ?>
        <div class="alert alert-warning">Tree truncated at the safety node limit. Narrow depth or contact support for large-network exports.</div>
    <?php endif; ?>
    <?php
    $byParent = $tree['by_parent'] ?? [];
    $rootId   = (int) ($tree['root_user_id'] ?? 0);

    $renderBranch = function (int $parentId) use (&$renderBranch, $byParent): void {
        $children = $byParent[$parentId] ?? [];
        if ($children === []) {
            return;
        }
        echo '<ul class="gt-list">';
        foreach ($children as $node) {
            $uid      = (int) $node['user_id'];
            $isActive = $node['account_status'] === 'active';
            $loaded   = count($byParent[$uid] ?? []);
            $joined   = substr((string) ($node['registered_at'] ?? ''), 0, 10);

            $card  = '<div class="gt-card ' . ($isActive ? 'is-active' : 'is-inactive') . '">';
            if ($loaded > 0) {
                $card .= '<span class="gt-chevron">▸</span>';
            }
            $card .= '<span class="gt-id">' . esc($node['member_id']) . '</span>'
                . '<span class="gt-name">' . esc($node['member_name'] ?? '') . '</span>'
                . '<span class="badge-status ' . esc($node['account_status']) . '">' . esc($node['account_status']) . '</span>'
                . '<span class="gt-meta">L' . (int) $node['level'] . ($joined !== '' ? ' · joined ' . esc($joined) : '') . '</span>';
            if ($loaded > 0) {
                $card .= '<span class="gt-count">' . $loaded . ' direct</span>';
            } elseif (! empty($node['has_children'])) {
                $card .= '<span class="gt-more">more below, increase depth</span>';
            }
            $card .= '</div>';

            echo '<li>';
            if ($loaded > 0) {
                echo '<details open><summary>' . $card . '</summary>';
                $renderBranch($uid);
                echo '</details>';
            } else {
                echo $card;
            }
            echo '</li>';
        }
        echo '</ul>';
    };
    ?>
    <div class="gt-wrap">
        <div class="gt-root-card">
            <span class="gt-root-label">Root</span>
            <span class="gt-id"><?= esc($profile->member_id) ?></span>
            <span class="gt-name"><?= esc($profile->member_name ?? '') ?></span>
        </div>
        <?php if ($byParent === [] || ! isset($byParent[$rootId])): ?>
            <div class="text-secondary mt-3">No downline members in this depth window.</div>
        <?php else: ?>
            <?php $renderBranch($rootId); ?>
        <?php endif; ?>
    </div>
</div>
<?= $this->endSection() ?>
