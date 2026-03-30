<?php

declare(strict_types=1);

namespace Modules\Intake\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Modules\Intake\Services\IntakeFormService;

/**
 * Staff/admin intake templates, assignments, submission review. Thin: delegates to IntakeFormService.
 */
final class IntakeAdminController
{
    public function __construct(
        private IntakeFormService $intake,
        private BranchContext $branchContext
    ) {
    }

    public function templatesIndex(): void
    {
        $templates = $this->intake->listTemplates($this->branchContext->getCurrentBranchId());
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash() ?? [];
        require base_path('modules/intake/views/templates/index.php');
    }

    public function templatesCreate(): void
    {
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash() ?? [];
        $template = ['name' => '', 'description' => '', 'is_active' => true, 'required_before_appointment' => false];
        $errors = [];
        require base_path('modules/intake/views/templates/create.php');
    }

    public function templatesStore(): void
    {
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        try {
            $id = $this->intake->createTemplate([
                'name' => (string) ($_POST['name'] ?? ''),
                'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
                'is_active' => isset($_POST['is_active']) && (string) $_POST['is_active'] !== '0',
                'required_before_appointment' => isset($_POST['required_before_appointment']) && (string) $_POST['required_before_appointment'] !== '0',
            ]);
            flash('success', 'Template created. Add fields next.');
            header('Location: /intake/templates/' . $id . '/edit');
            exit;
        } catch (\Throwable $e) {
            $flash = ['error' => $e->getMessage()];
            $template = [
                'name' => (string) ($_POST['name'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'is_active' => isset($_POST['is_active']) && (string) $_POST['is_active'] !== '0',
                'required_before_appointment' => isset($_POST['required_before_appointment']) && (string) $_POST['required_before_appointment'] !== '0',
            ];
            $errors = [$e->getMessage()];
            require base_path('modules/intake/views/templates/create.php');
        }
    }

    public function templatesEdit(int $id): void
    {
        $data = $this->intake->getTemplateWithFieldsForStaff($id);
        if (!$data) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash() ?? [];
        $template = $data['template'];
        $fields = $data['fields'];
        require base_path('modules/intake/views/templates/edit.php');
    }

    public function templatesUpdate(int $id): void
    {
        try {
            $this->intake->updateTemplate($id, [
                'name' => (string) ($_POST['name'] ?? ''),
                'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
                'is_active' => isset($_POST['is_active']) && (string) $_POST['is_active'] !== '0',
                'required_before_appointment' => isset($_POST['required_before_appointment']) && (string) $_POST['required_before_appointment'] !== '0',
            ]);
            flash('success', 'Template saved.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /intake/templates/' . $id . '/edit');
        exit;
    }

    public function templateFieldStore(int $templateId): void
    {
        $type = trim((string) ($_POST['field_type'] ?? ''));
        $options = null;
        if ($type === 'select') {
            $raw = (string) ($_POST['options_lines'] ?? '');
            $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
            $options = array_values(array_filter(array_map('trim', $lines), static fn (string $s): bool => $s !== ''));
        }
        try {
            $this->intake->addTemplateField($templateId, [
                'field_key' => (string) ($_POST['field_key'] ?? ''),
                'label' => (string) ($_POST['label'] ?? ''),
                'field_type' => $type,
                'required' => isset($_POST['required']) && (string) $_POST['required'] !== '0',
                'options' => $options,
            ]);
            flash('success', 'Field added.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /intake/templates/' . $templateId . '/edit');
        exit;
    }

    public function templateFieldDelete(int $templateId, int $fieldId): void
    {
        try {
            $this->intake->deleteTemplateField($templateId, $fieldId);
            flash('success', 'Field removed.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /intake/templates/' . $templateId . '/edit');
        exit;
    }

    public function assignForm(): void
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        $templates = array_values(array_filter(
            $this->intake->listTemplates($branchId),
            static fn (array $r): bool => !empty($r['is_active'])
        ));
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash() ?? [];
        require base_path('modules/intake/views/assign/form.php');
    }

    public function assignStore(): void
    {
        $templateId = (int) ($_POST['template_id'] ?? 0);
        $clientId = (int) ($_POST['client_id'] ?? 0);
        $appointmentId = isset($_POST['appointment_id']) && $_POST['appointment_id'] !== ''
            ? (int) $_POST['appointment_id']
            : null;
        try {
            $res = $this->intake->assignTemplate($templateId, $clientId, $appointmentId);
            flash('success', 'Assignment created. Copy the completion link or raw token below — it cannot be shown again.');
            $_SESSION['intake_show_token_once'] = $res['raw_token'];
            header('Location: /intake/assignments');
            exit;
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            header('Location: /intake/assign');
            exit;
        }
    }

    public function assignmentsIndex(): void
    {
        $assignments = $this->intake->listAssignmentsForStaff($this->branchContext->getCurrentBranchId());
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash() ?? [];
        $showTokenOnce = null;
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['intake_show_token_once'])) {
            $showTokenOnce = (string) $_SESSION['intake_show_token_once'];
            unset($_SESSION['intake_show_token_once']);
        }
        require base_path('modules/intake/views/assignments/index.php');
    }

    public function submissionShow(int $id): void
    {
        $detail = $this->intake->getSubmissionDetailForStaff($id);
        if (!$detail) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash() ?? [];
        $submission = $detail['submission'];
        $values = $detail['values'];
        $fields = $detail['fields'];
        $valueByKey = [];
        foreach ($values as $v) {
            $valueByKey[(string) $v['field_key']] = $v['value_text'] ?? '';
        }
        require base_path('modules/intake/views/submissions/show.php');
    }

    public function assignmentCancel(int $id): void
    {
        $reason = (string) ($_POST['reason'] ?? 'cancelled');
        try {
            $this->intake->cancelAssignment($id, $reason);
            flash('success', 'Assignment cancelled.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /intake/assignments');
        exit;
    }
}
