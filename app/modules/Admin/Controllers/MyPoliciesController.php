<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Services\NoticeService;
use App\Modules\Admin\Services\PoliciesService;
use App\Modules\Admin\Services\TermsService;

/**
 * Member-facing policy acknowledgement.
 *
 * Lists the policies the signed-in member must acknowledge, lets them read
 * the published version, and records their acknowledgement.
 */
class MyPoliciesController extends Controller
{
    private PoliciesService $policiesService;
    private TermsService $termsService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->policiesService = new PoliciesService($app->getDb());
        $this->termsService = new TermsService($app->getDb());
    }

    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requireAuth();
        if ($guard !== null) {
            return $guard;
        }

        $memberId = $this->getMemberId();
        if ($memberId === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $outstanding = $this->policiesService->getOutstandingForMember($memberId);
        $acknowledged = $this->policiesService->getAcknowledgedForMember($memberId);

        return $this->render('@admin/admin/terms/my_policies.html.twig', [
            'outstanding' => $outstanding,
            'acknowledged' => $acknowledged,
            'breadcrumbs' => [
                ['label' => $this->t('policies.my_title')],
            ],
        ]);
    }

    public function show(Request $request, array $vars): Response
    {
        $guard = $this->requireAuth();
        if ($guard !== null) {
            return $guard;
        }

        $memberId = $this->getMemberId();
        if ($memberId === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $versionId = (int) $vars['versionId'];
        $version = $this->termsService->getVersionById($versionId);
        if (!$version || !$version['is_published']) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $policy = $this->policiesService->getById((int) $version['policy_id']);
        if (!$policy || !$policy['is_active']) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        // Is this member in the policy's audience?
        $required = $this->policiesService->getRequiredMemberIds((int) $policy['id']);
        if (!in_array($memberId, $required, true)) {
            return $this->render('errors/403.html.twig', [], 403);
        }

        $userId = (int) $this->app->getSession()->get('user')['id'];
        $alreadyAccepted = $this->termsService->hasAccepted($userId, $versionId);

        return $this->render('@admin/admin/terms/my_policy_view.html.twig', [
            'policy' => $policy,
            'version' => $version,
            'already_accepted' => $alreadyAccepted,
            'breadcrumbs' => [
                ['label' => $this->t('policies.my_title'), 'url' => '/my/policies'],
                ['label' => $policy['name']],
            ],
        ]);
    }

    public function accept(Request $request, array $vars): Response
    {
        $guard = $this->requireAuth();
        if ($guard !== null) {
            return $guard;
        }
        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $memberId = $this->getMemberId();
        if ($memberId === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $versionId = (int) $vars['versionId'];
        $version = $this->termsService->getVersionById($versionId);
        if (!$version || !$version['is_published']) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $policy = $this->policiesService->getById((int) $version['policy_id']);
        if (!$policy || !$policy['is_active']) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $required = $this->policiesService->getRequiredMemberIds((int) $policy['id']);
        if (!in_array($memberId, $required, true)) {
            return $this->render('errors/403.html.twig', [], 403);
        }

        $userId = (int) $this->app->getSession()->get('user')['id'];
        $ip = $request->getClientIp();
        $this->termsService->acceptTerms($versionId, $userId, $ip);

        $this->flash('success', $this->t('policies.accepted'));
        return $this->redirect('/my/policies');
    }

    /**
     * Mark the pending-acknowledgements modal as dismissed for this session.
     * AJAX-called by the modal's JS when it auto-opens.
     */
    public function dismissModal(Request $request, array $vars): Response
    {
        $this->app->getSession()->set('pending_ack_modal_dismissed', true);
        return $this->json(['ok' => true]);
    }

    /**
     * Member-facing notice acknowledgement (for the bell dropdown / popup).
     */
    public function acknowledgeNotice(Request $request, array $vars): Response
    {
        $guard = $this->requireAuth();
        if ($guard !== null) {
            return $guard;
        }
        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $user = $this->app->getSession()->get('user');
        $userId = (int) ($user['id'] ?? 0);
        if ($userId === 0) {
            return $this->render('errors/403.html.twig', [], 403);
        }

        $noticeId = (int) ($vars['id'] ?? 0);
        $notices = new NoticeService($this->app->getDb());
        $notices->acknowledge($noticeId, $userId);

        $this->flash('success', $this->t('ack.notice_acknowledged'));

        $referer = $request->getHeader('referer');
        return $this->redirect($referer ?: '/');
    }

    private function getMemberId(): ?int
    {
        $user = $this->app->getSession()->get('user');
        if (!is_array($user)) {
            return null;
        }
        $memberId = (int) ($user['member_id'] ?? 0);
        if ($memberId > 0) {
            return $memberId;
        }
        $row = $this->app->getDb()->fetchOne(
            "SELECT id FROM members WHERE user_id = :uid LIMIT 1",
            ['uid' => (int) ($user['id'] ?? 0)]
        );
        return $row ? (int) $row['id'] : null;
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}
