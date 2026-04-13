<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Members\Services\RegistrationService;
use App\Modules\Members\Services\WaitingListService;
use App\Modules\OrgStructure\Services\OrgService;

/**
 * Public-facing registration controller.
 *
 * Handles self-registration forms, invitation-based registration,
 * and the public waiting list sign-up form. No authentication required.
 */
class PublicRegistrationController extends Controller
{
    private RegistrationService $registrationService;
    private WaitingListService $waitingListService;
    private OrgService $orgService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->registrationService = new RegistrationService($app->getDb());
        $this->waitingListService = new WaitingListService($app->getDb());
        $this->orgService = new OrgService($app->getDb());
    }

    /**
     * GET /register — show the self-registration form.
     */
    public function showForm(Request $request, array $vars): Response
    {
        $nodes = $this->orgService->getTree();

        return $this->render('@members/registration/public_register.html.twig', [
            'nodes' => $nodes,
            'invitation' => null,
        ]);
    }

    /**
     * POST /register — process self-registration.
     */
    public function register(Request $request, array $vars): Response
    {
        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $data = [
            'first_name'    => trim((string) $request->getParam('first_name', '')),
            'surname'       => trim((string) $request->getParam('surname', '')),
            'email'         => trim((string) $request->getParam('email', '')),
            'phone'         => $request->getParam('phone') ?: null,
            'dob'           => $request->getParam('dob') ?: null,
            'gender'        => $request->getParam('gender') ?: null,
            'address_line1' => $request->getParam('address_line1') ?: null,
            'address_line2' => $request->getParam('address_line2') ?: null,
            'city'          => $request->getParam('city') ?: null,
            'postcode'      => $request->getParam('postcode') ?: null,
            'country'       => $request->getParam('country') ?: null,
            'gdpr_consent'  => (int) $request->getParam('gdpr_consent', 0),
        ];

        $nodeId = $request->getParam('node_id') ? (int) $request->getParam('node_id') : null;
        $password = $request->getParam('password') ?: null;

        try {
            $this->registrationService->selfRegister($data, $nodeId, $password);
            return $this->render('@members/registration/public_register_success.html.twig');
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/register');
        }
    }

    /**
     * GET /register/invite/{token} — show the invitation registration form.
     */
    public function showInvitationForm(Request $request, array $vars): Response
    {
        $token = $vars['token'] ?? '';
        $invitation = $this->registrationService->getValidInvitation($token);

        if (!$invitation) {
            return $this->render('@members/registration/invitation_invalid.html.twig');
        }

        return $this->render('@members/registration/public_register.html.twig', [
            'invitation' => $invitation,
            'nodes' => [],
        ]);
    }

    /**
     * POST /register/invite/{token} — process invitation registration.
     */
    public function processInvitation(Request $request, array $vars): Response
    {
        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $token = $vars['token'] ?? '';

        $data = [
            'first_name'    => trim((string) $request->getParam('first_name', '')),
            'surname'       => trim((string) $request->getParam('surname', '')),
            'email'         => trim((string) $request->getParam('email', '')),
            'phone'         => $request->getParam('phone') ?: null,
            'dob'           => $request->getParam('dob') ?: null,
            'gender'        => $request->getParam('gender') ?: null,
            'address_line1' => $request->getParam('address_line1') ?: null,
            'address_line2' => $request->getParam('address_line2') ?: null,
            'city'          => $request->getParam('city') ?: null,
            'postcode'      => $request->getParam('postcode') ?: null,
            'country'       => $request->getParam('country') ?: null,
            'gdpr_consent'  => (int) $request->getParam('gdpr_consent', 0),
        ];

        $password = $request->getParam('password') ?: null;

        try {
            $this->registrationService->processInvitation($token, $data, $password);
            return $this->render('@members/registration/public_register_success.html.twig');
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect("/register/invite/{$token}");
        }
    }

    /**
     * GET /waiting-list — show the public waiting list sign-up form.
     */
    public function showWaitingListForm(Request $request, array $vars): Response
    {
        $nodes = $this->orgService->getTree();

        return $this->render('@members/registration/public_waiting_list.html.twig', [
            'nodes' => $nodes,
        ]);
    }

    /**
     * POST /waiting-list — process waiting list sign-up.
     */
    public function submitWaitingList(Request $request, array $vars): Response
    {
        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $data = [
            'parent_name'  => trim((string) $request->getParam('parent_name', '')),
            'parent_email' => trim((string) $request->getParam('parent_email', '')),
            'child_name'   => trim((string) $request->getParam('child_name', '')),
            'child_dob'    => $request->getParam('child_dob') ?: null,
            'notes'        => $request->getParam('notes') ?: null,
        ];
        $nodeId = $request->getParam('preferred_node_id') ? (int) $request->getParam('preferred_node_id') : null;

        try {
            $this->waitingListService->addEntry($data, $nodeId);
            return $this->render('@members/registration/public_waiting_list_success.html.twig');
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/waiting-list');
        }
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}
