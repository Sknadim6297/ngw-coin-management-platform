<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-store">
    <title>Opening Member View · <?= esc(config('Platform')->appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= base_url('assets/css/app.css') ?>" rel="stylesheet">
</head>
<body class="bg-dark text-light">
<main class="container py-5" style="max-width: 520px;">
    <div class="card-premium text-center p-4">
        <div class="spinner-border text-warning mb-3" role="status" aria-hidden="true"></div>
        <h1 class="h4">Opening support view</h1>
        <p class="text-secondary mb-1">Member <?= esc($grant['target_member_id']) ?> · <?= esc($grant['target_name']) ?></p>
        <p class="small text-secondary">Read-only · Original admin session stays active in your other tab.</p>
    </div>
</main>
<form id="sv-start" method="post" action="<?= site_url('support-view/start') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="grant_token" value="<?= esc($grant['token']) ?>">
</form>
<script>
document.getElementById('sv-start').submit();
</script>
</body>
</html>
