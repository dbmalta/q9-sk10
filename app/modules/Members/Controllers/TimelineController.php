<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Members\Services\TimelineService;

/**
 * Member timeline controller.
 *
 * Handles adding and deleting timeline entries (rank progressions,
 * qualifications, etc.) for a member record.
 */
class TimelineController extends Controller
{
    private TimelineService $timelineService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->timelineService = new TimelineService($app->getDb());
    }

    /**
     * POST /members/{id}/timeline — add a timeline entry.
     */
    public function store(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) return $csrfGuard;

        $memberId = (int) $vars['id'];
        $fieldKey = trim((string) $request->getParam('field_key', ''));
        $value = trim((string) $request->getParam('value', ''));
        $effectiveDate = trim((string) $request->getParam('effective_date', ''));
        $notes = trim((string) $request->getParam('notes', '')) ?: null;

        $userId = $this->app->getSession()->get('user')['id'] ?? null;

        try {
            $this->timelineService->addEntry(
                $memberId,
                $fieldKey,
                $value,
                $effectiveDate,
                $userId ? (int) $userId : null,
                $notes
            );
            $this->flash('success', 'timeline.entry_added');
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        }

        return Response::redirect("/members/$memberId");
    }

    /**
     * POST /members/{id}/timeline/{entryId}/delete — delete a timeline entry.
     */
    public function delete(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) return $csrfGuard;

        $memberId = (int) $vars['id'];
        $entryId = (int) $vars['entryId'];

        // Verify entry belongs to this member
        $entry = $this->timelineService->getById($entryId);
        if ($entry && (int) $entry['member_id'] === $memberId) {
            $this->timelineService->deleteEntry($entryId);
            $this->flash('success', 'timeline.entry_deleted');
        }

        return Response::redirect("/members/$memberId");
    }
}
