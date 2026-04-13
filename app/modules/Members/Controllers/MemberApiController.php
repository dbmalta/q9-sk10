<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Members\Services\MemberService;

/**
 * Member API controller for HTMX partials.
 *
 * Returns HTML fragments for live search results, member cards,
 * and status badges. All endpoints require authentication and
 * members.read permission.
 */
class MemberApiController extends Controller
{
    private MemberService $memberService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->memberService = new MemberService($app->getDb());
    }

    /**
     * GET /members/api/search — HTMX live search results.
     */
    public function searchResults(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.read');
        if ($guard !== null) {
            return $guard;
        }

        $query = trim((string) $request->getParam('q', ''));
        if (strlen($query) < 2) {
            return Response::html('');
        }

        $scopeNodeIds = $this->app->getPermissionResolver()->getScopeNodeIds();
        $result = $this->memberService->search($query, [], 1, 10, $scopeNodeIds);

        return $this->render('@members/partials/_search_results.html.twig', [
            'members' => $result['items'],
            'total' => $result['total'],
            'query' => $query,
        ]);
    }

    /**
     * GET /members/api/{id}/card — HTMX member card partial.
     */
    public function memberCard(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.read');
        if ($guard !== null) {
            return $guard;
        }

        $member = $this->memberService->getById((int) $vars['id']);
        if ($member === null) {
            return Response::html('<div class="text-muted">Not found</div>', 404);
        }

        return $this->render('@members/partials/_member_card.html.twig', [
            'member' => $member,
        ]);
    }

    /**
     * GET /members/api/{id}/status-badge — HTMX status badge partial.
     */
    public function statusBadge(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.read');
        if ($guard !== null) {
            return $guard;
        }

        $member = $this->memberService->getById((int) $vars['id']);
        if ($member === null) {
            return Response::html('', 404);
        }

        return $this->render('@members/partials/_status_badge.html.twig', [
            'member' => $member,
        ]);
    }
}
