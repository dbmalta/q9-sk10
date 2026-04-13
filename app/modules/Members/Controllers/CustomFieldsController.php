<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Members\Services\CustomFieldService;

/**
 * Custom field definitions management controller.
 *
 * Admin interface for creating, editing, reordering, and deactivating
 * the custom fields that appear on member records.
 */
class CustomFieldsController extends Controller
{
    private CustomFieldService $fieldService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->fieldService = new CustomFieldService($app->getDb());
    }

    /**
     * GET /admin/custom-fields — list all field definitions.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('custom_fields.write');
        if ($guard !== null) {
            return $guard;
        }

        $showInactive = $request->getParam('show_inactive', '') === '1';
        $definitions = $this->fieldService->getDefinitions($showInactive ? null : true);

        return $this->render('@members/custom_fields/index.html.twig', [
            'definitions' => $definitions,
            'show_inactive' => $showInactive,
            'field_types' => CustomFieldService::FIELD_TYPES,
            'breadcrumbs' => [
                ['label' => 'nav.custom_fields', 'url' => '/admin/custom-fields'],
            ],
        ]);
    }

    /**
     * GET /admin/custom-fields/create — show create form.
     */
    public function create(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('custom_fields.write');
        if ($guard !== null) {
            return $guard;
        }

        return $this->render('@members/custom_fields/form.html.twig', [
            'definition' => null,
            'field_types' => CustomFieldService::FIELD_TYPES,
            'breadcrumbs' => [
                ['label' => 'nav.custom_fields', 'url' => '/admin/custom-fields'],
                ['label' => 'custom_fields.add_field'],
            ],
        ]);
    }

    /**
     * POST /admin/custom-fields/create — store new field definition.
     */
    public function store(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('custom_fields.write');
        if ($guard !== null) {
            return $guard;
        }
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) {
            return $csrfGuard;
        }

        $data = $this->extractFieldData($request);

        try {
            $this->fieldService->create($data);
            $this->flash('success', 'flash.saved');
            return Response::redirect('/admin/custom-fields');
        } catch (\InvalidArgumentException $e) {
            return $this->render('@members/custom_fields/form.html.twig', [
                'definition' => null,
                'field_types' => CustomFieldService::FIELD_TYPES,
                'error' => $e->getMessage(),
                'old' => $data,
                'breadcrumbs' => [
                    ['label' => 'nav.custom_fields', 'url' => '/admin/custom-fields'],
                    ['label' => 'custom_fields.add_field'],
                ],
            ]);
        }
    }

    /**
     * GET /admin/custom-fields/{id}/edit — show edit form.
     */
    public function edit(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('custom_fields.write');
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) $vars['id'];
        $definition = $this->fieldService->getById($id);
        if (!$definition) {
            return Response::redirect('/admin/custom-fields');
        }

        // Parse validation_rules for form display
        if (!empty($definition['validation_rules']) && is_string($definition['validation_rules'])) {
            $definition['validation_rules'] = json_decode($definition['validation_rules'], true) ?? [];
        }

        return $this->render('@members/custom_fields/form.html.twig', [
            'definition' => $definition,
            'field_types' => CustomFieldService::FIELD_TYPES,
            'breadcrumbs' => [
                ['label' => 'nav.custom_fields', 'url' => '/admin/custom-fields'],
                ['label' => 'custom_fields.edit_field'],
            ],
        ]);
    }

    /**
     * POST /admin/custom-fields/{id}/edit — update field definition.
     */
    public function update(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('custom_fields.write');
        if ($guard !== null) {
            return $guard;
        }
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) {
            return $csrfGuard;
        }

        $id = (int) $vars['id'];
        $data = $this->extractFieldData($request);

        try {
            $this->fieldService->update($id, $data);
            $this->flash('success', 'flash.saved');
            return Response::redirect('/admin/custom-fields');
        } catch (\InvalidArgumentException $e) {
            $definition = $this->fieldService->getById($id);
            return $this->render('@members/custom_fields/form.html.twig', [
                'definition' => $definition,
                'field_types' => CustomFieldService::FIELD_TYPES,
                'error' => $e->getMessage(),
                'old' => $data,
                'breadcrumbs' => [
                    ['label' => 'nav.custom_fields', 'url' => '/admin/custom-fields'],
                    ['label' => 'custom_fields.edit_field'],
                ],
            ]);
        }
    }

    /**
     * POST /admin/custom-fields/{id}/deactivate — soft-delete a field.
     */
    public function deactivate(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('custom_fields.write');
        if ($guard !== null) {
            return $guard;
        }
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) {
            return $csrfGuard;
        }

        $id = (int) $vars['id'];
        $this->fieldService->deactivate($id);
        $this->flash('success', 'flash.saved');
        return Response::redirect('/admin/custom-fields');
    }

    /**
     * POST /admin/custom-fields/{id}/activate — re-enable a deactivated field.
     */
    public function activate(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('custom_fields.write');
        if ($guard !== null) {
            return $guard;
        }
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) {
            return $csrfGuard;
        }

        $id = (int) $vars['id'];
        $this->fieldService->activate($id);
        $this->flash('success', 'flash.saved');
        return Response::redirect('/admin/custom-fields');
    }

    /**
     * POST /admin/custom-fields/reorder — HTMX reorder endpoint.
     *
     * Expects POST body: ids[]=3&ids[]=1&ids[]=2
     */
    public function reorder(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('custom_fields.write');
        if ($guard !== null) {
            return $guard;
        }
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) {
            return $csrfGuard;
        }

        $ids = $request->getParam('ids', []);
        if (!is_array($ids)) {
            return Response::json(['error' => 'Invalid request'], 400);
        }

        $this->fieldService->reorder(array_map('intval', $ids));
        return Response::json(['ok' => true]);
    }

    /**
     * Extract field definition data from form request.
     *
     * @param Request $request
     * @return array
     */
    private function extractFieldData(Request $request): array
    {
        $data = [
            'field_key' => trim((string) $request->getParam('field_key', '')),
            'field_type' => trim((string) $request->getParam('field_type', '')),
            'label' => trim((string) $request->getParam('label', '')),
            'description' => trim((string) $request->getParam('description', '')) ?: null,
            'is_required' => $request->getParam('is_required') ? 1 : 0,
            'display_group' => trim((string) $request->getParam('display_group', 'additional')),
        ];

        // Build validation_rules from type-specific fields
        $rules = [];
        $type = $data['field_type'];

        if ($type === 'dropdown') {
            $optionsRaw = trim((string) $request->getParam('dropdown_options', ''));
            if ($optionsRaw !== '') {
                $rules['dropdown_options'] = array_values(
                    array_filter(
                        array_map('trim', explode("\n", $optionsRaw)),
                        fn($o) => $o !== ''
                    )
                );
            }
        }

        if ($type === 'number') {
            $min = $request->getParam('min', '');
            $max = $request->getParam('max', '');
            if ($min !== '' && $min !== null) {
                $rules['min'] = (float) $min;
            }
            if ($max !== '' && $max !== null) {
                $rules['max'] = (float) $max;
            }
        }

        $maxLen = $request->getParam('max_length', '');
        if ($maxLen !== '' && $maxLen !== null && in_array($type, ['short_text', 'long_text'])) {
            $rules['max_length'] = (int) $maxLen;
        }

        if (!empty($rules)) {
            $data['validation_rules'] = $rules;
        }

        return $data;
    }
}
