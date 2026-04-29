<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Services\LanguageService;

/**
 * Language management controller.
 *
 * List, upload, activate/deactivate languages, manage translation
 * string overrides, set defaults, and export the master file.
 */
class LanguageController extends Controller
{
    private LanguageService $service;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->service = new LanguageService($app->getDb(), ROOT_PATH . '/lang');
    }

    /**
     * GET /admin/languages — list languages.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.languages');
        if ($guard !== null) {
            return $guard;
        }

        // Reconcile DB with the filesystem before listing.
        // This auto-heals after system updates: adds newly-deployed language files
        // to the DB, refreshes completion percentages, and removes orphan records
        // (e.g. fr-FR left behind when the bundled file was renamed to fr.json).
        $this->service->syncFromFilesystem();

        $languages = $this->service->getLanguages();

        // Calculate completion for each language
        foreach ($languages as &$lang) {
            $lang['completion'] = $this->service->calculateCompletion($lang['code']);
        }

        return $this->render('@admin/admin/languages/index.html.twig', [
            'languages' => $languages,
            'breadcrumbs' => [
                ['label' => $this->t('nav.admin'), 'url' => '/admin/dashboard'],
                ['label' => $this->t('languages.title')],
            ],
        ]);
    }

    /**
     * GET /admin/languages/upload — show upload form.
     */
    public function upload(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.languages');
        if ($guard !== null) {
            return $guard;
        }

        return $this->render('@admin/admin/languages/upload.html.twig', [
            'breadcrumbs' => [
                ['label' => $this->t('nav.admin'), 'url' => '/admin/dashboard'],
                ['label' => $this->t('languages.title'), 'url' => '/admin/languages'],
                ['label' => $this->t('languages.upload')],
            ],
        ]);
    }

    /**
     * POST /admin/languages/upload — process uploaded language.
     */
    public function processUpload(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.languages');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $code       = trim((string) $request->getParam('code', ''));
        $name       = trim((string) $request->getParam('name', ''));
        $nativeName = trim((string) $request->getParam('native_name', ''));

        // Validate required fields
        if ($code === '' || $name === '' || $nativeName === '') {
            $this->flash('error', $this->t('languages.fields_required'));
            return $this->redirect('/admin/languages/upload');
        }

        // Read uploaded file
        $fileContent = file_get_contents($_FILES['file']['tmp_name'] ?? '');
        if ($fileContent === false || $fileContent === '') {
            $this->flash('error', $this->t('languages.file_required'));
            return $this->redirect('/admin/languages/upload');
        }

        // Parse JSON
        $translations = json_decode($fileContent, true);
        if (!is_array($translations)) {
            $this->flash('error', $this->t('languages.invalid_json'));
            return $this->redirect('/admin/languages/upload');
        }

        try {
            $this->service->uploadLanguage($code, $name, $nativeName, $translations);
            $this->flash('success', $this->t('languages.uploaded'));
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/admin/languages/upload');
        }

        return $this->redirect('/admin/languages');
    }

    /**
     * POST /admin/languages/{code}/default — set as default language.
     */
    public function setDefault(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.languages');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $code = (string) ($vars['code'] ?? '');

        try {
            $this->service->setDefault($code);
            $this->flash('success', $this->t('languages.default_set'));
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/languages');
    }

    /**
     * POST /admin/languages/{code}/activate — activate a language.
     */
    public function activate(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.languages');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $code = (string) ($vars['code'] ?? '');
        $this->service->activate($code);
        $this->flash('success', $this->t('languages.activated'));

        return $this->redirect('/admin/languages');
    }

    /**
     * POST /admin/languages/{code}/deactivate — deactivate a language.
     */
    public function deactivate(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.languages');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $code = (string) ($vars['code'] ?? '');

        try {
            $this->service->deactivate($code);
            $this->flash('success', $this->t('languages.deactivated'));
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/languages');
    }

    /**
     * GET /admin/languages/{code}/strings — view/edit translation strings.
     */
    public function strings(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.languages');
        if ($guard !== null) {
            return $guard;
        }

        $code = (string) ($vars['code'] ?? '');
        $strings = $this->service->getStringsForLanguage($code);

        // Find language name for display
        $languages = $this->service->getLanguages();
        $languageName = $code;
        foreach ($languages as $lang) {
            if ($lang['code'] === $code) {
                $languageName = $lang['name'] . ' (' . $lang['native_name'] . ')';
                break;
            }
        }

        return $this->render('@admin/admin/languages/strings.html.twig', [
            'code'          => $code,
            'language_name' => $languageName,
            'strings'       => $strings,
            'breadcrumbs'   => [
                ['label' => $this->t('nav.admin'), 'url' => '/admin/dashboard'],
                ['label' => $this->t('languages.title'), 'url' => '/admin/languages'],
                ['label' => $languageName],
            ],
        ]);
    }

    /**
     * POST /admin/languages/{code}/overrides — save a string override.
     */
    public function saveOverride(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.languages');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $code  = (string) ($vars['code'] ?? '');
        $key   = trim((string) $request->getParam('key', ''));
        $value = trim((string) $request->getParam('value', ''));

        if ($key === '' || $value === '') {
            $this->flash('error', $this->t('languages.override_required'));
            return $this->redirect('/admin/languages/' . $code . '/strings');
        }

        $this->service->setOverride($code, $key, $value);
        $this->flash('success', $this->t('flash.saved'));

        return $this->redirect('/admin/languages/' . $code . '/strings');
    }

    /**
     * POST /admin/languages/{code}/overrides/clear — clear a string override.
     */
    public function clearOverride(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.languages');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $code = (string) ($vars['code'] ?? '');
        $key  = trim((string) $request->getParam('key', ''));

        if ($key === '') {
            $this->flash('error', $this->t('languages.key_required'));
            return $this->redirect('/admin/languages/' . $code . '/strings');
        }

        $this->service->clearOverride($code, $key);
        $this->flash('success', $this->t('languages.override_cleared'));

        return $this->redirect('/admin/languages/' . $code . '/strings');
    }

    /**
     * POST /language/switch — set the current user's UI language for this session.
     *
     * Open to all visitors (including unauthenticated) so the switcher works
     * on the login screen. Validates the code against active languages and
     * only redirects to relative URLs to avoid open-redirect abuse.
     */
    public function switchLanguage(Request $request, array $vars): Response
    {
        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $code = trim((string) $request->getParam('lang_code', $request->getParam('code', '')));
        $redirectTo = (string) $request->getParam('redirect_to', '/');
        // Only accept relative paths to prevent open-redirect
        if ($redirectTo === '' || $redirectTo[0] !== '/' || str_starts_with($redirectTo, '//')) {
            $redirectTo = '/';
        }

        if ($code === '' || !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $code)) {
            return $this->redirect($redirectTo);
        }

        // Confirm the language is available and active
        $available = $this->app->getI18n()->getAvailableLanguages();
        $valid = false;
        foreach ($available as $lang) {
            if ($lang['code'] === $code && $lang['is_active']) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            return $this->redirect($redirectTo);
        }

        $this->app->getSession()->set('language', $code);
        return $this->redirect($redirectTo);
    }

    /**
     * GET /admin/languages/export-master — download en.json master file.
     */
    public function exportMaster(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.languages');
        if ($guard !== null) {
            return $guard;
        }

        $json = $this->service->exportMasterFile();

        return (new Response(200, $json))
            ->setHeader('Content-Type', 'application/json; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="en.json"')
            ->setHeader('Cache-Control', 'no-cache, must-revalidate');
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}
