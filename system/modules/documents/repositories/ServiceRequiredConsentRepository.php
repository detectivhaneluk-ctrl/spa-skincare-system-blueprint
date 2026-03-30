<?php

declare(strict_types=1);

namespace Modules\Documents\Repositories;

use Core\App\Database;

final class ServiceRequiredConsentRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return list<int> document_definition_id
     */
    public function getRequiredDefinitionIds(int $serviceId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT document_definition_id FROM service_required_consents WHERE service_id = ?',
            [$serviceId]
        );
        return array_map(static fn (array $r): int => (int) $r['document_definition_id'], $rows);
    }

    public function setRequired(int $serviceId, array $documentDefinitionIds): void
    {
        $this->db->query('DELETE FROM service_required_consents WHERE service_id = ?', [$serviceId]);
        foreach ($documentDefinitionIds as $defId) {
            $this->db->insert('service_required_consents', [
                'service_id' => $serviceId,
                'document_definition_id' => (int) $defId,
            ]);
        }
    }

    public function addRequired(int $serviceId, int $documentDefinitionId): void
    {
        $this->db->insert('service_required_consents', [
            'service_id' => $serviceId,
            'document_definition_id' => $documentDefinitionId,
        ]);
    }

    public function removeRequired(int $serviceId, int $documentDefinitionId): void
    {
        $this->db->query(
            'DELETE FROM service_required_consents WHERE service_id = ? AND document_definition_id = ?',
            [$serviceId, $documentDefinitionId]
        );
    }
}
