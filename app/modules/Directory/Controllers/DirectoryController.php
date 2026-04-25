<?php

declare(strict_types=1);

namespace App\Modules\Directory\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Directory\Services\DirectoryService;
use App\Modules\OrgStructure\Services\OrgService;

/**
 * Directory controller — searchable contact directory of members
 * within the caller's scope. The visual org tree lives in the
 * OrgStructure module (/admin/org).
 */
class DirectoryController extends Controller
{
    private DirectoryService $directoryService;
    private OrgService $orgService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->directoryService = new DirectoryService($app->getDb());
        $this->orgService = new OrgService($app->getDb());
    }

    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('directory.read');
        if ($guard !== null) {
            return $guard;
        }

        $nodeId = $request->getParam('node_id') ? (int) $request->getParam('node_id') : null;
        $search = $request->getParam('search') ? trim((string) $request->getParam('search')) : null;

        $ctx = $this->resolveViewContext();
        $memberSvc = new \App\Modules\Members\Services\MemberService($this->app->getDb());
        $scopeNodeIds = $memberSvc->expandNodeSubtree($ctx->scopeNodeIds());

        $members = $this->directoryService->getDirectoryMembers($scopeNodeIds, $search, $nodeId);
        $nodes = $this->orgService->getTree();

        return $this->render('@directory/directory/contacts.html.twig', [
            'members' => $members,
            'nodes' => $nodes,
            'current_node_id' => $nodeId,
            'current_search' => $search ?? '',
            'breadcrumbs' => [
                ['label' => $this->t('nav.directory')],
            ],
        ]);
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}
