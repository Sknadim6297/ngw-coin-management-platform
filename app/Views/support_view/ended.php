<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-store">
    <title>Support View Ended · <?= esc(config('Platform')->appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= base_url('assets/css/app.css') ?>" rel="stylesheet">
</head>
<body class="bg-dark text-light">
<main class="container py-5" style="max-width: 560px;">
    <div class="card-premium p-4">
        <h1 class="h4 mb-3">Member support view ended</h1>
        <?= view('components/alerts') ?>
        <p class="text-secondary">The isolated support-view session was revoked. Your Admin Panel tab (if still open) remains separately authenticated.</p>
        <div class="d-flex flex-wrap gap-2 mt-3">
            <a class="btn btn-gold" href="<?= site_url('admin/members') ?>">Back to Members</a>
            <a class="btn btn-outline-gold" href="<?= site_url('admin/dashboard') ?>">Admin Dashboard</a>
            <button class="btn btn-outline-secondary" type="button" onclick="window.close()">Close Tab</button>
        </div>
    </div>
</main>
</body>
</html>
