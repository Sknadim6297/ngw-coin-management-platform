<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<div class="card-premium" style="max-width: 720px;">
    <h2 class="h5 mb-3">Create Ticket</h2>
    <form method="post" action="<?= site_url('member/support') ?>">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label" for="category">Category</label>
            <select class="form-select" id="category" name="category" required>
                <?php foreach ($categories as $key => $label): ?>
                    <option value="<?= esc($key) ?>" <?= old('category') === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="subject">Subject</label>
            <input class="form-control" id="subject" name="subject" required minlength="5" maxlength="180"
                   value="<?= esc(old('subject')) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label" for="message">Message</label>
            <textarea class="form-control" id="message" name="message" rows="5" required minlength="5" maxlength="5000"><?= esc(old('message')) ?></textarea>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-gold" type="submit">Submit</button>
            <a class="btn btn-outline-gold" href="<?= site_url('member/support') ?>">Cancel</a>
        </div>
    </form>
</div>
<?= $this->endSection() ?>
