<h4 class="mb-3"><i class="bi bi-database me-2"></i>Database Configuration</h4>
<p class="text-muted">Enter the MySQL database credentials. The database must already exist.</p>

<?php if (!empty($errors ?? [])) : ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err) : ?>
        <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php
$prev = $sessionData['db'] ?? [];
?>

<form method="post" action="/setup" id="dbForm">
    <input type="hidden" name="step" value="2">

    <div class="row mb-3">
        <div class="col-8">
            <label for="db_host" class="form-label">Database Host</label>
            <input type="text" class="form-control" id="db_host" name="db_host"
                   value="<?= htmlspecialchars($prev['host'] ?? 'localhost') ?>" required>
        </div>
        <div class="col-4">
            <label for="db_port" class="form-label">Port</label>
            <input type="text" class="form-control" id="db_port" name="db_port"
                   value="<?= htmlspecialchars($prev['port'] ?? '3306') ?>" required>
        </div>
    </div>

    <div class="mb-3">
        <label for="db_name" class="form-label">Database Name</label>
        <input type="text" class="form-control" id="db_name" name="db_name"
               value="<?= htmlspecialchars($prev['name'] ?? '') ?>" required>
    </div>

    <div class="mb-3">
        <label for="db_user" class="form-label">Database User</label>
        <input type="text" class="form-control" id="db_user" name="db_user"
               value="<?= htmlspecialchars($prev['user'] ?? '') ?>" required>
    </div>

    <div class="mb-3">
        <label for="db_password" class="form-label">Database Password</label>
        <input type="password" class="form-control" id="db_password" name="db_password"
               value="<?= htmlspecialchars($prev['password'] ?? '') ?>">
    </div>

    <div class="d-flex justify-content-between">
        <a href="/setup?step=1" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <button type="submit" class="btn btn-primary">
            Connect &amp; Run Migrations <i class="bi bi-arrow-right ms-1"></i>
        </button>
    </div>
</form>
