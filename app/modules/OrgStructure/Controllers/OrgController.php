<?php

declare(strict_types=1);

namespace App\Modules\OrgStructure\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\OrgStructure\Services\OrgService;

/**
 * Org structure controller.
 *
 * Tree view with expand/collapse, node CRUD, and team management.
 */
class OrgController extends Controller
{
    private OrgService $orgService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->orgService = new OrgService($app->getDb());
    }

    /**
     * GET /admin/org — show the org tree.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('org_structure.read');
        if ($guard !== null) {
            return $guard;
        }

        $allowed = $this->resolveViewAllowedNodeIds();
        $tree = $this->orgService->getTree(true, $allowed);
        $levelTypes = $this->orgService->getLevelTypes();

        return $this->render('@orgstructure/org/index.html.twig', [
            'tree' => $tree,
            'level_types' => $levelTypes,
            'breadcrumbs' => [
                ['label' => $this->t('nav.org_structure')],
            ],
        ]);
    }

    /**
     * GET /admin/org/nodes/{id} — view a node's details.
     */
    public function show(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('org_structure.read');
        if ($guard !== null) {
            return $guard;
        }

        $node = $this->orgService->getNode((int) $vars['id']);
        if ($node === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $allowed = $this->resolveViewAllowedNodeIds();
        $scopeGuard = $this->assertNodeInAllowed((int) $vars['id'], $allowed);
        if ($scopeGuard !== null) {
            return $scopeGuard;
        }

        $ancestors = $this->orgService->getAncestors((int) $vars['id']);
        if ($allowed !== null) {
            // Out-of-scope ancestors are pruned from the breadcrumb trail
            // so their names don't leak through the detail page.
            $ancestors = array_values(array_filter(
                $ancestors,
                fn(array $a) => in_array((int) $a['id'], $allowed, true)
            ));
        }
        $children = $this->orgService->getChildren((int) $vars['id']);
        $teams = $this->orgService->getTeamsForNode((int) $vars['id']);
        $levelTypes = $this->orgService->getLevelTypes();

        return $this->render('@orgstructure/org/show.html.twig', [
            'node' => $node,
            'ancestors' => $ancestors,
            'children' => $children,
            'teams' => $teams,
            'level_types' => $levelTypes,
            'breadcrumbs' => $this->buildBreadcrumbs($ancestors),
        ]);
    }

    /**
     * GET /admin/org/nodes/create — show the create node form.
     */
    public function create(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('org_structure.write');
        if ($guard !== null) {
            return $guard;
        }

        $parentId = $request->getParam('parent_id');
        $parent = null;
        if ($parentId !== null) {
            $parent = $this->orgService->getNode((int) $parentId);
            $scopeGuard = $this->assertNodeInAllowed((int) $parentId, $this->resolveWriteAllowedNodeIds());
            if ($scopeGuard !== null) {
                return $scopeGuard;
            }
        } else {
            // Creating a root node is effectively super-admin only — no parent
            // means the new node sits above any existing scope.
            if ($this->resolveWriteAllowedNodeIds() !== null) {
                return $this->render('errors/403.html.twig', [], 403);
            }
        }

        $levelTypes = $this->orgService->getLevelTypes();

        return $this->render('@orgstructure/org/form.html.twig', [
            'node' => null,
            'parent' => $parent,
            'level_types' => $levelTypes,
            'breadcrumbs' => [
                ['label' => $this->t('nav.org_structure'), 'url' => '/admin/org'],
                ['label' => $this->t('common.add')],
            ],
        ]);
    }

    /**
     * POST /admin/org/nodes — store a new node.
     */
    public function store(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('org_structure.write');
        if ($guard !== null) {
            return $guard;
        }
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) {
            return $csrfGuard;
        }

        $name = trim((string) $this->getParam('name', ''));
        if ($name === '') {
            $this->flash('error', $this->t('org.name_required'));
            return $this->redirect('/admin/org/nodes/create');
        }

        $parentIdRaw = $this->getParam('parent_id');
        $parentId = $parentIdRaw !== null && $parentIdRaw !== '' ? (int) $parentIdRaw : null;
        $writeAllowed = $this->resolveWriteAllowedNodeIds();
        if ($parentId === null) {
            if ($writeAllowed !== null) {
                return $this->render('errors/403.html.twig', [], 403);
            }
        } else {
            $scopeGuard = $this->assertNodeInAllowed($parentId, $writeAllowed);
            if ($scopeGuard !== null) {
                return $scopeGuard;
            }
        }

        $nodeId = $this->orgService->createNode([
            'name' => $name,
            'short_name' => $this->getParam('short_name') ?: null,
            'description' => $this->getParam('description') ?: null,
            'parent_id' => $this->getParam('parent_id') ?: null,
            'level_type_id' => (int) $this->getParam('level_type_id', 0),
            'age_group_min' => $this->getParam('age_group_min') ?: null,
            'age_group_max' => $this->getParam('age_group_max') ?: null,
            'sort_order' => (int) $this->getParam('sort_order', 0),
        ]);

        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect("/admin/org/nodes/$nodeId");
    }

    /**
     * GET /admin/org/nodes/{id}/edit — show the edit form.
     */
    public function edit(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('org_structure.write');
        if ($guard !== null) {
            return $guard;
        }

        $node = $this->orgService->getNode((int) $vars['id']);
        if ($node === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $scopeGuard = $this->assertNodeInAllowed((int) $vars['id'], $this->resolveWriteAllowedNodeIds());
        if ($scopeGuard !== null) {
            return $scopeGuard;
        }

        $parent = $node['parent_id'] ? $this->orgService->getNode((int) $node['parent_id']) : null;
        $levelTypes = $this->orgService->getLevelTypes();
        $ancestors = $this->orgService->getAncestors((int) $vars['id']);

        return $this->render('@orgstructure/org/form.html.twig', [
            'node' => $node,
            'parent' => $parent,
            'level_types' => $levelTypes,
            'breadcrumbs' => $this->buildBreadcrumbs($ancestors, $this->t('common.edit')),
        ]);
    }

    /**
     * POST /admin/org/nodes/{id} — update a node.
     */
    public function update(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('org_structure.write');
        if ($guard !== null) {
            return $guard;
        }
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) {
            return $csrfGuard;
        }

        $nodeId = (int) $vars['id'];
        $node = $this->orgService->getNode($nodeId);
        if ($node === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $scopeGuard = $this->assertNodeInAllowed($nodeId, $this->resolveWriteAllowedNodeIds());
        if ($scopeGuard !== null) {
            return $scopeGuard;
        }

        $name = trim((string) $this->getParam('name', ''));
        if ($name === '') {
            $this->flash('error', $this->t('org.name_required'));
            return $this->redirect("/admin/org/nodes/$nodeId/edit");
        }

        $this->orgService->updateNode($nodeId, [
            'name' => $name,
            'short_name' => $this->getParam('short_name') ?: null,
            'description' => $this->getParam('description') ?: null,
            'age_group_min' => $this->getParam('age_group_min') ?: null,
            'age_group_max' => $this->getParam('age_group_max') ?: null,
            'sort_order' => (int) $this->getParam('sort_order', 0),
            'is_active' => (int) $this->getParam('is_active', 1),
        ]);

        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect("/admin/org/nodes/$nodeId");
    }

    /**
     * POST /admin/org/nodes/{id}/delete — delete a node.
     */
    public function delete(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('org_structure.write');
        if ($guard !== null) {
            return $guard;
        }
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) {
            return $csrfGuard;
        }

        $nodeId = (int) $vars['id'];

        $scopeGuard = $this->assertNodeInAllowed($nodeId, $this->resolveWriteAllowedNodeIds());
        if ($scopeGuard !== null) {
            return $scopeGuard;
        }

        try {
            $this->orgService->deleteNode($nodeId);
            $this->flash('success', $this->t('flash.deleted'));
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/org');
    }

    /**
     * POST /admin/org/nodes/{id}/teams — create a team for a node.
     */
    public function storeTeam(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('org_structure.write');
        if ($guard !== null) {
            return $guard;
        }
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) {
            return $csrfGuard;
        }

        $nodeId = (int) $vars['id'];

        $scopeGuard = $this->assertNodeInAllowed($nodeId, $this->resolveWriteAllowedNodeIds());
        if ($scopeGuard !== null) {
            return $scopeGuard;
        }

        $name = trim((string) $this->getParam('team_name', ''));

        if ($name === '') {
            $this->flash('error', $this->t('org.team_name_required'));
            return $this->redirect("/admin/org/nodes/$nodeId");
        }

        $this->orgService->createTeam([
            'node_id' => $nodeId,
            'name' => $name,
            'description' => $this->getParam('team_description') ?: null,
            'is_permanent' => (int) $this->getParam('is_permanent', 1),
        ]);

        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect("/admin/org/nodes/$nodeId");
    }

    /**
     * POST /admin/org/teams/{id}/delete — delete a team.
     */
    public function deleteTeam(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('org_structure.write');
        if ($guard !== null) {
            return $guard;
        }
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) {
            return $csrfGuard;
        }

        $teamId = (int) $vars['id'];
        $team = $this->orgService->getTeam($teamId);

        if ($team === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $scopeGuard = $this->assertNodeInAllowed((int) $team['node_id'], $this->resolveWriteAllowedNodeIds());
        if ($scopeGuard !== null) {
            return $scopeGuard;
        }

        $this->orgService->deleteTeam($teamId);
        $this->flash('success', $this->t('flash.deleted'));
        return $this->redirect("/admin/org/nodes/{$team['node_id']}");
    }

    /**
     * Build breadcrumbs from ancestor nodes.
     */
    private function buildBreadcrumbs(array $ancestors, ?string $extraLabel = null): array
    {
        $crumbs = [['label' => $this->t('nav.org_structure'), 'url' => '/admin/org']];

        foreach ($ancestors as $ancestor) {
            $crumbs[] = [
                'label' => $ancestor['name'],
                'url' => "/admin/org/nodes/{$ancestor['id']}",
            ];
        }

        // Remove URL from last crumb (current page)
        if (!empty($crumbs)) {
            $lastIdx = count($crumbs) - 1;
            if ($extraLabel !== null) {
                $crumbs[] = ['label' => $extraLabel];
            } else {
                unset($crumbs[$lastIdx]['url']);
            }
        }

        return $crumbs;
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }

    /**
     * Allowed node IDs for the tree/detail view. Null = unrestricted
     * (super admin with no active scope). An empty array means the user
     * has no scope at all. Narrowed by the active ViewContext scope when
     * the user has explicitly picked a subset of their scopes.
     *
     * @return array<int>|null
     */
    private function resolveViewAllowedNodeIds(): ?array
    {
        $resolver = $this->app->getPermissionResolver();
        $ctx = $this->resolveViewContext();

        if ($resolver->isSuperAdmin()) {
            if ($ctx->activeScopeNodeId !== null) {
                return $this->orgService->expandAllowedSubtree([$ctx->activeScopeNodeId]);
            }
            return null;
        }

        $roleScopes = $resolver->getScopeNodeIds();
        if ($roleScopes === []) {
            return [];
        }

        if ($ctx->activeScopeNodeId !== null && in_array($ctx->activeScopeNodeId, $roleScopes, true)) {
            return $this->orgService->expandAllowedSubtree([$ctx->activeScopeNodeId]);
        }

        return $this->orgService->expandAllowedSubtree($roleScopes);
    }

    /**
     * Allowed node IDs for write operations. Null = unrestricted (super
     * admin). Unlike the view-allowed set this does NOT narrow by the
     * active scope picker — the user can always mutate any node inside
     * their role-assigned scopes, regardless of which subset they're
     * currently filtering the UI to.
     *
     * @return array<int>|null
     */
    private function resolveWriteAllowedNodeIds(): ?array
    {
        $resolver = $this->app->getPermissionResolver();
        if ($resolver->isSuperAdmin()) {
            return null;
        }
        $roleScopes = $resolver->getScopeNodeIds();
        if ($roleScopes === []) {
            return [];
        }
        return $this->orgService->expandAllowedSubtree($roleScopes);
    }

    /**
     * Return a 403 response if $nodeId is not in $allowed. A null
     * $allowed means unrestricted (super admin) and always passes.
     *
     * @param array<int>|null $allowed
     */
    private function assertNodeInAllowed(int $nodeId, ?array $allowed): ?Response
    {
        if ($allowed === null) {
            return null;
        }
        if (!in_array($nodeId, $allowed, true)) {
            return $this->render('errors/403.html.twig', [], 403);
        }
        return null;
    }
}
