<?php

declare(strict_types=1);

namespace AppCore\Modules\Permissions\Controllers;

use AppCore\Core\Application;
use AppCore\Core\Controller;
use AppCore\Core\Request;
use AppCore\Core\Response;
use AppCore\Modules\Permissions\Services\AssignmentService;
use AppCore\Modules\Permissions\Services\RoleService;

class AssignmentController extends Controller
{
    private AssignmentService $assignments;
    private RoleService $roles;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->assignments = new AssignmentService($app->getDb(), $app->getSession());
        $this->roles       = new RoleService($app->getDb());
    }

    public function forUser(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.read');
        if ($guard !== null) {
            return $guard;
        }

        $userId = (int) $vars['userId'];
        $user = $this->app->getDb()->fetchOne(
            "SELECT id, email FROM users WHERE id = :id",
            ['id' => $userId]
        );

        return $this->render('@permissions/assignments/index.html.twig', [
            'user'        => $user,
            'assignments' => $this->assignments->forUser($userId),
            'roles'       => $this->roles->all(),
        ]);
    }

    public function store(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.write');
        if ($guard !== null) {
            return $guard;
        }

        $userId  = (int) $vars['userId'];
        $roleId  = (int) $this->getParam('role_id', 0);
        $startAt = (string) $this->getParam('start_date', '');
        $endAt   = (string) $this->getParam('end_date', '');
        $nodes   = $request->getParam('node_ids', []);

        $nodeIds = is_array($nodes) ? array_map('intval', $nodes) : [];
        $actor = $this->app->getSession()->getUser();

        if ($roleId === 0) {
            $this->flash('error', $this->t('permissions.assignment_role_required'));
            return $this->redirect('/admin/roles/assignments/' . $userId);
        }

        $this->assignments->assign(
            $userId,
            $roleId,
            $startAt !== '' ? $startAt : null,
            $endAt !== '' ? $endAt : null,
            $nodeIds,
            $actor ? (int) $actor['id'] : null,
        );

        $this->flash('success', $this->t('permissions.assignment_created'));
        return $this->redirect('/admin/roles/assignments/' . $userId);
    }

    public function end(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.write');
        if ($guard !== null) {
            return $guard;
        }

        $assignmentId = (int) $vars['id'];
        $assignment = $this->app->getDb()->fetchOne(
            "SELECT user_id FROM role_assignments WHERE id = :id",
            ['id' => $assignmentId]
        );

        $this->assignments->end($assignmentId);

        $userId = $assignment !== null ? (int) $assignment['user_id'] : 0;
        $this->flash('success', $this->t('permissions.assignment_ended'));
        return $this->redirect('/admin/roles/assignments/' . $userId);
    }
}
