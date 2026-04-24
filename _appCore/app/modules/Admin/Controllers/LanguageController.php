<?php

declare(strict_types=1);

namespace AppCore\Modules\Admin\Controllers;

use AppCore\Core\Application;
use AppCore\Core\Controller;
use AppCore\Core\Request;
use AppCore\Core\Response;
use AppCore\Modules\Admin\Services\LanguageService;

class LanguageController extends Controller
{
    private LanguageService $languages;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->languages = new LanguageService($app->getDb(), $app->getI18n());
    }

    public function switchLanguage(Request $request, array $vars): Response
    {
        $code = (string) $this->getParam('code', '');
        $redirectTo = (string) $this->getParam('redirect_to', '/');

        if ($code !== '') {
            $this->app->getSession()->set('language', $code);
        }
        return $this->redirect($redirectTo);
    }

    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.languages');
        if ($guard !== null) {
            return $guard;
        }
        return $this->render('@admin/admin/languages.html.twig', [
            'languages' => $this->languages->list(),
        ]);
    }

    public function strings(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.languages');
        if ($guard !== null) {
            return $guard;
        }

        $code = (string) $vars['code'];
        return $this->render('@admin/admin/language_strings.html.twig', [
            'code'    => $code,
            'strings' => $this->languages->getStrings($code),
        ]);
    }

    public function saveOverride(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.languages');
        if ($guard !== null) {
            return $guard;
        }

        $code = (string) $vars['code'];
        $key  = (string) $this->getParam('key', '');
        $value = (string) $this->getParam('value', '');
        $this->languages->saveOverride($code, $key, $value !== '' ? $value : null);

        $this->flash('success', $this->t('languages.override_saved'));
        return $this->redirect('/admin/languages/' . urlencode($code) . '/strings');
    }

    public function activate(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.languages');
        if ($guard !== null) {
            return $guard;
        }
        $this->languages->activate((string) $vars['code']);
        return $this->redirect('/admin/languages');
    }

    public function deactivate(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.languages');
        if ($guard !== null) {
            return $guard;
        }
        $this->languages->deactivate((string) $vars['code']);
        return $this->redirect('/admin/languages');
    }
}
