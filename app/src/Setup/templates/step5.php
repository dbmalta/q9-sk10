<h4 class="mb-3"><i class="bi bi-envelope me-2"></i>Email (SMTP) Settings</h4>
<p class="text-muted">
    Configure outgoing email. You can skip this and set it up later in
    the admin panel.
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
$prev = $sessionData['smtp'] ?? [];
?>

<form method="post" action="/setup">
    <input type="hidden" name="step" value="5">

    <div class="row mb-3">
        <div class="col-8">
            <label for="smtp_host" class="form-label">SMTP Host</label>
            <input type="text" class="form-control" id="smtp_host" name="smtp_host"
                   value="<?= htmlspecialchars($prev['host'] ?? '') ?>"
                   placeholder="e.g. smtp.gmail.com">
        </div>
        <div class="col-4">
            <label for="smtp_port" class="form-label">Port</label>
            <input type="number" class="form-control" id="smtp_port" name="smtp_port"
                   value="<?= htmlspecialchars((string)($prev['port'] ?? '587')) ?>">
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-6">
            <label for="smtp_username" class="form-label">Username</label>
            <input type="text" class="form-control" id="smtp_username" name="smtp_username"
                   value="<?= htmlspecialchars($prev['username'] ?? '') ?>">
        </div>
        <div class="col-6">
            <label for="smtp_password" class="form-label">Password</label>
            <input type="password" class="form-control" id="smtp_password" name="smtp_password"
                   value="<?= htmlspecialchars($prev['password'] ?? '') ?>">
        </div>
    </div>

    <div class="mb-3">
        <label for="smtp_encryption" class="form-label">Encryption</label>
        <select class="form-select" id="smtp_encryption" name="smtp_encryption">
            <option value="tls" <?= ($prev['encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (recommended)</option>
            <option value="ssl" <?= ($prev['encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
            <option value=""    <?= ($prev['encryption'] ?? 'tls') === '' ? 'selected' : '' ?>>None</option>
        </select>
    </div>

    <div class="row mb-3">
        <div class="col-6">
            <label for="smtp_from_email" class="form-label">From Email</label>
            <input type="email" class="form-control" id="smtp_from_email" name="smtp_from_email"
                   value="<?= htmlspecialchars($prev['from_email'] ?? '') ?>"
                   placeholder="noreply@yourdomain.com">
        </div>
        <div class="col-6">
            <label for="smtp_from_name" class="form-label">From Name</label>
            <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name"
                   value="<?= htmlspecialchars($prev['from_name'] ?? '') ?>"
                   placeholder="ScoutKeeper">
        </div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="/setup?step=4" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <div>
            <button type="submit" name="skip_smtp" value="1" class="btn btn-outline-secondary me-2">
                Skip for Now
            </button>
            <button type="submit" class="btn btn-primary">
                Save &amp; Continue <i class="bi bi-arrow-right ms-1"></i>
            </button>
        </div>
    </div>
</form>
