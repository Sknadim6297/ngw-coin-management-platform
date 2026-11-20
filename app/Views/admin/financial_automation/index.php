<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?php
/** @var array<string, mixed> $s */
$controls  = $s['controls'];
$readiness = $s['readiness'];
$rules     = $s['rules'];
$metrics   = $s['metrics'];
$last      = $s['last_success'];
$next      = $s['next_run'];
$allOn     = (bool) $s['all_on'];
$anyOn     = (bool) $s['any_on'];
$allReady  = (bool) $s['all_ready'];
$hb        = $s['heartbeat_at'];
$cronOk    = false;
foreach ($readiness['scheduler']['checks'] as $c) {
    if ($c['key'] === 'cron') {
        $cronOk = (bool) $c['ok'];
    }
}
$fmt = static fn (?string $v): string => $v === null || $v === '' ? '—' : (string) $v;
$num = static function (string $v): string {
    $t = rtrim(rtrim($v, '0'), '.');

    return $t === '' ? '0' : $t;
};
$masterState = $allOn ? 'on' : ($anyOn ? 'partial' : 'off');
$masterText  = ['on' => 'All automation is ON', 'partial' => 'Partially ON', 'off' => 'All automation is OFF'][$masterState];
$icons = [
    'daily_mining'     => 'bi-cpu',
    'level_commission' => 'bi-diagram-3',
    'scheduler'        => 'bi-clock-history',
];
$blurbs = [
    'daily_mining'     => 'Credits each member\'s daily mining reward in COIN, once per business day.',
    'level_commission' => 'Posts 3-level commissions from approved USDT purchases to commission wallets.',
    'scheduler'        => 'Lets the server cron run the two jobs above automatically every day.',
];
?>
<style>
.fa-wrap { max-width: 1280px; }
.fa-grid { display: grid; gap: 1rem; }
.fa-grid.cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.fa-grid.cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.fa-grid.cols-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
@media (max-width: 1199.98px) { .fa-grid.cols-4 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 991.98px) { .fa-grid.cols-3 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 767.98px) { .fa-grid.cols-3, .fa-grid.cols-2, .fa-grid.cols-4 { grid-template-columns: minmax(0, 1fr); } }
.fa-master { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1.25rem; border-color: rgba(212,175,55,.45); }
.fa-master h2 { font-size: 1.35rem; margin: 0; }
.fa-state { display: inline-flex; align-items: center; gap: .5rem; font-weight: 700; letter-spacing: .01em; }
.fa-dot { width: .7rem; height: .7rem; border-radius: 50%; background: var(--text-secondary); display: inline-block; }
.fa-dot.on { background: var(--success); }
.fa-dot.partial { background: var(--warning); }
.fa-dot.off { background: #6b6b73; }
.fa-dot.bad { background: var(--danger); }
.fa-card h3 { font-size: 1.05rem; margin: 0; }
.fa-card .fa-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin-bottom: .6rem; }
.fa-card .fa-ico { color: var(--gold); font-size: 1.35rem; margin-right: .5rem; }
.fa-pill { border: 1px solid var(--border); border-radius: 999px; padding: .15rem .65rem; font-size: .78rem; }
.fa-pill.on { color: var(--success); border-color: rgba(61,220,151,.45); background: rgba(61,220,151,.08); }
.fa-pill.off { color: var(--text-secondary); }
.fa-pill.bad { color: #ffb3bb; border-color: rgba(227,93,106,.45); background: rgba(227,93,106,.08); }
.fa-checks { list-style: none; margin: .75rem 0 0; padding: 0; font-size: .85rem; }
.fa-checks li { display: flex; gap: .55rem; padding: .35rem 0; border-top: 1px solid rgba(255,255,255,.05); }
.fa-checks .ok { color: var(--success); }
.fa-checks .no { color: var(--danger); }
.fa-checks small { display: block; color: var(--text-secondary); }
.fa-kv { display: grid; grid-template-columns: 11rem 1fr; gap: .4rem .75rem; font-size: .92rem; margin: 0; }
.fa-kv dt { color: var(--text-secondary); font-weight: 500; }
.fa-kv dd { margin: 0; }
@media (max-width: 575.98px) { .fa-kv { grid-template-columns: 1fr; gap: .1rem; } .fa-kv dd { margin-bottom: .5rem; } }
.fa-tile .fa-label { color: var(--text-secondary); font-size: .8rem; }
.fa-tile .fa-value { font-size: 1.35rem; font-weight: 700; color: var(--gold-bright); word-break: break-word; }
.fa-tile .fa-sub { color: var(--text-secondary); font-size: .8rem; }
.fa-blockers { border-color: rgba(227,93,106,.45); }
.fa-blockers li { margin-bottom: .35rem; }
.fa-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
.fa-note { color: var(--text-secondary); font-size: .82rem; }
.fa-advanced { border-style: dashed; }
.btn-fa-stop { --bs-btn-color: #ffb3bb; --bs-btn-border-color: rgba(227,93,106,.6); --bs-btn-hover-bg: rgba(227,93,106,.15); --bs-btn-hover-border-color: var(--danger); --bs-btn-hover-color: #fff; }
.fa-table td, .fa-table th { white-space: nowrap; }
</style>

<div class="fa-wrap">
    <p class="page-subtitle">
        One place to run daily mining and level commissions. Switch automation ON only when every check below is green.
        Switching OFF only stops <em>future</em> posting — existing earnings and ledger entries are never touched.
    </p>

    <?php if ($blocked !== []): ?>
        <div class="card-premium fa-blockers mb-3" role="alert">
            <h2 class="h6 mb-2"><i class="bi bi-shield-exclamation me-1"></i> Activation prevented — fix these first</h2>
            <ul class="mb-0 small">
                <?php foreach ($blocked as $b): ?><li><?= esc((string) $b) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Master control -->
    <div class="card-premium fa-master mb-3">
        <div>
            <div class="fa-state mb-1"><span class="fa-dot <?= esc($masterState) ?>"></span><h2><?= esc($masterText) ?></h2></div>
            <div class="fa-note">
                Database <code><?= esc($s['database']) ?></code>
                · Server cron:
                <?php if ($cronOk): ?><span class="text-success">working</span><?php else: ?><span class="text-danger">not detected</span><?php endif; ?>
                <?php if ($hb !== null): ?>(last tick <?= esc(date('Y-m-d H:i:s', (int) $hb)) ?> UTC)<?php endif; ?>
            </div>
        </div>
        <?php if (! empty($canControl)): ?>
            <div class="fa-actions">
                <button type="button" class="btn btn-gold btn-lg"
                        data-fa-open="<?= esc(site_url('admin/financial-automation/start-all'), 'attr') ?>"
                        data-fa-title="Start All Automation"
                        <?= $allReady && ! $allOn ? '' : 'disabled' ?>>
                    <i class="bi bi-play-fill me-1"></i> Start All Automation
                </button>
                <form method="post" action="<?= site_url('admin/financial-automation/stop-all') ?>" onsubmit="return confirm('Stop ALL automation? Future posting stops; existing earnings are kept.');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-gold btn-lg btn-fa-stop" <?= $anyOn ? '' : 'disabled' ?>>
                        <i class="bi bi-stop-fill me-1"></i> Stop All Automation
                    </button>
                </form>
            </div>
        <?php else: ?>
            <span class="fa-note">View only — a finance approver or platform administrator can change the switches.</span>
        <?php endif; ?>
    </div>
    <?php if (! $allReady && ! $allOn): ?>
        <p class="fa-note mb-3"><i class="bi bi-info-circle me-1"></i> “Start All” is unavailable until every control below is ready. Nothing is approved or created automatically.</p>
    <?php endif; ?>

    <!-- Three controls -->
    <div class="fa-grid cols-3 mb-3">
        <?php foreach ($controls as $key => $c):
            $r = $readiness[$key];
            $on = (bool) $c['enabled'];
            $pill = $on && $c['active'] ? ['on', 'ON'] : ($on ? ['bad', 'ON — authorization invalid'] : ['off', 'OFF']);
            ?>
            <div class="card-premium fa-card">
                <div class="fa-head">
                    <h3><i class="bi <?= esc($icons[$key]) ?> fa-ico"></i><?= esc($c['label']) ?></h3>
                    <span class="fa-pill <?= esc($pill[0]) ?>"><?= esc($pill[1]) ?></span>
                </div>
                <p class="fa-note mb-2"><?= esc($blurbs[$key]) ?></p>
                <div class="fa-state mb-2">
                    <span class="fa-dot <?= $r['ready'] ? 'on' : 'bad' ?>"></span>
                    <span class="small"><?= $r['ready'] ? 'All checks passed' : count($r['blockers']) . ' check(s) failing' ?></span>
                </div>
                <?php if (! empty($canControl)): ?>
                    <div class="fa-actions">
                        <?php if (! $on): ?>
                            <button type="button" class="btn btn-gold btn-sm"
                                    data-fa-open="<?= esc(site_url('admin/financial-automation/' . $key . '/on'), 'attr') ?>"
                                    data-fa-title="Turn ON — <?= esc($c['label'], 'attr') ?>"
                                    <?= $r['ready'] ? '' : 'disabled' ?>>Turn ON</button>
                        <?php else: ?>
                            <form method="post" action="<?= site_url('admin/financial-automation/' . $key . '/off') ?>" onsubmit="return confirm('Turn OFF <?= esc($c['label'], 'js') ?>? Existing earnings are kept.');">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-outline-gold btn-sm btn-fa-stop">Turn OFF</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <ul class="fa-checks">
                    <?php foreach ($r['checks'] as $chk): ?>
                        <li>
                            <i class="bi <?= $chk['ok'] ? 'bi-check-circle-fill ok' : 'bi-x-circle-fill no' ?>"></i>
                            <span><?= esc($chk['label']) ?><small><?= esc($chk['detail']) ?></small></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Business rules -->
    <div class="fa-grid cols-2 mb-3">
        <div class="card-premium">
            <h2 class="h6 mb-3"><i class="bi bi-cpu me-1 text-warning"></i> Mining rules</h2>
            <dl class="fa-kv">
                <dt>Rate</dt><dd><?= esc($rules['mining']['monthly_rate']) ?></dd>
                <dt>Daily divisor</dt><dd><?= esc($rules['mining']['divisor']) ?></dd>
                <dt>Compounding</dt><dd><?= esc($rules['mining']['compounding']) ?></dd>
                <dt>Approval day</dt><dd><?= esc($rules['mining']['approval_day']) ?></dd>
                <dt>Reward</dt><dd>Paid in <?= esc($rules['mining']['reward_asset']) ?></dd>
                <dt>Business timezone</dt><dd><?= esc($rules['mining']['timezone']) ?></dd>
            </dl>
        </div>
        <div class="card-premium">
            <h2 class="h6 mb-3"><i class="bi bi-diagram-3 me-1 text-warning"></i> Commission rules</h2>
            <dl class="fa-kv">
                <dt>Level 1</dt><dd><?= esc($rules['commission']['level_1']) ?></dd>
                <dt>Level 2</dt><dd><?= esc($rules['commission']['level_2']) ?></dd>
                <dt>Level 3</dt><dd><?= esc($rules['commission']['level_3']) ?></dd>
                <dt>Calculated from</dt><dd><?= esc($rules['commission']['base']) ?></dd>
                <dt>Plan</dt><dd><?= $rules['commission']['plan_ref'] !== null ? '<code>' . esc((string) $rules['commission']['plan_ref']) . '</code>' : '<span class="text-warning">No approved plan yet</span>' ?></dd>
                <dt>Status</dt><dd><?= esc($rules['commission']['note']) ?></dd>
            </dl>
        </div>
    </div>

    <!-- Operations -->
    <div class="fa-grid cols-4 mb-3">
        <div class="card-premium fa-tile">
            <div class="fa-label">Last successful run</div>
            <?php foreach (['mining' => 'Mining', 'commission' => 'Commission'] as $job => $label): ?>
                <div class="mt-1">
                    <strong><?= esc($label) ?></strong>:
                    <?php if ($last[$job] !== null): ?>
                        <?= esc((string) $last[$job]['business_date']) ?>
                        <div class="fa-sub">finished <?= esc((string) $last[$job]['finished_at']) ?> UTC</div>
                    <?php else: ?>
                        <span class="text-secondary">never</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="card-premium fa-tile">
            <div class="fa-label">Next scheduled run</div>
            <div class="fa-value" style="font-size:1.1rem;"><?= esc($fmt($next['at'])) ?></div>
            <div class="fa-sub"><?= esc($next['label']) ?><?= $next['business_date'] !== null ? ' · posts ' . esc($next['business_date']) : '' ?></div>
        </div>
        <div class="card-premium fa-tile">
            <div class="fa-label">Generated mining credits</div>
            <div class="fa-value"><?= esc($num($metrics['mining_credit_total'])) ?> <?= esc($rules['mining']['reward_asset']) ?></div>
            <div class="fa-sub"><?= (int) $metrics['mining_credit_count'] ?> credit entries (ledger)</div>
        </div>
        <div class="card-premium fa-tile">
            <div class="fa-label">Posted commissions</div>
            <div class="fa-value"><?= esc($num($metrics['commission_post_total'])) ?> USDT</div>
            <div class="fa-sub"><?= (int) $metrics['commission_post_count'] ?> commission entries (ledger)</div>
        </div>
    </div>

    <div class="fa-grid cols-2 mb-3">
        <div class="card-premium <?= $s['failed_jobs'] !== [] ? 'fa-blockers' : '' ?>">
            <h2 class="h6 mb-2"><i class="bi bi-exclamation-octagon me-1"></i> Failed jobs (last 7 days) <span class="fa-pill <?= $s['failed_jobs'] === [] ? 'on' : 'bad' ?>"><?= count($s['failed_jobs']) ?></span></h2>
            <?php if ($s['failed_jobs'] === []): ?>
                <p class="fa-note mb-0">No unresolved failed jobs.</p>
            <?php else: ?>
                <ul class="small mb-0">
                    <?php foreach ($s['failed_jobs'] as $f): ?>
                        <li><strong><?= esc($f['job']) ?></strong> <?= esc((string) $f['business_date']) ?> — <?= esc($f['status']) ?>: <?= esc((string) $f['message']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="card-premium <?= $s['blockers'] !== [] ? 'fa-blockers' : '' ?>">
            <h2 class="h6 mb-2"><i class="bi bi-shield-exclamation me-1"></i> Current blockers <span class="fa-pill <?= $s['blockers'] === [] ? 'on' : 'bad' ?>"><?= count($s['blockers']) ?></span></h2>
            <?php if ($s['blockers'] === []): ?>
                <p class="fa-note mb-0">Nothing blocks automation.</p>
            <?php else: ?>
                <ul class="small mb-0">
                    <?php foreach ($s['blockers'] as $b): ?><li><?= esc((string) $b) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Safety -->
    <div class="card-premium mb-3">
        <h2 class="h6 mb-2"><i class="bi bi-lock me-1 text-warning"></i> Not controlled here — stay OFF unless separately authorized</h2>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($s['restricted'] as $f): ?>
                <span class="fa-pill <?= $f['on'] ? 'bad' : 'off' ?>"><?= esc($f['label']) ?>: <?= $f['on'] ? 'ON' : 'OFF' ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Tools -->
    <div class="card-premium mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <h2 class="h6 mb-0"><i class="bi bi-journal-check me-1 text-warning"></i> Verification (read-only)</h2>
            <div class="fa-actions">
                <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/financial-automation?reconcile=1') ?>">Run reconciliation</a>
                <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/financial-automation?preview=1') ?>">Preview pending commissions</a>
            </div>
        </div>
        <?php if (isset($s['reconciliation'])): $rec = $s['reconciliation']; ?>
            <p class="mb-1"><span class="fa-pill <?= $rec['ok'] ? 'on' : 'bad' ?>"><?= $rec['ok'] ? 'Reconciled — no differences' : 'Differences found' ?></span></p>
            <?php if ($rec['issues'] !== []): ?><ul class="small mb-2"><?php foreach ($rec['issues'] as $i): ?><li><?= esc($i) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <p class="fa-note mb-0">Mining ledger credits <?= esc($num($rec['mining']['ledger_credits'])) ?> · wallet balances <?= esc($num($rec['mining']['wallet_balance'])) ?> · completed day runs <?= esc($num($rec['mining']['day_runs_total'])) ?></p>
        <?php endif; ?>
        <?php if (isset($s['commission_preview'])): $pv = $s['commission_preview']; ?>
            <?php if (! empty($pv['error'])): ?>
                <p class="text-danger small mb-0">Preview unavailable: <?= esc((string) $pv['error']) ?></p>
            <?php else: ?>
                <p class="mb-0 small mt-2">If commission automation runs now it would post <strong><?= (int) $pv['pending_items'] ?></strong> pending item(s) totalling <strong><?= esc($num((string) $pv['missing_total_usdt'])) ?> USDT</strong>
                    (<?= (int) $pv['already_posted_items'] ?> already posted). Review this before turning Level Commission ON.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($s['recent_runs'] !== []): ?>
        <div class="card-premium mb-3">
            <h2 class="h6 mb-2"><i class="bi bi-clock-history me-1 text-warning"></i> Recent automation runs</h2>
            <div class="table-responsive">
                <table class="table table-sm table-dark align-middle mb-0 fa-table">
                    <thead><tr><th>When (UTC)</th><th>Job</th><th>Business date</th><th>Status</th><th class="text-end">Credits</th><th class="text-end">Amount</th><th>Message</th></tr></thead>
                    <tbody>
                    <?php foreach ($s['recent_runs'] as $run): ?>
                        <tr>
                            <td><?= esc((string) $run['started_at']) ?></td>
                            <td><?= esc($run['job']) ?></td>
                            <td><?= esc((string) $run['business_date']) ?></td>
                            <td><span class="fa-pill <?= $run['status'] === 'success' ? 'on' : (in_array($run['status'], ['failed', 'blocked'], true) ? 'bad' : 'off') ?>"><?= esc($run['status']) ?></span></td>
                            <td class="text-end"><?= (int) $run['credits_count'] ?></td>
                            <td class="text-end"><?= esc($num((string) $run['amount'])) ?></td>
                            <td style="white-space:normal;min-width:14rem;"><?= esc((string) $run['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Advanced -->
    <div class="card-premium fa-advanced">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h2 class="h6 mb-1"><i class="bi bi-sliders me-1 text-warning"></i> Advanced configuration</h2>
                <p class="fa-note mb-0">Policy drafts, dual-control approvals, rates and plans are managed in Financial Policies. This is not part of the daily workflow.</p>
            </div>
            <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/financial-policies') ?>">Open Financial Policies (Advanced)</a>
        </div>
    </div>
</div>

<?php if (! empty($canControl)): ?>
<!-- Confirmation modal (ON / Start All) -->
<div class="modal fade" id="faModal" tabindex="-1" aria-labelledby="faModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content card-premium" method="post" id="faForm" action="#">
            <?= csrf_field() ?>
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title" id="faModalTitle">Confirm</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-secondary">
                    This records a server-side authorization and lets the scheduler post <strong>real earnings</strong> once every check passes.
                    It does not approve any policy and does not create any credit by itself.
                </p>
                <div class="mb-3">
                    <label class="form-label small" for="faConfirm">Type <code><?= esc($s['confirm_phrase']) ?></code> to confirm</label>
                    <input class="form-control" type="text" name="confirm" id="faConfirm" autocomplete="off" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small" for="faPassword">Your admin password</label>
                    <input class="form-control" type="password" name="password" id="faPassword" autocomplete="current-password" required>
                </div>
                <div class="mb-0">
                    <label class="form-label small" for="faReason">Note (optional)</label>
                    <input class="form-control" type="text" name="reason" id="faReason" maxlength="200">
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-gold" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-gold">Confirm &amp; Turn ON</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
    var modalEl = document.getElementById('faModal');
    if (!modalEl || typeof bootstrap === 'undefined') { return; }
    var modal = new bootstrap.Modal(modalEl);
    var form = document.getElementById('faForm');
    document.querySelectorAll('[data-fa-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            form.setAttribute('action', btn.getAttribute('data-fa-open'));
            document.getElementById('faModalTitle').textContent = btn.getAttribute('data-fa-title') || 'Confirm';
            form.reset();
            modal.show();
            setTimeout(function () { document.getElementById('faConfirm').focus(); }, 300);
        });
    });
})();
</script>
<?= $this->endSection() ?>
