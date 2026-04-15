<?php

declare(strict_types=1);

namespace App\Modules\Events\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Events\Services\EventService;
use App\Modules\OrgStructure\Services\OrgService;

/**
 * Event management controller.
 *
 * Public: calendar view, single event view.
 * Admin: list, create, edit, publish/unpublish, delete events.
 */
class EventController extends Controller
{
    private EventService $eventService;
    private OrgService $orgService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->eventService = new EventService($app->getDb());
        $this->orgService = new OrgService($app->getDb());
    }

    /**
     * GET /events — month calendar view (members only).
     */
    public function calendar(Request $request, array $vars): Response
    {
        $authCheck = $this->requireAuth();
        if ($authCheck !== null) {
            return $authCheck;
        }

        $now = new \DateTimeImmutable();
        $year = (int) ($request->getParam('year') ?: $now->format('Y'));
        $month = (int) ($request->getParam('month') ?: $now->format('n'));

        // Clamp month to 1-12 and adjust year
        if ($month < 1) {
            $month = 12;
            $year--;
        } elseif ($month > 12) {
            $month = 1;
            $year++;
        }

        $events = $this->eventService->getForMonth($year, $month);

        // Format events for Alpine.js JSON consumption
        $eventsJson = array_map(function (array $event) {
            return [
                'id' => $event['id'],
                'title' => $event['title'],
                'start_date' => $event['start_date'],
                'end_date' => $event['end_date'] ?? null,
                'all_day' => $event['all_day'],
                'location' => $event['location'] ?? null,
                'date_key' => substr($event['start_date'], 0, 10),
            ];
        }, $events);

        return $this->render('@events/events/calendar.html.twig', [
            'events_json' => json_encode($eventsJson, JSON_UNESCAPED_UNICODE),
            'year' => $year,
            'month' => $month,
            'month_name' => $this->getMonthName($month),
            'breadcrumbs' => [
                ['label' => $this->t('nav.events')],
            ],
        ]);
    }

    /**
     * GET /events/{id} — view a single published event (members only).
     */
    public function show(Request $request, array $vars): Response
    {
        $authCheck = $this->requireAuth();
        if ($authCheck !== null) {
            return $authCheck;
        }

        $id = (int) $vars['id'];
        $event = $this->eventService->getById($id);

        if (!$event || !$event['is_published']) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        return $this->render('@events/events/show.html.twig', [
            'event' => $event,
            'breadcrumbs' => [
                ['label' => $this->t('nav.events'), 'url' => '/events'],
                ['label' => $event['title']],
            ],
        ]);
    }

    /**
     * GET /admin/events — admin paginated event list.
     */
    public function adminIndex(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('events.write');
        if ($guard !== null) {
            return $guard;
        }

        $page = max(1, (int) $request->getParam('page', 1));
        $result = $this->eventService->getAll($page, 20);

        return $this->render('@events/events/admin_index.html.twig', [
            'events' => $result['items'],
            'pagination' => $result,
            'breadcrumbs' => [
                ['label' => $this->t('nav.events'), 'url' => '/events'],
                ['label' => $this->t('events.manage')],
            ],
        ]);
    }

    /**
     * GET /admin/events/create — show create form.
     */
    public function create(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('events.write');
        if ($guard !== null) {
            return $guard;
        }

        $nodes = $this->orgService->getTree();

        return $this->render('@events/events/form.html.twig', [
            'event' => null,
            'nodes' => $nodes,
            'breadcrumbs' => [
                ['label' => $this->t('nav.events'), 'url' => '/events'],
                ['label' => $this->t('events.manage'), 'url' => '/admin/events'],
                ['label' => $this->t('events.create')],
            ],
        ]);
    }

    /**
     * POST /admin/events — store new event.
     */
    public function store(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('events.write');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $userId = (int) $this->app->getSession()->get('user')['id'];

        $data = [
            'title' => trim((string) $request->getParam('title', '')),
            'description' => (string) $request->getParam('description', ''),
            'location' => $request->getParam('location') ? trim((string) $request->getParam('location')) : null,
            'start_date' => (string) $request->getParam('start_date', ''),
            'end_date' => $request->getParam('end_date') ?: null,
            'all_day' => $request->getParam('all_day') ? 1 : 0,
            'node_scope_id' => $request->getParam('node_scope_id') ? (int) $request->getParam('node_scope_id') : null,
        ];

        if (empty($data['title'])) {
            $this->flash('error', $this->t('events.title_required'));
            return $this->redirect('/admin/events/create');
        }

        if (empty($data['start_date'])) {
            $this->flash('error', $this->t('events.start_date_required'));
            return $this->redirect('/admin/events/create');
        }

        $id = $this->eventService->create($data, $userId);

        if ($request->getParam('publish')) {
            $this->eventService->publish($id);
        }

        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/events');
    }

    /**
     * GET /admin/events/{id}/edit — edit form.
     */
    public function edit(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('events.write');
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) $vars['id'];
        $event = $this->eventService->getById($id);
        if (!$event) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $nodes = $this->orgService->getTree();

        return $this->render('@events/events/form.html.twig', [
            'event' => $event,
            'nodes' => $nodes,
            'breadcrumbs' => [
                ['label' => $this->t('nav.events'), 'url' => '/events'],
                ['label' => $this->t('events.manage'), 'url' => '/admin/events'],
                ['label' => $this->t('common.edit')],
            ],
        ]);
    }

    /**
     * POST /admin/events/{id} — update event.
     */
    public function update(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('events.write');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $id = (int) $vars['id'];

        $data = [
            'title' => trim((string) $request->getParam('title', '')),
            'description' => (string) $request->getParam('description', ''),
            'location' => $request->getParam('location') ? trim((string) $request->getParam('location')) : null,
            'start_date' => (string) $request->getParam('start_date', ''),
            'end_date' => $request->getParam('end_date') ?: null,
            'all_day' => $request->getParam('all_day') ? 1 : 0,
            'node_scope_id' => $request->getParam('node_scope_id') ? (int) $request->getParam('node_scope_id') : null,
        ];

        if (empty($data['title'])) {
            $this->flash('error', $this->t('events.title_required'));
            return $this->redirect("/admin/events/{$id}/edit");
        }

        if (empty($data['start_date'])) {
            $this->flash('error', $this->t('events.start_date_required'));
            return $this->redirect("/admin/events/{$id}/edit");
        }

        $this->eventService->update($id, $data);
        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/events');
    }

    /**
     * POST /admin/events/{id}/publish — publish event.
     */
    public function publish(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('events.write');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $this->eventService->publish((int) $vars['id']);
        $this->flash('success', $this->t('events.published'));
        return $this->redirect('/admin/events');
    }

    /**
     * POST /admin/events/{id}/unpublish — unpublish event.
     */
    public function unpublish(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('events.write');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $this->eventService->unpublish((int) $vars['id']);
        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/events');
    }

    /**
     * POST /admin/events/{id}/delete — delete event.
     */
    public function delete(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('events.write');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $this->eventService->delete((int) $vars['id']);
        $this->flash('success', $this->t('flash.deleted'));
        return $this->redirect('/admin/events');
    }

    /**
     * Get a translatable month name.
     */
    private function getMonthName(int $month): string
    {
        $months = [
            1 => 'months.january', 2 => 'months.february', 3 => 'months.march',
            4 => 'months.april', 5 => 'months.may', 6 => 'months.june',
            7 => 'months.july', 8 => 'months.august', 9 => 'months.september',
            10 => 'months.october', 11 => 'months.november', 12 => 'months.december',
        ];

        return $this->t($months[$month] ?? 'months.january');
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}
