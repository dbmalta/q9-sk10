<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Members\Services\AttachmentService;

/**
 * Member attachment controller.
 *
 * Handles file uploads, downloads, and deletions for member attachments.
 */
class AttachmentController extends Controller
{
    private AttachmentService $attachmentService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $uploadPath = $app->getConfigValue('app.data_path', ROOT_PATH . '/data') . '/uploads';
        $this->attachmentService = new AttachmentService($app->getDb(), $uploadPath);
    }

    /**
     * POST /members/{id}/attachments — upload a file.
     */
    public function upload(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) return $csrfGuard;

        $memberId = (int) $vars['id'];
        $fieldKey = trim((string) $request->getParam('field_key', 'general'));
        $userId = $this->app->getSession()->get('user')['id'] ?? null;

        $file = $_FILES['attachment'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $this->flash('error', 'attachments.no_file');
            return Response::redirect("/members/$memberId");
        }

        try {
            $this->attachmentService->upload(
                $memberId,
                $file,
                $fieldKey,
                $userId ? (int) $userId : null
            );
            $this->flash('success', 'attachments.uploaded');
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->flash('error', 'attachments.upload_failed');
        }

        return Response::redirect("/members/$memberId");
    }

    /**
     * GET /members/{id}/attachments/{attachmentId}/download — download a file.
     */
    public function download(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.read');
        if ($guard !== null) return $guard;

        $memberId = (int) $vars['id'];
        $attachmentId = (int) $vars['attachmentId'];

        $attachment = $this->attachmentService->getById($attachmentId);
        if (!$attachment || (int) $attachment['member_id'] !== $memberId) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        try {
            $fileData = $this->attachmentService->download($attachment);

            return Response::file(
                $fileData['path'],
                $fileData['original_name'],
                $fileData['mime_type']
            );
        } catch (\RuntimeException $e) {
            return $this->render('errors/404.html.twig', [], 404);
        }
    }

    /**
     * POST /members/{id}/attachments/{attachmentId}/delete — delete a file.
     */
    public function delete(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) return $guard;
        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) return $csrfGuard;

        $memberId = (int) $vars['id'];
        $attachmentId = (int) $vars['attachmentId'];

        $attachment = $this->attachmentService->getById($attachmentId);
        if ($attachment && (int) $attachment['member_id'] === $memberId) {
            $this->attachmentService->delete($attachmentId);
            $this->flash('success', 'attachments.deleted');
        }

        return Response::redirect("/members/$memberId");
    }
}
