<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Core\Controller;
use App\Core\Encryption;
use App\Core\Response;
use App\Modules\Members\Services\MemberService;

/**
 * Self-service profile edits. Every submitted field is queued in
 * member_pending_changes — nothing is applied to the members row directly.
 * Admins approve or reject via the existing /members/pending-changes queue.
 */
class MemberSelfEditController extends Controller
{
    /**
     * GET /me/profile/edit — render the self-edit form for the current user.
     */
    public function edit(): Response
    {
        if (($r = $this->requireAuth()) !== null) {
            return $r;
        }
        $member = $this->loadOwnMember();
        if ($member === null) {
            $this->flash('error', 'user.account.no_member_linked');
            return $this->redirect('/account');
        }

        $pending = $this->memberService()->getPendingChanges($member['id']);

        return $this->render('@members/members/self_edit.html.twig', [
            'member' => $member,
            'pending' => $pending,
            'editable_fields' => MemberService::SELF_EDIT_FIELDS,
            'breadcrumbs' => [
                ['label' => $this->app->getI18n()->t('nav.my_profile'), 'url' => '/account'],
                ['label' => $this->app->getI18n()->t('self_edit.title')],
            ],
        ]);
    }

    /**
     * POST /me/profile/edit — queue the submitted changes for admin review.
     */
    public function save(): Response
    {
        if (($r = $this->requireAuth()) !== null) {
            return $r;
        }
        $member = $this->loadOwnMember();
        if ($member === null) {
            $this->flash('error', 'user.account.no_member_linked');
            return $this->redirect('/account');
        }

        $user = $this->app->getSession()->getUser();
        $submitted = [];
        foreach (MemberService::SELF_EDIT_FIELDS as $field) {
            $value = $this->getParam($field);
            if ($value !== null) {
                $submitted[$field] = $value;
            }
        }

        $queued = $this->memberService()->submitSelfEdit(
            (int) $member['id'],
            $submitted,
            (int) $user['id'],
        );

        if ($queued === []) {
            $this->flash('info', 'self_edit.no_changes');
        } else {
            $this->flash('success', 'self_edit.submitted');
        }
        return $this->redirect('/me/profile/edit');
    }

    /**
     * Member records are not scope-filtered when the actor is editing their
     * own profile — they are always the authoritative source for their data.
     */
    protected function scopeAppliesToCurrentPage(): bool
    {
        return false;
    }

    private function loadOwnMember(): ?array
    {
        $user = $this->app->getSession()->getUser();
        if ($user === null) {
            return null;
        }
        return $this->app->getDb()->fetchOne(
            "SELECT * FROM members WHERE user_id = :uid LIMIT 1",
            ['uid' => (int) $user['id']]
        );
    }

    private function memberService(): MemberService
    {
        $keyPath = $this->app->getConfigValue('security.encryption_key_file');
        $encryption = null;
        if (is_string($keyPath) && $keyPath !== '' && file_exists($keyPath)) {
            try {
                $encryption = new Encryption($keyPath);
            } catch (\Throwable) {
                $encryption = null;
            }
        }
        return new MemberService($this->app->getDb(), $encryption);
    }
}
