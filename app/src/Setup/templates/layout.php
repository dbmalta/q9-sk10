<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ScoutKeeper &mdash; Setup (Step <?= $currentStep ?> of <?= $totalSteps ?>)</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YcnS/1WR6TpOnzVYB4AhBCEYhS5L10PbqFA3"
          crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f4f6f9; }
        .setup-card { max-width: 640px; margin: 2rem auto; }
        .step-indicator .step { width: 2rem; height: 2rem; border-radius: 50%; display: inline-flex;
            align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 600;
            background: #dee2e6; color: #6c757d; }
        .step-indicator .step.active { background: #0d6efd; color: #fff; }
        .step-indicator .step.done { background: #198754; color: #fff; }
        .step-indicator .step-line { width: 1.5rem; height: 2px; background: #dee2e6; display: inline-block;
            vertical-align: middle; margin: 0 0.15rem; }
        .step-indicator .step-line.done { background: #198754; }
        .check-ok { color: #198754; }
        .check-fail { color: #dc3545; }
    </style>
</head>
<body>
<div class="container py-4">
    <!-- Step indicator -->
    <div class="text-center mb-4 step-indicator">
        <?php for ($i = 1; $i <= $totalSteps; $i++) : ?>
            <?php if ($i > 1) :
                ?><span class="step-line <?= $i <= $currentStep ? 'done' : '' ?>"></span><?php
            endif; ?>
            <span class="step <?= $i < $currentStep ? 'done' : ($i === $currentStep ? 'active' : '') ?>">
                <?php if ($i < $currentStep) :
                    ?><i class="bi bi-check"></i><?php
                else :
                    ?><?= $i ?><?php
                endif; ?>
            </span>
        <?php endfor; ?>
    </div>

    <div class="card setup-card shadow-sm">
        <div class="card-body p-4">
            <?php
            // Render the step-specific template
            require $templateFile;
            ?>
        </div>
    </div>

    <p class="text-center text-muted small mt-3">
        ScoutKeeper &copy; <?= date('Y') ?> QuadNine Ltd &mdash; AGPL v3
    </p>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
</body>
</html>
