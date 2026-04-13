<h4 class="mb-3"><i class="bi bi-clipboard-check me-2"></i>Pre-flight Checks</h4>
<p class="text-muted">ScoutKeeper requires the following to run correctly.</p>

<?php
$checks = $wizard->getPrerequisiteChecks();
$allPassed = true;
foreach ($checks as $check) {
    if (!$check['passed']) {
        $allPassed = false;
    }
}
?>

<table class="table table-sm mb-4">
    <thead>
        <tr><th>Requirement</th><th>Status</th><th>Detail</th></tr>
    </thead>
    <tbody>
        <?php foreach ($checks as $check) : ?>
        <tr>
            <td><?= htmlspecialchars($check['label']) ?></td>
            <td>
                <?php if ($check['passed']) : ?>
                    <i class="bi bi-check-circle-fill check-ok"></i>
                <?php else : ?>
                    <i class="bi bi-x-circle-fill check-fail"></i>
                <?php endif; ?>
            </td>
            <td class="small text-muted"><?= htmlspecialchars($check['detail']) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if (!empty($errors ?? [])) : ?>
<div class="alert alert-danger">
    <strong>Cannot proceed.</strong> Please fix the issues above and reload this page.
</div>
<?php endif; ?>

<form method="post" action="/setup">
    <input type="hidden" name="step" value="1">
    <div class="d-flex justify-content-end">
        <button type="submit" class="btn btn-primary" <?= $allPassed ? '' : 'disabled' ?>>
            Continue <i class="bi bi-arrow-right ms-1"></i>
        </button>
    </div>
</form>
