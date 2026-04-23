<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Modules\Members\Services\MemberDashboardService;

/**
 * GET /me — the member-facing landing page.
 *
 * Shows a welcome, the member's nodes, upcoming events, recent articles,
 * and unacknowledged notices. Member mode's equivalent of /admin/dashboard.
 */
class MemberDashboardController extends Controller
{
    /**
     * GET /me/profile — redirect to the current member's admin profile view.
     */
    public function viewOwnProfile(): Response
    {
        if (($r = $this->requireAuth()) !== null) {
            return $r;
        }
        $user = $this->app->getSession()->getUser();
        $row = $this->app->getDb()->fetchOne(
            "SELECT id FROM members WHERE user_id = :uid LIMIT 1",
            ['uid' => (int) $user['id']]
        );
        return $row
            ? $this->redirect('/members/' . (int) $row['id'])
            : $this->redirect('/account');
    }

    public function show(): Response
    {
        if (($r = $this->requireAuth()) !== null) {
            return $r;
        }
        $user = $this->app->getSession()->getUser();

        $memberRow = $this->app->getDb()->fetchOne(
            "SELECT id FROM members WHERE user_id = :uid LIMIT 1",
            ['uid' => (int) $user['id']]
        );
        if ($memberRow === null) {
            $this->flash('info', 'user.account.no_member_linked');
            return $this->redirect('/account');
        }

        $service = new MemberDashboardService($this->app->getDb());
        $data = $service->load((int) $memberRow['id'], (int) $user['id']);

        return $this->render('@members/me/dashboard.html.twig', $data);
    }

    /**
     * The member dashboard always shows the viewer's own records — node
     * scope doesn't apply, and the scope picker should be hidden.
     */
    protected function scopeAppliesToCurrentPage(): bool
    {
        return false;
    }
}
