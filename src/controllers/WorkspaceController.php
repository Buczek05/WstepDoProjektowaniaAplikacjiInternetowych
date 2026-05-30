<?php

require_once 'AppController.php';
require_once __DIR__ . '/../repositories/StatsRepository.php';
require_once __DIR__ . '/../Attribute/AllowedMethods.php';

class WorkspaceController extends AppController {

    /** Switch the active workspace (POST + CSRF; membership verified in the repo). */
    #[AllowedMethods(['POST'])]
    public function switch()
    {
        $this->requireLogin();

        // CSRF (B2/C2)
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            $this->redirect('/dashboard');
        }

        $orgId = (int)($_POST['organization_id'] ?? 0);
        if ($orgId > 0) {
            StatsRepository::getInstance()->switchWorkspace((int)$_SESSION['user_id'], $orgId);
        }

        // Return to the page the user came from, if it is one of ours.
        $path  = '/' . trim((string)parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH), '/');
        $known = ['/dashboard', '/sales', '/marketing', '/global', '/settings'];
        $this->redirect(in_array($path, $known, true) ? $path : '/dashboard');
    }
}
