<?php if (session()->getFlashdata('success')): ?>
    <div class="alert alert-success" role="alert"><?= esc(session()->getFlashdata('success')) ?></div>
<?php endif; ?>

<?php if (session()->getFlashdata('error')): ?>
    <div class="alert alert-danger" role="alert"><?= esc(session()->getFlashdata('error')) ?></div>
<?php endif; ?>

<?php $errors = session()->getFlashdata('errors'); ?>
<?php if (is_array($errors) && $errors !== []): ?>
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $error): ?>
                <li><?= esc(is_array($error) ? implode(' ', $error) : $error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
