<?php
$appName = $appName ?? config('Platform')->appName;
$mode    = $mode ?? 'member'; // member|admin|register|forgot
$copy    = [
    'member' => [
        'title' => 'Member Access',
        'text'  => 'Sign in to your NGW membership dashboard with your Member ID.',
    ],
    'admin' => [
        'title' => 'Admin Access',
        'text'  => 'Secure administrator console for inventory, pricing, and member management.',
    ],
    'register' => [
        'title' => 'Join NGW',
        'text'  => 'Create your free membership. Accounts stay inactive until the first approved coin purchase.',
    ],
    'forgot' => [
        'title' => 'Reset Access',
        'text'  => 'Recover your account securely with a time-limited password reset link.',
    ],
];
$panel = $copy[$mode] ?? $copy['member'];
?>
<aside class="auth-visual">
    <div class="brand-mark">
        <img class="brand-logo" src="<?= base_url('assets/images/logo.jpeg') ?>" alt="<?= esc($appName) ?> logo">
        <div>
            <div class="brand-name"><?= esc($appName) ?></div>
            <div class="brand-tag">NGW</div>
        </div>
    </div>

    <div class="auth-copy">
        <h1><?= esc($panel['title']) ?></h1>
        <p><?= esc($panel['text']) ?></p>
        <img class="auth-hero-logo" src="<?= base_url('assets/images/logo.jpeg') ?>" alt="<?= esc($appName) ?> logo" width="220" height="220">
    </div>
</aside>
