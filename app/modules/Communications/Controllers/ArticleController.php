<?php

declare(strict_types=1);

namespace App\Modules\Communications\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Communications\Services\ArticleService;
use App\Modules\OrgStructure\Services\OrgService;

/**
 * Article management controller.
 *
 * Admin: list, create, edit, publish/unpublish, delete articles.
 * Public: view published articles by slug.
 */
class ArticleController extends Controller
{
    private ArticleService $articleService;
    private OrgService $orgService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->articleService = new ArticleService($app->getDb());
        $this->orgService = new OrgService($app->getDb());
    }

    /**
     * GET /articles — public article listing.
     */
    public function index(Request $request, array $vars): Response
    {
        $page = max(1, (int) $request->getParam('page', 1));
        $nodeId = $request->getParam('node_id') ? (int) $request->getParam('node_id') : null;

        $result = $this->articleService->getPublished($nodeId, $page, 10);

        return $this->render('@communications/articles/index.html.twig', [
            'articles' => $result['items'],
            'pagination' => $result,
            'breadcrumbs' => [
                ['label' => $this->t('nav.communications')],
            ],
        ]);
    }

    /**
     * GET /articles/{slug} — view a single published article.
     */
    public function show(Request $request, array $vars): Response
    {
        $slug = $vars['slug'] ?? '';
        $article = $this->articleService->getBySlug($slug);

        if (!$article || !$article['is_published']) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        return $this->render('@communications/articles/show.html.twig', [
            'article' => $article,
            'breadcrumbs' => [
                ['label' => $this->t('nav.communications'), 'url' => '/articles'],
                ['label' => $article['title']],
            ],
        ]);
    }

    /**
     * GET /admin/articles — admin article list.
     */
    public function adminIndex(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('communications.write');
        if ($guard !== null) return $guard;

        $page = max(1, (int) $request->getParam('page', 1));
        $result = $this->articleService->getAll($page, 20);

        return $this->render('@communications/articles/admin_index.html.twig', [
            'articles' => $result['items'],
            'pagination' => $result,
            'breadcrumbs' => [
                ['label' => $this->t('nav.communications'), 'url' => '/articles'],
                ['label' => $this->t('articles.manage')],
            ],
        ]);
    }

    /**
     * GET /admin/articles/create — show create form.
     */
    public function create(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('communications.write');
        if ($guard !== null) return $guard;

        $nodes = $this->orgService->getTree();

        return $this->render('@communications/articles/form.html.twig', [
            'article' => null,
            'nodes' => $nodes,
            'breadcrumbs' => [
                ['label' => $this->t('nav.communications'), 'url' => '/articles'],
                ['label' => $this->t('articles.manage'), 'url' => '/admin/articles'],
                ['label' => $this->t('articles.create')],
            ],
        ]);
    }

    /**
     * POST /admin/articles — store new article.
     */
    public function store(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('communications.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $userId = (int) $this->app->getSession()->get('user')['id'];

        $data = [
            'title' => trim((string) $request->getParam('title', '')),
            'body' => (string) $request->getParam('body', ''),
            'excerpt' => $request->getParam('excerpt') ?: null,
            'visibility' => $request->getParam('visibility', 'members'),
            'node_scope_id' => $request->getParam('node_scope_id') ? (int) $request->getParam('node_scope_id') : null,
        ];

        if (empty($data['title'])) {
            $this->flash('error', $this->t('articles.title_required'));
            return $this->redirect('/admin/articles/create');
        }

        $id = $this->articleService->create($data, $userId);

        if ($request->getParam('publish')) {
            $this->articleService->publish($id);
        }

        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/articles');
    }

    /**
     * GET /admin/articles/{id}/edit — edit form.
     */
    public function edit(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('communications.write');
        if ($guard !== null) return $guard;

        $id = (int) $vars['id'];
        $article = $this->articleService->getById($id);
        if (!$article) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $nodes = $this->orgService->getTree();

        return $this->render('@communications/articles/form.html.twig', [
            'article' => $article,
            'nodes' => $nodes,
            'breadcrumbs' => [
                ['label' => $this->t('nav.communications'), 'url' => '/articles'],
                ['label' => $this->t('articles.manage'), 'url' => '/admin/articles'],
                ['label' => $this->t('common.edit')],
            ],
        ]);
    }

    /**
     * POST /admin/articles/{id} — update article.
     */
    public function update(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('communications.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $id = (int) $vars['id'];

        $data = [
            'title' => trim((string) $request->getParam('title', '')),
            'body' => (string) $request->getParam('body', ''),
            'excerpt' => $request->getParam('excerpt') ?: null,
            'visibility' => $request->getParam('visibility', 'members'),
            'node_scope_id' => $request->getParam('node_scope_id') ? (int) $request->getParam('node_scope_id') : null,
        ];

        if (empty($data['title'])) {
            $this->flash('error', $this->t('articles.title_required'));
            return $this->redirect("/admin/articles/{$id}/edit");
        }

        $this->articleService->update($id, $data);
        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/articles');
    }

    /**
     * POST /admin/articles/{id}/publish — publish article.
     */
    public function publish(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('communications.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $this->articleService->publish((int) $vars['id']);
        $this->flash('success', $this->t('articles.published'));
        return $this->redirect('/admin/articles');
    }

    /**
     * POST /admin/articles/{id}/unpublish — unpublish article.
     */
    public function unpublish(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('communications.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $this->articleService->unpublish((int) $vars['id']);
        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/articles');
    }

    /**
     * POST /admin/articles/{id}/delete — delete article.
     */
    public function delete(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('communications.write');
        if ($guard !== null) return $guard;

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) return $csrfCheck;

        $this->articleService->delete((int) $vars['id']);
        $this->flash('success', $this->t('flash.deleted'));
        return $this->redirect('/admin/articles');
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}
