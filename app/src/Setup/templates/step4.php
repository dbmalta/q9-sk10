<h4 class="mb-3"><i class="bi bi-person-lock me-2"></i>Create Admin Account</h4>
<p class="text-muted">This will be the super-admin with full access to the system.</p>

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
$prev = $sessionData['admin'] ?? [];
?>

<form method="post" action="/setup">
    <input type="hidden" name="step" value="4">

    <div class="row mb-3">
        <div class="col-6">
            <label for="admin_first_name" class="form-label">First Name</label>
            <input type="text" class="form-control" id="admin_first_name" name="admin_first_name"
                   value="<?= htmlspecialchars($prev['first_name'] ?? '') ?>" required>
        </div>
        <div class="col-6">
            <label for="admin_surname" class="form-label">Surname</label>
            <input type="text" class="form-control" id="admin_surname" name="admin_surname"
                   value="<?= htmlspecialchars($prev['surname'] ?? '') ?>" required>
        </div>
    </div>

    <div class="mb-3">
        <label for="admin_email" class="form-label">Email Address</label>
        <input type="email" class="form-control" id="admin_email" name="admin_email"
               value="<?= htmlspecialchars($prev['email'] ?? '') ?>" required>
    </div>

    <div class="row mb-3">
        <div class="col-6">
            <label for="admin_password" class="form-label">Password</label>
            <input type="password" class="form-control" id="admin_password" name="admin_password"
                   minlength="10" required>
            <div class="form-text">Minimum 10 characters.</div>
        </div>
        <div class="col-6">
            <label for="admin_password_confirm" class="form-label">Confirm Password</label>
            <input type="password" class="form-control" id="admin_password_confirm"
                   name="admin_password_confirm" minlength="10" required>
        </div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="/setup?step=3" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <button type="submit" class="btn btn-primary">
            Create Admin <i class="bi bi-arrow-right ms-1"></i>
        </button>
    </div>
</form>
