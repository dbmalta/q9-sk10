<?php

declare(strict_types=1);

namespace App\Modules\Events\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Events\Services\EventService;
use App\Modules\Events\Services\ICalService;

/**
 * iCalendar feed controller.
 *
 * Serves unauthenticated .ics feeds via token and manages per-user
 * token generation/regeneration.
 */
class ICalController extends Controller
{
    private EventService $eventService;
    private ICalService $icalService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->eventService = new EventService($app->getDb());
        $this->icalService = new ICalService($app->getDb());
    }

    /**
     * GET /ical/{token} — serve .ics feed (unauthenticated).
     */
    public function feed(Request $request, array $vars): Response
    {
        $token = $vars['token'] ?? '';
        $tokenRecord = $this->icalService->validateToken($token);

        if ($tokenRecord === null) {
            return new Response(404, 'Invalid or expired iCal token.');
        }

        // Get upcoming published events for the next 12 months
        $startDate = date('Y-m-d H:i:s');
        $endDate = date('Y-m-d H:i:s', strtotime('+12 months'));
        $events = $this->eventService->getForDateRange($startDate, $endDate);

        $calendarName = $this->app->getConfigValue('app.name', 'ScoutKeeper') . ' Events';
        $icsContent = $this->icalService->generateFeed($events, $calendarName);

        $response = new Response(200, $icsContent);
        $response->setHeader('Content-Type', 'text/calendar; charset=UTF-8');
        $response->setHeader('Content-Disposition', 'inline; filename="events.ics"');
        $response->setHeader('Cache-Control', 'no-cache, must-revalidate');

        return $response;
    }

    /**
     * GET /events/ical — iCal token management page (requires auth).
     */
    public function manage(Request $request, array $vars): Response
    {
        $authCheck = $this->requireAuth();
        if ($authCheck !== null) {
            return $authCheck;
        }

        $user = $this->app->getSession()->get('user');
        $memberId = (int) $user['member_id'];

        $tokenRecord = $this->icalService->getTokenForMember($memberId);

        $feedUrl = null;
        if ($tokenRecord !== null) {
            $baseUrl = rtrim($this->app->getConfigValue('app.url', ''), '/');
            $feedUrl = $baseUrl . '/ical/' . $tokenRecord['token'];
        }

        return $this->render('@events/ical.html.twig', [
            'token_record' => $tokenRecord,
            'feed_url' => $feedUrl,
            'breadcrumbs' => [
                ['label' => $this->t('nav.events'), 'url' => '/events'],
                ['label' => $this->t('events.ical_feed')],
            ],
        ]);
    }

    /**
     * POST /events/ical/generate — generate or regenerate iCal token (requires auth, CSRF).
     */
    public function generate(Request $request, array $vars): Response
    {
        $authCheck = $this->requireAuth();
        if ($authCheck !== null) {
            return $authCheck;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $user = $this->app->getSession()->get('user');
        $memberId = (int) $user['member_id'];

        // Regenerate replaces any existing token
        $this->icalService->regenerateToken($memberId);

        $this->flash('success', $this->t('events.ical_token_generated'));
        return $this->redirect('/events/ical');
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}
