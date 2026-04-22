<h4 class="mb-3"><i class="bi bi-sliders me-2"></i>Install Type</h4>
<p class="text-muted">Choose how ScoutKeeper should set up the schema in the database you just connected to.</p>

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
$status = $wizard->getDatabaseStatus();
$prev = $sessionData['install_type'] ?? 'keep';
?>

<?php if (!$status['connected']) : ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Could not re-connect to the database: <?= htmlspecialchars($status['error'] ?? 'unknown error') ?>.
    <a href="/setup?step=2">Go back to step 2</a> and check your credentials.
</div>
<?php elseif (!$status['is_empty']) : ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <strong>This database is not empty</strong> — it already contains
    <?= (int) $status['table_count'] ?> table<?= $status['table_count'] === 1 ? '' : 's' ?>.
    Choosing <em>Clean install</em> or <em>Demo install</em> below will drop
    every existing table before installing.
</div>
<?php else : ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>
    The database is empty.
</div>
<?php endif; ?>

<form method="post" action="/setup">
    <input type="hidden" name="step" value="3">

    <div class="form-check mb-3 p-3 border rounded">
        <input class="form-check-input" type="radio" name="install_type" value="keep"
               id="install_keep" <?= $prev === 'keep' ? 'checked' : '' ?>>
        <label class="form-check-label" for="install_keep">
            <strong>Install without changing the database</strong> <span class="badge bg-secondary ms-1">default</span>
            <div class="form-text">
                Leaves existing tables alone and only applies pending migrations.
                Safe for re-running setup or installing over a compatible existing schema.
            </div>
        </label>
    </div>

    <div class="form-check mb-3 p-3 border rounded">
        <input class="form-check-input" type="radio" name="install_type" value="clean"
               id="install_clean" <?= $prev === 'clean' ? 'checked' : '' ?>>
        <label class="form-check-label" for="install_clean">
            <strong>Clean install</strong>
            <div class="form-text text-danger">
                Drops every table in this database, then installs fresh.
                <u>All existing data will be lost.</u>
            </div>
        </label>
    </div>

    <div class="form-check mb-3 p-3 border rounded">
        <input class="form-check-input" type="radio" name="install_type" value="demo"
               id="install_demo" <?= $prev === 'demo' ? 'checked' : '' ?>>
        <label class="form-check-label" for="install_demo">
            <strong>Install with demo data</strong>
            <div class="form-text text-danger">
                Drops every table, installs fresh, and seeds the demo
                <em>Scout Association of Filfla</em> organisation (~30,000 members).
                Takes 2–5 minutes. <u>All existing data will be lost.</u>
            </div>
        </label>
    </div>

    <div class="d-flex justify-content-between">
        <a href="/setup?step=2" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <button type="submit" class="btn btn-primary" <?= $status['connected'] ? '' : 'disabled' ?>>
            Continue <i class="bi bi-arrow-right ms-1"></i>
        </button>
    </div>
</form>
