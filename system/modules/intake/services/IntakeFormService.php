<?php

declare(strict_types=1);

namespace Modules\Intake\Services;

use Core\App\Database;
use Core\App\SettingsService;
use Core\Audit\AuditService;
use Core\Auth\SessionAuth;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationRepositoryScope;
use Modules\Appointments\Repositories\AppointmentRepository;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Intake\Repositories\IntakeFormAssignmentRepository;
use Modules\Intake\Repositories\IntakeFormSubmissionRepository;
use Modules\Intake\Repositories\IntakeFormSubmissionValueRepository;
use Modules\Intake\Repositories\IntakeFormTemplateFieldRepository;
use Modules\Intake\Repositories\IntakeFormTemplateRepository;

/**
 * Intake forms: templates, assignments, public token completion. Template shape is authoritative — never trust client-defined fields.
 *
 * Public HTML routes ({@see \Modules\Intake\Controllers\IntakePublicController}) are anonymous (no auth middleware) but are not
 * “open”: a valid token is necessary but not sufficient — {@see IntakeFormAssignmentRepository::findByTokenHashWithPublicGraphOrgCohesion()}
 * proves single-org graph cohesion (no resolved HTTP tenant), {@see isPublicIntakePolicyAllowingForAssignment()} enforces branch
 * existence when assignment is branch-scoped, and branch-effective {@see SettingsService::getIntakeSettings()}.
 */
final class IntakeFormService
{
    public const FIELD_TYPES = ['text', 'textarea', 'checkbox', 'select', 'date', 'email', 'phone', 'number'];

    /** Single user-facing deny message for any unusable public intake link (missing, invalid, expired, wrong lifecycle). */
    public const PUBLIC_ACCESS_UNAVAILABLE_MESSAGE = 'This link is invalid, expired, or no longer available.';

    public function __construct(
        private IntakeFormTemplateRepository $templates,
        private IntakeFormTemplateFieldRepository $fields,
        private IntakeFormAssignmentRepository $assignments,
        private IntakeFormSubmissionRepository $submissions,
        private IntakeFormSubmissionValueRepository $values,
        private ClientRepository $clients,
        private AppointmentRepository $appointments,
        private BranchContext $branchContext,
        private SessionAuth $session,
        private Database $db,
        private AuditService $audit,
        private SettingsService $settings,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    public function listTemplates(?int $branchFilter): array
    {
        $filters = [];
        if ($branchFilter !== null) {
            $filters['branch_id'] = $branchFilter;
        }

        return $this->templates->listInTenantScopeForStaff($filters, $branchFilter, 200, 0);
    }

    /**
     * @return array{template: array<string, mixed>, fields: list<array<string, mixed>>}|null
     */
    public function getTemplateWithFieldsForStaff(int $templateId): ?array
    {
        $t = $this->templates->findInTenantScopeForStaff($templateId, $this->branchContext->getCurrentBranchId());
        if (!$t) {
            return null;
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity($this->nullableInt($t['branch_id'] ?? null));

        return [
            'template' => $t,
            'fields' => $this->decodeFieldRows($this->fields->listByTemplateIdInTenantScopeForStaff($templateId, $this->branchContext->getCurrentBranchId())),
        ];
    }

    public function createTemplate(array $data): int
    {
        $data = $this->branchContext->enforceBranchOnCreate($data);
        $data['name'] = trim((string) ($data['name'] ?? ''));
        if ($data['name'] === '') {
            throw new \InvalidArgumentException('Template name is required.');
        }
        $data['created_by'] = $this->session->id();
        $data['updated_by'] = $this->session->id();
        $data['is_active'] = $data['is_active'] ?? 1;
        $data['required_before_appointment'] = $data['required_before_appointment'] ?? 0;

        return $this->templates->create($data);
    }

    public function updateTemplate(int $id, array $data): void
    {
        $row = $this->templates->findInTenantScopeForStaff($id, $this->branchContext->getCurrentBranchId());
        if (!$row) {
            throw new \RuntimeException('Template not found.');
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity($this->nullableInt($row['branch_id'] ?? null));
        $data['updated_by'] = $this->session->id();
        if (isset($data['name'])) {
            $data['name'] = trim((string) $data['name']);
            if ($data['name'] === '') {
                throw new \InvalidArgumentException('Template name is required.');
            }
        }
        $this->templates->updateInTenantScopeForStaff($id, $this->branchContext->getCurrentBranchId(), $data);
    }

    /**
     * @param array{field_key: string, label: string, field_type: string, required?: bool, options?: list<string>} $field
     */
    public function addTemplateField(int $templateId, array $field): int
    {
        $t = $this->templates->findInTenantScopeForStaff($templateId, $this->branchContext->getCurrentBranchId());
        if (!$t || !(bool) ($t['is_active'] ?? 0)) {
            throw new \DomainException('Template not found or inactive.');
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity($this->nullableInt($t['branch_id'] ?? null));
        $key = $this->normalizeFieldKey((string) ($field['field_key'] ?? ''));
        $label = trim((string) ($field['label'] ?? ''));
        $type = trim((string) ($field['field_type'] ?? ''));
        if ($label === '') {
            throw new \InvalidArgumentException('Field label is required.');
        }
        $this->assertFieldType($type);
        $options = $field['options'] ?? null;
        $this->validateOptionsForType($type, $options);
        $existing = $this->fields->listByTemplateIdInTenantScopeForStaff($templateId, $this->branchContext->getCurrentBranchId());
        $sort = count($existing);

        return $this->fields->createInTenantScopeForStaff([
            'template_id' => $templateId,
            'sort_order' => $sort,
            'field_key' => $key,
            'label' => $label,
            'field_type' => $type,
            'required' => !empty($field['required']),
            'options_json' => $type === 'select' ? array_values(array_map('strval', $options ?? [])) : null,
        ], $this->branchContext->getCurrentBranchId());
    }

    public function deleteTemplateField(int $templateId, int $fieldId): void
    {
        $t = $this->templates->findInTenantScopeForStaff($templateId, $this->branchContext->getCurrentBranchId());
        if (!$t) {
            throw new \RuntimeException('Template not found.');
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity($this->nullableInt($t['branch_id'] ?? null));
        $opBranch = $this->branchContext->getCurrentBranchId();
        $f = $this->fields->findInTenantScopeForStaff($fieldId, $opBranch);
        if (!$f || (int) ($f['template_id'] ?? 0) !== $templateId) {
            throw new \RuntimeException('Field not found.');
        }
        $this->fields->deleteByIdInTenantScopeForStaff($fieldId, $opBranch);
    }

    /**
     * @return array{assignment_id: int, raw_token: string}
     */
    public function assignTemplate(int $templateId, int $clientId, ?int $appointmentId): array
    {
        $t = $this->templates->findInTenantScopeForStaff($templateId, $this->branchContext->getCurrentBranchId());
        if (!$t || !(bool) ($t['is_active'] ?? 0)) {
            throw new \DomainException('Template not found or inactive.');
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity($this->nullableInt($t['branch_id'] ?? null));
        $client = $this->clients->find($clientId);
        if (!$client) {
            throw new \DomainException('Client not found.');
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity($this->nullableInt($client['branch_id'] ?? null));
        $branchId = $this->nullableInt($t['branch_id'] ?? null)
            ?? $this->nullableInt($client['branch_id'] ?? null);
        $apptBranch = null;
        if ($appointmentId !== null && $appointmentId > 0) {
            $appt = $this->appointments->find($appointmentId);
            if (!$appt) {
                throw new \DomainException('Appointment not found.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($this->nullableInt($appt['branch_id'] ?? null));
            if ((int) ($appt['client_id'] ?? 0) !== $clientId) {
                throw new \DomainException('Appointment does not belong to this client.');
            }
            $apptBranch = $this->nullableInt($appt['branch_id'] ?? null);
            if ($branchId !== null && $apptBranch !== null && $branchId !== $apptBranch) {
                throw new \DomainException('Branch mismatch between template, client, and appointment.');
            }
        }
        $fields = $this->fields->listByTemplateIdInTenantScopeForStaff($templateId, $this->branchContext->getCurrentBranchId());
        if ($fields === []) {
            throw new \DomainException('Template has no fields; add fields before assigning.');
        }
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
        $assignmentId = $this->assignments->create([
            'template_id' => $templateId,
            'client_id' => $clientId,
            'appointment_id' => $appointmentId !== null && $appointmentId > 0 ? $appointmentId : null,
            'branch_id' => $branchId ?? $apptBranch,
            'status' => 'pending',
            'token_hash' => $hash,
            'token_expires_at' => $expires,
            'assigned_by' => $this->session->id(),
        ]);
        $this->audit->log('intake_form_assigned', 'intake_form_assignment', $assignmentId, $this->session->id(), $branchId ?? $apptBranch, [
            'template_id' => $templateId,
            'client_id' => $clientId,
            'appointment_id' => $appointmentId,
        ]);

        return ['assignment_id' => $assignmentId, 'raw_token' => $raw];
    }

    /**
     * @return array{assignment: array<string, mixed>, fields: list<array<string, mixed>>}|null
     */
    public function loadPublicForm(string $rawToken): ?array
    {
        $hash = hash('sha256', trim($rawToken));
        $a = $this->assignments->findByTokenHashWithPublicGraphOrgCohesion($hash);
        if (!$a) {
            return null;
        }
        if (!$this->applyExpiryIfNeeded($a, $hash)) {
            return null;
        }
        $a = $this->assignments->findByAssignmentIdAndTokenHashWithPublicGraphOrgCohesion((int) $a['id'], $hash);
        if (!$a || in_array($a['status'], ['completed', 'cancelled', 'expired'], true)) {
            return null;
        }
        if (!$this->isPublicIntakePolicyAllowingForAssignment($a)) {
            return null;
        }
        if (!isset($a['template_active']) || (int) $a['template_active'] !== 1) {
            return null;
        }
        $templateId = (int) $a['template_id'];
        $fields = $this->decodeFieldRows($this->fields->listByTemplateIdForPublicTokenFlow($templateId));
        if ($fields === []) {
            return null;
        }
        if (($a['status'] ?? '') === 'pending') {
            $this->assignments->updateColumnsWhereIdAndTokenHashForPublicTokenFlow((int) $a['id'], $hash, [
                'status' => 'opened',
                'opened_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return ['assignment' => $a, 'fields' => $fields];
    }

    /**
     * Confirms the bearer may see the post-submit thanks state: token resolves to a completed assignment with a stored submission.
     */
    public function publicThanksAllowed(string $rawToken): bool
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '') {
            return false;
        }
        $hash = hash('sha256', $rawToken);
        $a = $this->assignments->findByTokenHashWithPublicGraphOrgCohesion($hash);
        if (!$a) {
            return false;
        }
        $a = $this->assignments->findByAssignmentIdAndTokenHashWithPublicGraphOrgCohesion((int) $a['id'], $hash);
        if (!$a || ($a['status'] ?? '') !== 'completed') {
            return false;
        }
        if (!$this->isPublicIntakePolicyAllowingForAssignment($a)) {
            return false;
        }

        return $this->submissions->findByAssignmentIdForPublicTokenFlow((int) $a['id']) !== null;
    }

    /**
     * @param array<string, string|list<string>> $post field_key => value (arrays for multi — not used)
     * @return array{ok: bool, errors?: array<string, string>, submission_id?: int}
     */
    public function submitPublic(string $rawToken, array $post): array
    {
        $hash = hash('sha256', trim($rawToken));
        $a = $this->assignments->findByTokenHashWithPublicGraphOrgCohesion($hash);
        if (!$a) {
            return ['ok' => false, 'errors' => ['_token' => self::PUBLIC_ACCESS_UNAVAILABLE_MESSAGE]];
        }
        if (!$this->applyExpiryIfNeeded($a, $hash)) {
            return ['ok' => false, 'errors' => ['_token' => self::PUBLIC_ACCESS_UNAVAILABLE_MESSAGE]];
        }
        $a = $this->assignments->findByAssignmentIdAndTokenHashWithPublicGraphOrgCohesion((int) $a['id'], $hash);
        if (!$a || in_array($a['status'], ['completed', 'cancelled', 'expired'], true)) {
            return ['ok' => false, 'errors' => ['_token' => self::PUBLIC_ACCESS_UNAVAILABLE_MESSAGE]];
        }
        if (!$this->isPublicIntakePolicyAllowingForAssignment($a)) {
            return ['ok' => false, 'errors' => ['_token' => self::PUBLIC_ACCESS_UNAVAILABLE_MESSAGE]];
        }
        if ($this->submissions->findByAssignmentIdForPublicTokenFlow((int) $a['id'])) {
            return ['ok' => false, 'errors' => ['_token' => self::PUBLIC_ACCESS_UNAVAILABLE_MESSAGE]];
        }
        $templateId = (int) $a['template_id'];
        $fieldRows = $this->decodeFieldRows($this->fields->listByTemplateIdForPublicTokenFlow($templateId));
        $errors = [];
        $normalized = [];
        foreach ($fieldRows as $f) {
            $key = (string) $f['field_key'];
            $type = (string) $f['field_type'];
            $req = (bool) ($f['required'] ?? false);
            $rawVal = $post[$key] ?? null;
            $v = $this->validateAndNormalizeField($type, $rawVal, $req, $f['options'] ?? null);
            if ($v['error'] !== null) {
                $errors[$key] = $v['error'];
            } else {
                $normalized[$key] = $v['value'];
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        $pdo = $this->db->connection();
        $pdo->beginTransaction();
        try {
            $subId = $this->submissions->createAfterPublicTokenFlow([
                'assignment_id' => (int) $a['id'],
                'template_id' => $templateId,
                'client_id' => (int) $a['client_id'],
                'appointment_id' => isset($a['appointment_id']) && $a['appointment_id'] !== null && $a['appointment_id'] !== ''
                    ? (int) $a['appointment_id']
                    : null,
                'submitted_from' => 'public_token',
                'validation_errors_json' => null,
            ]);
            foreach ($normalized as $k => $val) {
                $this->values->insert($subId, $k, $val);
            }
            $this->assignments->updateColumnsWhereIdAndTokenHashForPublicTokenFlow((int) $a['id'], $hash, [
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $this->audit->log('intake_form_submitted', 'intake_form_submission', $subId, null, $this->nullableInt($a['branch_id'] ?? null), [
            'assignment_id' => (int) $a['id'],
            'template_id' => $templateId,
            'client_id' => (int) $a['client_id'],
        ]);

        return ['ok' => true, 'submission_id' => $subId];
    }

    public function cancelAssignment(int $assignmentId, string $reason): void
    {
        $a = $this->assignments->findInTenantScopeForStaff($assignmentId, $this->branchContext->getCurrentBranchId());
        if (!$a) {
            throw new \RuntimeException('Assignment not found.');
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity($this->nullableInt($a['branch_id'] ?? null));
        if (in_array($a['status'], ['completed', 'cancelled'], true)) {
            throw new \DomainException('Assignment cannot be cancelled.');
        }
        $this->assignments->updateColumnsInTenantScopeForStaff($assignmentId, $this->branchContext->getCurrentBranchId(), [
            'status' => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
            'cancel_reason' => trim($reason) !== '' ? trim($reason) : 'cancelled',
        ]);
    }

    /**
     * Counts open assignments **linked to this appointment** whose template has `required_before_appointment`.
     * For appointment-scoped intakes (assignment.appointment_id set). New bookings are gated separately by
     * {@see countBlockingRequiredPendingAssignmentsForNewBooking()} (appointment_id IS NULL).
     */
    public function countIncompletePriorIntakeAssignmentsForAppointment(int $appointmentId): int
    {
        if ($appointmentId <= 0) {
            return 0;
        }
        $appt = $this->appointments->find($appointmentId);
        if (!$appt) {
            return 0;
        }
        $apptBranch = $this->nullableInt($appt['branch_id'] ?? null);
        $catalogOperationBranchId = ($apptBranch !== null && $apptBranch > 0)
            ? $apptBranch
            : $this->branchContext->getCurrentBranchId();

        return $this->assignments->countIncompleteRequiredPriorForAppointmentInTenantScope($appointmentId, $catalogOperationBranchId);
    }

    /**
     * Open assignments for this client with `required_before_appointment`, not yet tied to an appointment (`appointment_id` NULL),
     * in `pending`/`opened`. Used to block **new** appointment creation (staff slot create, public book, series occurrence, waitlist convert)
     * until completed or cancelled. Branch: assignment and template must match the booking branch when that branch is known; when unknown,
     * only fully global (NULL/NULL) rows apply.
     */
    public function countBlockingRequiredPendingAssignmentsForNewBooking(int $clientId, ?int $bookingBranchId): int
    {
        if ($clientId <= 0) {
            return 0;
        }
        $params = [$clientId];
        $sql = 'SELECT COUNT(*) AS c
             FROM intake_form_assignments a
             INNER JOIN intake_form_templates t ON t.id = a.template_id AND t.deleted_at IS NULL
             WHERE a.client_id = ?
               AND a.appointment_id IS NULL
               AND a.status IN (\'pending\',\'opened\')
               AND t.required_before_appointment = 1
               AND t.is_active = 1';
        if ($bookingBranchId === null || $bookingBranchId <= 0) {
            $aNull = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('a');
            $tNull = $this->orgScope->settingsBackedCatalogGlobalNullBranchOrgAnchoredSql('t');
            $sql .= $aNull['sql'] . $tNull['sql'];
            $params = array_merge($params, $aNull['params'], $tNull['params']);
        } else {
            $aVis = $this->orgScope->productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause('a', $bookingBranchId);
            $tVis = $this->orgScope->productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause('t', $bookingBranchId);
            $sql .= ' AND (' . $aVis['sql'] . ') AND (' . $tVis['sql'] . ')';
            $params = array_merge($params, $aVis['params'], $tVis['params']);
        }
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAssignmentsForStaff(?int $branchFilter): array
    {
        $filters = [];
        if ($branchFilter !== null) {
            $filters['branch_id'] = $branchFilter;
        }

        return $this->assignments->listInTenantScopeForStaff($filters, $branchFilter, 200, 0);
    }

    /**
     * @return array{submission: array<string, mixed>, values: list<array<string, mixed>>, fields: list<array<string, mixed>>}|null
     */
    public function getSubmissionDetailForStaff(int $submissionId): ?array
    {
        $opBranch = $this->branchContext->getCurrentBranchId();
        $sub = $this->submissions->findInTenantScopeForStaff($submissionId, $opBranch);
        if (!$sub) {
            return null;
        }
        $a = $this->assignments->findInTenantScopeForStaff((int) $sub['assignment_id'], $opBranch);
        if (!$a) {
            return null;
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity($this->nullableInt($a['branch_id'] ?? null));
        $t = $this->templates->findInTenantScopeForStaff((int) $sub['template_id'], $opBranch, true);
        if ($t) {
            $this->branchContext->assertBranchMatchOrGlobalEntity($this->nullableInt($t['branch_id'] ?? null));
        }

        return [
            'submission' => $sub,
            'values' => $this->values->listBySubmissionIdInTenantScopeForStaff($submissionId, $opBranch),
            'fields' => $this->decodeFieldRows($this->fields->listByTemplateIdInTenantScopeForStaff((int) $sub['template_id'], $opBranch)),
        ];
    }

    public function findSubmissionIdForAssignment(int $assignmentId): ?int
    {
        return $this->submissions->findSubmissionIdByAssignmentIdInTenantScopeForStaff(
            $assignmentId,
            $this->branchContext->getCurrentBranchId()
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function decodeFieldRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $opts = null;
            if (!empty($r['options_json'])) {
                $raw = $r['options_json'];
                if (is_string($raw)) {
                    $opts = json_decode($raw, true);
                } elseif (is_array($raw)) {
                    $opts = $raw;
                }
                if (!is_array($opts)) {
                    $opts = [];
                }
            }
            $r['options'] = $opts;
            $out[] = $r;
        }

        return $out;
    }

    /**
     * @param string $tokenHashSha256 hex sha256 of raw token (same as used for {@see IntakeFormAssignmentRepository::findByTokenHashWithPublicGraphOrgCohesion})
     */
    private function applyExpiryIfNeeded(array $a, string $tokenHashSha256): bool
    {
        $id = (int) $a['id'];
        $exp = $a['token_expires_at'] ?? null;
        if ($exp !== null && trim((string) $exp) !== '' && strtotime((string) $exp) < time()) {
            if (!in_array($a['status'], ['completed', 'cancelled', 'expired'], true)) {
                $this->assignments->updateColumnsWhereIdAndTokenHashForPublicTokenFlow($id, $tokenHashSha256, ['status' => 'expired']);
            }

            return false;
        }

        return true;
    }

    private function assertFieldType(string $type): void
    {
        if (!in_array($type, self::FIELD_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported field type: ' . $type);
        }
    }

    /**
     * @param list<string>|null $options
     */
    private function validateOptionsForType(string $type, ?array $options): void
    {
        if ($type !== 'select') {
            return;
        }
        if ($options === null || $options === []) {
            throw new \InvalidArgumentException('Select fields require options.');
        }
    }

    /**
     * @return array{error: ?string, value: ?string}
     */
    private function validateAndNormalizeField(string $type, mixed $raw, bool $required, ?array $selectOptions): array
    {
        if ($type === 'checkbox') {
            $on = $raw === true || $raw === 1 || $raw === '1' || $raw === 'on' || $raw === 'yes';
            if ($required && !$on) {
                return ['error' => 'Required.', 'value' => null];
            }

            return ['error' => null, 'value' => $on ? '1' : '0'];
        }
        $str = is_array($raw) ? '' : trim((string) ($raw ?? ''));
        if ($required && $str === '') {
            return ['error' => 'Required.', 'value' => null];
        }
        if (!$required && $str === '') {
            return ['error' => null, 'value' => null];
        }
        return match ($type) {
            'text', 'textarea' => ['error' => null, 'value' => $str],
            'email' => filter_var($str, FILTER_VALIDATE_EMAIL)
                ? ['error' => null, 'value' => $str]
                : ['error' => 'Invalid email.', 'value' => null],
            'phone' => strlen($str) <= 50
                ? ['error' => null, 'value' => $str]
                : ['error' => 'Phone too long.', 'value' => null],
            'number' => is_numeric($str)
                ? ['error' => null, 'value' => $str]
                : ['error' => 'Must be a number.', 'value' => null],
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $str) === 1
                ? ['error' => null, 'value' => $str]
                : ['error' => 'Use YYYY-MM-DD.', 'value' => null],
            'select' => $this->validateSelect($str, $selectOptions ?? []),
            default => ['error' => 'Unsupported type.', 'value' => null],
        };
    }

    /**
     * @param list<string> $options
     * @return array{error: ?string, value: ?string}
     */
    private function validateSelect(string $str, array $options): array
    {
        $allowed = array_map('strval', $options);
        if (!in_array($str, $allowed, true)) {
            return ['error' => 'Invalid choice.', 'value' => null];
        }

        return ['error' => null, 'value' => $str];
    }

    private function normalizeFieldKey(string $key): string
    {
        $key = strtolower(trim($key));
        if ($key === '' || preg_match('/^[a-z0-9_]{1,64}$/', $key) !== 1) {
            throw new \InvalidArgumentException('field_key must be 1–64 chars: a-z, 0-9, underscore.');
        }

        return $key;
    }

    private function nullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return (int) $v;
    }

    /**
     * Anonymous public intake: branch must not be soft-deleted when assignment is branch-scoped; intake.public_enabled must be
     * true for the effective settings scope (global-only merge when assignment.branch_id is null/0).
     *
     * @param array<string, mixed> $a Assignment row (incl. branch_id)
     */
    private function isPublicIntakePolicyAllowingForAssignment(array $a): bool
    {
        $rawBranch = $this->nullableInt($a['branch_id'] ?? null);
        $scopedBranchId = ($rawBranch !== null && $rawBranch > 0) ? $rawBranch : null;
        if ($scopedBranchId !== null) {
            $row = $this->db->fetchOne(
                'SELECT id FROM branches WHERE id = ? AND deleted_at IS NULL',
                [$scopedBranchId]
            );
            if ($row === null) {
                return false;
            }
        }

        return $this->settings->getIntakeSettings($scopedBranchId)['public_enabled'];
    }
}
