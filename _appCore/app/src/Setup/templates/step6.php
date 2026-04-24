<h4 class="mb-3"><i class="bi bi-envelope me-2"></i>SMTP (optional)</h4>
<p class="text-muted">Used for password resets, notifications, and outbound email. You can skip this and configure it later.</p>

<?php if (!empty($errors ?? [])) : ?>
<div class="alert alert-danger"><ul class="mb-0">
    <?php foreach ($errors as $err) : ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<?php $prev = $sessionData['smtp'] ?? []; ?>

<form method="post" action="/setup">
    <input type="hidden" name="step" value="6">

    <div class="mb-3">
        <label for="smtp_host" class="form-label">SMTP host</label>
        <input type="text" class="form-control" id="smtp_host" name="smtp_host"
               value="<?= htmlspecialchars($prev['host'] ?? '') ?>" placeholder="smtp.example.com">
    </div>

    <div class="row mb-3">
        <div class="col-4">
            <label for="smtp_port" class="form-label">Port</label>
            <input type="number" class="form-control" id="smtp_port" name="smtp_port"
                   value="<?= htmlspecialchars((string) ($prev['port'] ?? 587)) ?>">
        </div>
        <div class="col-8">
            <label for="smtp_encryption" class="form-label">Encryption</label>
            <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                <option value="tls" <?= (($prev['encryption'] ?? 'tls') === 'tls') ? 'selected' : '' ?>>TLS</option>
                <option value="ssl" <?= (($prev['encryption'] ?? '') === 'ssl') ? 'selected' : '' ?>>SSL</option>
                <option value="none" <?= (($prev['encryption'] ?? '') === 'none') ? 'selected' : '' ?>>None</option>
            </select>
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
        <label for="smtp_from_email" class="form-label">From email</label>
        <input type="email" class="form-control" id="smtp_from_email" name="smtp_from_email"
               value="<?= htmlspecialchars($prev['from_email'] ?? '') ?>">
    </div>

    <div class="mb-3">
        <label for="smtp_from_name" class="form-label">From name</label>
        <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name"
               value="<?= htmlspecialchars($prev['from_name'] ?? '') ?>">
    </div>

    <div class="d-flex justify-content-between align-items-center">
        <a href="/setup?step=5" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <div>
            <button type="submit" name="skip_smtp" value="1" class="btn btn-outline-secondary me-2">Skip</button>
            <button type="submit" class="btn btn-primary">Continue <i class="bi bi-arrow-right ms-1"></i></button>
        </div>
    </div>
</form>
