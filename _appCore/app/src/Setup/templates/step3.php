<h4 class="mb-3"><i class="bi bi-hdd me-2"></i>Install Type</h4>
<p class="text-muted">Choose whether to keep any existing tables or wipe the database before installing.</p>

<?php if (!empty($errors ?? [])) : ?>
<div class="alert alert-danger"><ul class="mb-0">
    <?php foreach ($errors as $err) : ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<form method="post" action="/setup">
    <input type="hidden" name="step" value="3">

    <div class="form-check mb-3">
        <input class="form-check-input" type="radio" name="install_type" id="install_keep" value="keep" checked>
        <label class="form-check-label" for="install_keep">
            <strong>Keep existing data</strong>
            <div class="text-muted small">Apply only new migrations. Choose this if the DB already has appCore tables.</div>
        </label>
    </div>

    <div class="form-check mb-4">
        <input class="form-check-input" type="radio" name="install_type" id="install_clean" value="clean">
        <label class="form-check-label" for="install_clean">
            <strong>Clean install</strong>
            <div class="text-muted small">Drop every table in this database, then install a fresh schema.</div>
        </label>
    </div>

    <div class="d-flex justify-content-between">
        <a href="/setup?step=2" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <button type="submit" class="btn btn-primary">Continue <i class="bi bi-arrow-right ms-1"></i></button>
    </div>
</form>
