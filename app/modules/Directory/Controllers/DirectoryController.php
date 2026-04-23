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
 * Directory controller.
 *
 * Organogram: visual org tree with key role holders.
 * Contacts: searchable flat list of directory-visible role holders.
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

    /**
     * GET /directory — visual organogram with key role holders.
     */
    public function organogram(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('directory.read');
        if ($guard !== null) {
            return $guard;
        }

        $tree = $this->directoryService->getOrganogram();

        return $this->render('@directory/directory/organogram.html.twig', [
            'tree' => $tree,
            'breadcrumbs' => [
                ['label' => $this->t('nav.directory')],
            ],
        ]);
    }

    /**
     * GET /directory/contacts — searchable flat contact list.
     */
    public function contacts(Request $request, array $vars): Response
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
        $contacts = $this->directoryService->getContactDirectory($nodeId, $search, $scopeNodeIds);
        $nodes = $this->orgService->getTree();

        return $this->render('@directory/directory/contacts.html.twig', [
            'contacts' => $contacts,
            'nodes' => $nodes,
            'current_node_id' => $nodeId,
            'current_search' => $search ?? '',
            'breadcrumbs' => [
                ['label' => $this->t('nav.directory'), 'url' => '/directory'],
                ['label' => $this->t('directory.contacts')],
            ],
        ]);
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}
