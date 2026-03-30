<?php

declare(strict_types=1);

namespace Core\Observability;

/**
 * Aggregated probe output for one run.
 */
final class BackendHealthReport
{
    /**
     * @param list<BackendHealthLayerSnapshot> $layers
     */
    public function __construct(
        public readonly array $layers,
        public readonly string $overallStatus,
        public readonly int $exitCode,
        public readonly string $overallSummary,
    ) {
    }

    /**
     * @return array{
     *   schema: string,
     *   overall_status: string,
     *   exit_code: int,
     *   overall_summary: string,
     *   layers: list<array{layer:string,status:string,reason_codes:list<string>,summary:string}>
     * }
     */
    public function toJsonArray(): array
    {
        return [
            'schema' => 'spa_backend_health_v1',
            'overall_status' => $this->overallStatus,
            'exit_code' => $this->exitCode,
            'overall_summary' => $this->overallSummary,
            'layers' => array_map(static fn (BackendHealthLayerSnapshot $s) => $s->toArray(), $this->layers),
        ];
    }
}
