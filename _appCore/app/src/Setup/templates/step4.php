<h4 class="mb-3"><i class="bi bi-building me-2"></i>Project Details</h4>
<p class="text-muted">Basic information about this installation. You can change these later in Settings.</p>

<?php if (!empty($errors ?? [])) : ?>
<div class="alert alert-danger"><ul class="mb-0">
    <?php foreach ($errors as $err) : ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<?php $prev = $sessionData['project'] ?? []; ?>

<form method="post" action="/setup">
    <input type="hidden" name="step" value="4">

    <div class="mb-3">
        <label for="project_name" class="form-label">Project name</label>
        <input type="text" class="form-control" id="project_name" name="project_name"
               value="<?= htmlspecialchars($prev['name'] ?? '') ?>"
               placeholder="e.g. My Project" required>
        <div class="form-text">Appears in the header and email subjects.</div>
    </div>

    <div class="mb-3">
        <label for="language" class="form-label">Default language</label>
        <input type="text" class="form-control" id="language" name="language"
               value="<?= htmlspecialchars($prev['language'] ?? 'en') ?>" maxlength="10">
        <div class="form-text">Two-letter code matching a file in /lang/ (e.g. "en").</div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="/setup?step=3" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <button type="submit" class="btn btn-primary">Continue <i class="bi bi-arrow-right ms-1"></i></button>
    </div>
</form>
