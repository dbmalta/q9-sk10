<?php $justFinished = $justFinished ?? false; ?>

<?php if ($justFinished) : ?>
<h4 class="mb-3 text-success"><i class="bi bi-check2-circle me-2"></i>Setup Complete</h4>
<p>appCore has been installed. The configuration file has been written to <code>config/config.php</code>.</p>
<p class="mb-4">You can now sign in with the administrator account you just created.</p>
<a href="/login" class="btn btn-primary">
    Go to login <i class="bi bi-box-arrow-in-right ms-1"></i>
</a>
<?php else : ?>
<h4 class="mb-3"><i class="bi bi-rocket-takeoff me-2"></i>Finish Setup</h4>
<p class="text-muted">
    Clicking Finish will write <code>config/config.php</code>, save final settings,
    and clear the setup session.
</p>

<?php if (!empty($errors ?? [])) : ?>
<div class="alert alert-danger"><ul class="mb-0">
    <?php foreach ($errors as $err) : ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<form method="post" action="/setup">
    <input type="hidden" name="step" value="8">
    <div class="d-flex justify-content-between">
        <a href="/setup?step=7" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <button type="submit" class="btn btn-success">
            Finish setup <i class="bi bi-check-lg ms-1"></i>
        </button>
    </div>
</form>
<?php endif; ?>
