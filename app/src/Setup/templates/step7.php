<h4 class="mb-3"><i class="bi bi-check2-all me-2"></i>Finish Setup</h4>

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
$sd = $_SESSION['setup_data'] ?? [];
$finished = !$wizard->isSetupNeeded() && empty($errors ?? []) && ($_SESSION['setup_step'] ?? 0) >= 7;
// If we just arrived at step 7 (GET), show summary and a Finish button.
// If we already wrote config (POST success), show the success message.
?>

<?php if (isset($justFinished) && $justFinished): ?>
    <!-- Post-finish success state -->
    <div class="alert alert-success">
        <i class="bi bi-check-circle me-1"></i>
        <strong>Setup complete!</strong> Your configuration has been saved.
    </div>

    <p>You can now log in with the admin account you created.</p>

    <div class="text-center mt-4">
        <a href="/login" class="btn btn-primary btn-lg">
            <i class="bi bi-box-arrow-in-right me-1"></i> Go to Login
        </a>
    </div>

<?php else: ?>
    <!-- Pre-finish summary -->
    <p class="text-muted">Review your settings before finalising the installation.</p>

    <table class="table table-sm">
        <tbody>
            <tr>
                <th class="text-muted" style="width:40%">Database</th>
                <td>
                    <?= htmlspecialchars(($sd['db']['user'] ?? '?') . '@' . ($sd['db']['host'] ?? '?') . '/' . ($sd['db']['name'] ?? '?')) ?>
                </td>
            </tr>
            <tr>
                <th class="text-muted">Organisation</th>
                <td><?= htmlspecialchars($sd['org']['name'] ?? '(not set)') ?></td>
            </tr>
            <tr>
                <th class="text-muted">Root Node</th>
                <td><?= htmlspecialchars($sd['org']['root_node_name'] ?? '(not set)') ?></td>
            </tr>
            <tr>
                <th class="text-muted">Admin Email</th>
                <td><?= htmlspecialchars($sd['admin']['email'] ?? '(not set)') ?></td>
            </tr>
            <tr>
                <th class="text-muted">SMTP</th>
                <td>
                    <?php if (!empty($sd['smtp']['host'])): ?>
                        <?= htmlspecialchars($sd['smtp']['host'] . ':' . $sd['smtp']['port']) ?>
                    <?php else: ?>
                        <span class="text-muted">Skipped (can configure later)</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th class="text-muted">Encryption Key</th>
                <td>
                    <?php if (file_exists(dirname(__DIR__, 4) . '/config/encryption.key')): ?>
                        <span class="text-success"><i class="bi bi-check-circle"></i> Generated</span>
                    <?php else: ?>
                        <span class="text-danger"><i class="bi bi-x-circle"></i> Missing</span>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>

    <form method="post" action="/setup">
        <input type="hidden" name="step" value="7">

        <div class="d-flex justify-content-between">
            <a href="/setup?step=6" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-lg me-1"></i> Finish &amp; Write Config
            </button>
        </div>
    </form>
<?php endif; ?>
