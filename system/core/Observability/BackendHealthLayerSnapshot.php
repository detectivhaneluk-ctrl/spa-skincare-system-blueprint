<?php

declare(strict_types=1);

namespace Core\Observability;

/**
 * @param non-empty-string $layer
 * @param list<string> $reasonCodes
 */
final class BackendHealthLayerSnapshot
{
    public function __construct(
        public readonly string $layer,
        public readonly string $status,
        public readonly array $reasonCodes,
        public readonly string $summary,
    ) {
    }

    /**
     * @return array{layer:string,status:string,reason_codes:list<string>,summary:string}
     */
    public function toArray(): array
    {
        return [
            'layer' => $this->layer,
            'status' => $this->status,
            'reason_codes' => $this->reasonCodes,
            'summary' => $this->summary,
        ];
    }
}
