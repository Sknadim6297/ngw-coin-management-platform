<?php
/**
 * Shared Login-as-Member modal (Phase 10D).
 *
 * @var string $memberId
 * @var string|null $memberName
 * @var string $modalId
 */
$memberId   = (string) ($memberId ?? '');
$memberName = (string) ($memberName ?? '');
$modalId    = (string) ($modalId ?? 'impersonateModal');
$actionBase = site_url('admin/members');
?>
<div class="modal fade modal-impersonate" id="<?= esc($modalId) ?>" tabindex="-1" aria-labelledby="<?= esc($modalId) ?>Title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content"
              method="post"
              action="<?= esc($actionBase . '/' . rawurlencode($memberId) . '/impersonate') ?>"
              target="_blank"
              data-impersonate-form
              data-action-base="<?= esc($actionBase) ?>">
            <?= csrf_field() ?>
            <div class="modal-header">
                <div class="d-flex align-items-center gap-3">
                    <span class="modal-icon" aria-hidden="true"><i class="bi bi-box-arrow-up-right"></i></span>
                    <div>
                        <h2 class="modal-title h5 mb-0" id="<?= esc($modalId) ?>Title">
                            Login as <span data-impersonate-member-id><?= esc($memberId) ?></span>
                        </h2>
                        <div class="small text-secondary" data-impersonate-member-name><?= esc($memberName) ?></div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-secondary mb-3">
                    Opens a <strong class="text-warning">read-only</strong> support view in a new tab.
                    Your Admin session stays signed in on this tab. Re-enter your admin password to continue.
                </p>
                <label class="form-label" for="<?= esc($modalId) ?>Password">Admin password</label>
                <input class="form-control"
                       type="password"
                       name="admin_password"
                       id="<?= esc($modalId) ?>Password"
                       required
                       autocomplete="current-password"
                       placeholder="Confirm admin password">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-gold" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-gold">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Open Member View
                </button>
            </div>
        </form>
    </div>
</div>
