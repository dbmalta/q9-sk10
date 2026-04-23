<?php

declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Core\ViewContext;
use App\Core\ViewContextService;

/**
 * Handles the two endpoints that mutate the current view context:
 *   POST /context/mode   — body: mode=admin|member
 *   POST /context/scope  — body: node_id=<int>|all
 *
 * Both are CSRF-protected (enforced globally by Application) and redirect
 * back to redirect_to after persisting. Invalid submissions log an audit
 * entry and flash an error.
 */
class ViewContextController extends Controller
{
    public function setMode(): Response
    {
        if (($r = $this->requireAuth()) !== null) {
            return $r;
        }

        $user = $this->app->getSession()->getUser();
        $mode = (string) $this->getParam('mode', '');
        $service = new ViewContextService($this->app->getDb(), $this->app->getSession(), $this->app->getI18n());

        try {
            $service->setMode((int) $user['id'], $mode);
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $this->app->getI18n()->t('view.mismatch.body'));
            return $this->redirect($this->safeRedirectTarget());
        }

        $this->flash('view_announce', $this->app->getI18n()->t('view.mode_changed_announcement'));

        // Always land on / after a mode switch. The HomeController resolves
        // the correct dashboard for the new mode (admin dashboard vs. member
        // profile), avoiding "mode=member on an admin-only URL" states.
        return $this->redirect('/');
    }

    public function setScope(): Response
    {
        if (($r = $this->requireAuth()) !== null) {
            return $r;
        }

        $user = $this->app->getSession()->getUser();
        $raw  = $this->getParam('node_id', null);
        $nodeId = ($raw === 'all' || $raw === null || $raw === '') ? null : (int) $raw;

        $service = new ViewContextService($this->app->getDb(), $this->app->getSession(), $this->app->getI18n());
        try {
            $service->setScope((int) $user['id'], $nodeId);
        } catch (\InvalidArgumentException $e) {
            $this->auditInvalidScope((int) $user['id'], $raw);
            $this->flash('error', $this->app->getI18n()->t('view.mismatch.body'));
            return $this->redirect($this->safeRedirectTarget());
        }

        $this->flash('view_announce', $this->app->getI18n()->t('view.scope_changed_announcement'));
        return $this->redirect($this->safeRedirectTarget());
    }

    /**
     * Only honour same-origin relative paths for redirect_to.
     */
    private function safeRedirectTarget(): string
    {
        $candidate = (string) $this->getParam('redirect_to', '/');
        if ($candidate === '' || $candidate[0] !== '/' || str_starts_with($candidate, '//')) {
            return '/';
        }
        return $candidate;
    }

    private function auditInvalidScope(int $userId, mixed $rawNodeId): void
    {
        try {
            $this->app->getDb()->insert('audit_log', [
                'user_id'     => $userId,
                'action'      => 'view_context.invalid_scope',
                'entity_type' => 'scope',
                'entity_id'   => null,
                'new_values'  => json_encode(['submitted' => $rawNodeId], JSON_UNESCAPED_SLASHES),
                'ip_address'  => $this->app->getRequest()->getClientIp(),
                'view_mode'   => ViewContext::MODE_ADMIN,
            ]);
        } catch (\Throwable) {
            // audit failure must never block the redirect
        }
    }
}
