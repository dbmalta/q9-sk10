<h4 class="mb-3"><i class="bi bi-database me-2"></i>Database Connection</h4>
<p class="text-muted">Enter the credentials for an empty (or existing) MySQL 8.0+ database.</p>

<?php if (!empty($errors ?? [])) : ?>
<div class="alert alert-danger"><ul class="mb-0">
    <?php foreach ($errors as $err) : ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<?php $prev = $sessionData['db'] ?? []; ?>

<form method="post" action="/setup">
    <input type="hidden" name="step" value="2">

    <div class="row mb-3">
        <div class="col-8">
            <label class="form-label" for="db_host">Host</label>
            <input type="text" name="db_host" id="db_host" class="form-control"
                   value="<?= htmlspecialchars($prev['host'] ?? 'localhost') ?>" required>
        </div>
        <div class="col-4">
            <label class="form-label" for="db_port">Port</label>
            <input type="text" name="db_port" id="db_port" class="form-control"
                   value="<?= htmlspecialchars($prev['port'] ?? '3306') ?>">
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label" for="db_name">Database name</label>
        <input type="text" name="db_name" id="db_name" class="form-control"
               value="<?= htmlspecialchars($prev['name'] ?? '') ?>" required>
    </div>

    <div class="mb-3">
        <label class="form-label" for="db_user">User</label>
        <input type="text" name="db_user" id="db_user" class="form-control"
               value="<?= htmlspecialchars($prev['user'] ?? '') ?>" required>
    </div>

    <div class="mb-3">
        <label class="form-label" for="db_password">Password</label>
        <input type="password" name="db_password" id="db_password" class="form-control"
               value="<?= htmlspecialchars($prev['password'] ?? '') ?>">
    </div>

    <div class="d-flex justify-content-between">
        <a href="/setup?step=1" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <button type="submit" class="btn btn-primary">
            Test & Continue <i class="bi bi-arrow-right ms-1"></i>
        </button>
    </div>
</form>
