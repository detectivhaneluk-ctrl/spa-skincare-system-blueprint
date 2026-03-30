<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

/**
 * Human-readable labels for founder Incident Center rows and filters.
 */
final class FounderIncidentPresenter
{
    /** @return list<array{value:string,label:string}> */
    public function categoryFilterOptions(): array
    {
        return [
            ['value' => '', 'label' => 'All categories'],
            ['value' => 'access', 'label' => 'Access'],
            ['value' => 'organization_branch', 'label' => 'Organization / branch'],
            ['value' => 'public_surface', 'label' => 'Public surface'],
            ['value' => 'data_health', 'label' => 'Data health'],
        ];
    }

    /** @return list<array{value:string,label:string}> */
    public function severityFilterOptions(): array
    {
        return [
            ['value' => '', 'label' => 'All severities'],
            ['value' => FounderIncidentSeverity::CRITICAL, 'label' => 'Critical'],
            ['value' => FounderIncidentSeverity::HIGH, 'label' => 'High'],
            ['value' => FounderIncidentSeverity::MEDIUM, 'label' => 'Medium'],
            ['value' => FounderIncidentSeverity::LOW, 'label' => 'Low'],
        ];
    }

    public function categoryLabel(string $category): string
    {
        return match ($category) {
            'access' => 'Access',
            'organization_branch' => 'Organization / branch',
            'public_surface' => 'Public surface',
            'data_health' => 'Data health',
            default => $category,
        };
    }

    public function severityLabel(string $severity): string
    {
        return match ($severity) {
            FounderIncidentSeverity::CRITICAL => 'Critical',
            FounderIncidentSeverity::HIGH => 'High',
            FounderIncidentSeverity::MEDIUM => 'Medium',
            FounderIncidentSeverity::LOW => 'Low',
            default => $severity,
        };
    }
}
