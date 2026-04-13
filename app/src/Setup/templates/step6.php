<h4 class="mb-3"><i class="bi bi-key me-2"></i>Encryption Key</h4>
<p class="text-muted">
    An encryption key is needed to protect sensitive data such as medical
    notes. It will be generated automatically and stored in
    <code>config/encryption.key</code>.
</p>

<?php if (!empty($errors ?? [])): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php
$keyFile = $wizard->isSetupNeeded() ? '' : ''; // just for display
$keyExists = file_exists(dirname(__DIR__, 4) . '/config/encryption.key');
?>

<?php if ($keyExists): ?>
<div class="alert alert-success">
    <i class="bi bi-check-circle me-1"></i>
    Encryption key already exists. It will not be overwritten.
</div>
<?php else: ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>
    Clicking <strong>Generate</strong> will create a new 256-bit key.
    <strong>Back it up</strong> &mdash; if lost, encrypted data cannot be recovered.
</div>
<?php endif; ?>

<form method="post" action="/setup">
    <input type="hidden" name="step" value="6">

    <div class="d-flex justify-content-between">
        <a href="/setup?step=5" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <button type="submit" class="btn btn-primary">
            <?= $keyExists ? 'Continue' : 'Generate Key' ?> <i class="bi bi-arrow-right ms-1"></i>
        </button>
    </div>
</form>
