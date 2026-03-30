<?php

declare(strict_types=1);

namespace Modules\Clients\Services;

use Core\App\SettingsService;
use Core\Audit\AuditService;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Clients\Support\PublicContactNormalizer;

/**
 * Canonical anonymous public client resolution for online booking and public commerce.
 * Strict deterministic matching only; never merges two existing clients; never exposes existence via HTTP.
 */
final class PublicClientResolutionService
{
    public const REASON_MATCHED_EXISTING = 'matched_existing';

    public const REASON_CREATED_INSUFFICIENT_MATCH = 'created_new_insufficient_match';

    public const REASON_CREATED_CONFLICT = 'created_new_conflict';

    public const REASON_CREATED_MISSING_FIELDS = 'created_new_missing_fields';

    public function __construct(
        private ClientRepository $clientRepo,
        private AuditService $audit,
        private SettingsService $settings
    ) {
    }

    /**
     * @param array{first_name?:mixed,last_name?:mixed,email?:mixed,phone?:mixed} $payload
     * @param 'public_booking'|'public_commerce' $source
     * @return array{client_id: int, created: bool, reason: string, match_rule: ?string}
     */
    public function resolve(int $branchId, array $payload, string $source, bool $allowNewClients): array
    {
        $firstName = trim((string) ($payload['first_name'] ?? ''));
        $lastName = trim((string) ($payload['last_name'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            $this->audit->log('public_client_resolution', 'client', null, null, $branchId, [
                'source' => $source,
                'resolution' => self::REASON_CREATED_MISSING_FIELDS,
                'detail' => 'missing_name',
            ]);
            throw new \InvalidArgumentException('first_name and last_name are required.');
        }

        $emailRaw = trim((string) ($payload['email'] ?? ''));
        if ($emailRaw === '') {
            $this->audit->log('public_client_resolution', 'client', null, null, $branchId, [
                'source' => $source,
                'resolution' => self::REASON_CREATED_MISSING_FIELDS,
                'detail' => 'missing_email',
            ]);
            throw new \InvalidArgumentException('email is required.');
        }

        try {
            $emailNorm = PublicContactNormalizer::normalizeEmail($emailRaw);
        } catch (\InvalidArgumentException) {
            $this->audit->log('public_client_resolution', 'client', null, null, $branchId, [
                'source' => $source,
                'resolution' => self::REASON_CREATED_MISSING_FIELDS,
                'detail' => 'invalid_email',
            ]);
            throw new \InvalidArgumentException('email is invalid.');
        }

        $phoneRaw = isset($payload['phone']) ? (string) $payload['phone'] : '';
        $phoneDigits = PublicContactNormalizer::normalizePhoneDigitsForMatch($phoneRaw !== '' ? $phoneRaw : null);
        $phoneForCreate = $phoneRaw !== '' ? trim($phoneRaw) : null;

        $emailRow = $this->clientRepo->lockActiveByEmailBranch($branchId, $emailNorm);
        if ($emailRow !== null) {
            $eid = (int) ($emailRow['id'] ?? 0);
            if ($eid <= 0) {
                throw new \RuntimeException('Invalid client row.');
            }
            if ($phoneDigits !== null) {
                $otherId = $this->clientRepo->findActiveClientIdByPhoneDigitsExcluding($branchId, $phoneDigits, $eid);
                if ($otherId !== null) {
                    return $this->createNewClient(
                        $branchId,
                        $firstName,
                        $lastName,
                        $emailNorm,
                        $phoneForCreate,
                        $source,
                        self::REASON_CREATED_CONFLICT,
                        $allowNewClients,
                        'email_client_differs_from_phone_client'
                    );
                }
            }

            $this->audit->log('public_client_resolution', 'client', $eid, null, $branchId, [
                'source' => $source,
                'resolution' => self::REASON_MATCHED_EXISTING,
                'client_created' => false,
                'match_rule' => 'email_exact_branch',
            ]);

            return [
                'client_id' => $eid,
                'created' => false,
                'reason' => self::REASON_MATCHED_EXISTING,
                'match_rule' => 'email_exact_branch',
            ];
        }

        if ($phoneDigits !== null) {
            $phoneRows = $this->clientRepo->lockActiveByPhoneDigitsBranch($branchId, $phoneDigits);
            if (count($phoneRows) === 1) {
                $pid = (int) ($phoneRows[0]['id'] ?? 0);
                if ($pid <= 0) {
                    throw new \RuntimeException('Invalid client row.');
                }
                $this->audit->log('public_client_resolution', 'client', $pid, null, $branchId, [
                    'source' => $source,
                    'resolution' => self::REASON_MATCHED_EXISTING,
                    'client_created' => false,
                    'match_rule' => 'phone_digits_branch',
                ]);

                return [
                    'client_id' => $pid,
                    'created' => false,
                    'reason' => self::REASON_MATCHED_EXISTING,
                    'match_rule' => 'phone_digits_branch',
                ];
            }
            if (count($phoneRows) > 1) {
                return $this->createNewClient(
                    $branchId,
                    $firstName,
                    $lastName,
                    $emailNorm,
                    $phoneForCreate,
                    $source,
                    self::REASON_CREATED_INSUFFICIENT_MATCH,
                    $allowNewClients,
                    'ambiguous_phone_digits'
                );
            }
        }

        return $this->createNewClient(
            $branchId,
            $firstName,
            $lastName,
            $emailNorm,
            $phoneForCreate,
            $source,
            self::REASON_CREATED_INSUFFICIENT_MATCH,
            $allowNewClients,
            'no_existing_identity_match'
        );
    }

    /**
     * @return array{client_id: int, created: bool, reason: string, match_rule: null}
     */
    private function createNewClient(
        int $branchId,
        string $firstName,
        string $lastName,
        string $emailNorm,
        ?string $phoneForCreate,
        string $source,
        string $reason,
        bool $allowNewClients,
        string $detail
    ): array {
        if (!$allowNewClients) {
            throw new \InvalidArgumentException('new clients not allowed');
        }

        $marketing = $this->settings->getMarketingSettings($branchId);
        $defaultOptIn = !empty($marketing['default_opt_in']) ? 1 : 0;
        $id = $this->clientRepo->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $emailNorm,
            'phone' => $phoneForCreate,
            'branch_id' => $branchId,
            'marketing_opt_in' => $defaultOptIn,
            'created_by' => null,
            'updated_by' => null,
        ]);
        $this->audit->log('client_created', 'client', $id, null, $branchId, [
            'client' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $emailNorm,
                'source' => $source,
            ],
            'public_resolution' => $reason,
            'resolution_detail' => $detail,
        ]);
        $this->audit->log('public_client_resolution', 'client', $id, null, $branchId, [
            'source' => $source,
            'resolution' => $reason,
            'client_created' => true,
            'match_rule' => null,
            'detail' => $detail,
        ]);

        return [
            'client_id' => $id,
            'created' => true,
            'reason' => $reason,
            'match_rule' => null,
        ];
    }
}
