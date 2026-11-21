<!doctype html>
<html lang="en">
<body>
    <p>Hello,</p>
    <p>Verify your email address to activate your <?= esc($appName) ?> account.</p>
    <p><a href="<?= esc($verifyUrl) ?>">Verify email address</a></p>
    <p>This link expires in <?= esc((string) ceil($ttl / 60)) ?> minutes. If you did not create this account, ignore this email.</p>
</body>
</html>
