<h4 class="mb-3"><i class="bi bi-building me-2"></i>Organisation Setup</h4>
<p class="text-muted">
    Set your Scout organisation's name, the top-level node, and the name
    for the first level in your hierarchy (e.g. "National", "Country", "Region").
</p>

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
$prev = $sessionData['org'] ?? [];
?>

<form method="post" action="/setup">
    <input type="hidden" name="step" value="3">

    <div class="mb-3">
        <label for="org_name" class="form-label">Organisation Name</label>
        <input type="text" class="form-control" id="org_name" name="org_name"
               value="<?= htmlspecialchars($prev['name'] ?? '') ?>"
               placeholder="e.g. The Scout Association of Malta" required>
        <div class="form-text">This appears in the header and emails.</div>
    </div>

    <div class="mb-3">
        <label for="root_node_name" class="form-label">Root Node Name</label>
        <input type="text" class="form-control" id="root_node_name" name="root_node_name"
               value="<?= htmlspecialchars($prev['root_node_name'] ?? '') ?>"
               placeholder="e.g. Scout Association of Malta" required>
        <div class="form-text">The top node in your organisational tree.</div>
    </div>

    <div class="mb-3">
        <label for="level_type_name" class="form-label">First Level Type</label>
        <input type="text" class="form-control" id="level_type_name" name="level_type_name"
               value="<?= htmlspecialchars($prev['level_type_name'] ?? '') ?>"
               placeholder="e.g. National" required>
        <div class="form-text">The label for the root level of the hierarchy. You can add more levels later.</div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="/setup?step=2" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <button type="submit" class="btn btn-primary">
            Continue <i class="bi bi-arrow-right ms-1"></i>
        </button>
    </div>
</form>
