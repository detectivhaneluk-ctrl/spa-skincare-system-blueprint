<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

/**
 * Shapes raw salon problems into founder-facing issue rows (copy stays canonical in {@see PlatformSalonProblemsService}).
 * Handles action hierarchy vs hero/contextual CTAs and view-only (no manage permission) presentation.
 */
final class PlatformSalonIssuesSectionService
{
    /**
     * @param list<array<string, mixed>> $raw {@see PlatformSalonProblemsService::buildProblems()}
     * @param list<array<string, mixed>> $managementActions from salon detail management stack
     * @param string $lifecycleStatus active|suspended|archived — used to dedupe vs hero lifecycle controls
     * @return list<array{
     *     severity:string,
     *     title:string,
     *     detail:string,
     *     issue_key:string,
     *     action:?array{label:string,href:string,mode:string}
     * }>
     */
    public function presentForSalonDetail(array $raw, array $managementActions, bool $canManage, string $lifecycleStatus = 'active'): array
    {
        $mgmtKeys = $this->managementKeys($managementActions);
        $lifecycleStatus = strtolower(trim($lifecycleStatus));
        $out = [];

        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }

            $issueKey = (string) ($row['issue_key'] ?? '');
            $title = (string) ($row['title'] ?? '');
            $detail = trim((string) ($row['detail'] ?? ''));
            $severity = (string) ($row['severity'] ?? 'medium');
            $label = isset($row['action_label']) ? trim((string) $row['action_label']) : '';
            $href = isset($row['action_url']) ? trim((string) $row['action_url']) : '';

            $action = $this->resolveAction($issueKey, $label, $href, $canManage, $mgmtKeys, $lifecycleStatus);

            $out[] = [
                'severity' => $severity,
                'title' => $title,
                'detail' => $detail,
                'issue_key' => $issueKey,
                'action' => $action,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $managementActions
     * @return list<string>
     */
    private function managementKeys(array $managementActions): array
    {
        $keys = [];
        foreach ($managementActions as $a) {
            if (!is_array($a)) {
                continue;
            }
            $k = (string) ($a['key'] ?? '');
            if ($k !== '') {
                $keys[] = $k;
            }
        }

        return $keys;
    }

    /**
     * @return array{label:string,href:string,mode:string}|null
     */
    private function resolveAction(
        string $issueKey,
        string $label,
        string $href,
        bool $canManage,
        array $mgmtKeys,
        string $lifecycleStatus
    ): ?array {
        if (!$canManage || $label === '' || $href === '') {
            return null;
        }

        if ($issueKey === 'suspended' && ($lifecycleStatus === 'suspended' || in_array('reactivate', $mgmtKeys, true))) {
            return null;
        }

        if ($issueKey === 'no_branch' && in_array('add_branch', $mgmtKeys, true)) {
            return ['label' => 'Review branches', 'href' => '#branches', 'mode' => 'quiet'];
        }

        $quietKeys = [
            'admin_login_off',
            'access_mismatch',
            'tenant_path_blocked',
            'tenant_suspended_binding',
        ];
        if (in_array($issueKey, $quietKeys, true)) {
            return ['label' => $label, 'href' => $href, 'mode' => 'quiet'];
        }

        return ['label' => $label, 'href' => $href, 'mode' => 'secondary'];
    }
}
