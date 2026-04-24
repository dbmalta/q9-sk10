<h4 class="mb-3"><i class="bi bi-key me-2"></i>Encryption Key</h4>
<p class="text-muted">appCore will now generate a 32-byte encryption key and write it to <code>config/encryption.key</code> with 0600 permissions. This key is used for encrypting sensitive fields at rest.</p>

<?php if (!empty($errors ?? [])) : ?>
<div class="alert alert-danger"><ul class="mb-0">
    <?php foreach ($errors as $err) : ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>

<div class="alert alert-warning">
    <strong>Back up this file.</strong> Data encrypted with this key becomes unrecoverable if the key is lost.
    There is no key-rotation facility built in.
</div>

<form method="post" action="/setup">
    <input type="hidden" name="step" value="7">
    <div class="d-flex justify-content-between">
        <a href="/setup?step=6" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
        <button type="submit" class="btn btn-primary">Generate key & continue <i class="bi bi-arrow-right ms-1"></i></button>
    </div>
</form>
