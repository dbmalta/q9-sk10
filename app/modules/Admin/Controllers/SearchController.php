<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Members\Services\MemberService;

/**
 * Global search controller.
 *
 * Handles the topbar's live search (hx-get="/search") by returning an
 * HTML fragment of matching members within the user's permission scope.
 */
class SearchController extends Controller
{
    private MemberService $memberService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->memberService = new MemberService($app->getDb());
    }

    /**
     * GET /search — HTMX live search results fragment.
     */
    public function index(Request $request, array $vars): Response
    {
        $authCheck = $this->requireAuth();
        if ($authCheck !== null) {
            return $authCheck;
        }

        $query = trim((string) $request->getParam('search', ''));
        if ($query === '') {
            return Response::html('');
        }
        if (strlen($query) < 2) {
            return $this->render('@admin/partials/_global_search_results.html.twig', [
                'members' => [],
                'total' => 0,
                'query' => $query,
                'too_short' => true,
            ]);
        }

        $resolver = $this->app->getPermissionResolver();
        $members = [];
        $total = 0;
        if ($resolver->can('members.read')) {
            // Scope-aware by default (plan Q28): narrow to the active scope's
            // subtree. A "search all my nodes" toggle (?search_all=1) widens
            // to the full set of assignment subtrees.
            $ctx = $this->resolveViewContext();
            $searchAll = (bool) $request->getParam('search_all', false);
            $roots = $searchAll ? array_column($ctx->availableScopes, 'node_id') : $ctx->scopeNodeIds();
            $scopeNodeIds = $this->memberService->expandNodeSubtree($roots);
            $result = $this->memberService->search($query, [], 1, 8, $scopeNodeIds);
            $members = $result['items'];
            $total = $result['total'];
        }

        return $this->render('@admin/partials/_global_search_results.html.twig', [
            'members' => $members,
            'total' => $total,
            'query' => $query,
            'too_short' => false,
        ]);
    }
}
