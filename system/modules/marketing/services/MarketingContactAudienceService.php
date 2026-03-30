<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use Modules\Marketing\Repositories\MarketingContactAudienceRepository;
use Modules\Marketing\Support\MarketingContactEligibilityPolicy;

final class MarketingContactAudienceService
{
    public const AUDIENCE_ALL_CONTACTS = 'all_contacts';
    public const AUDIENCE_MARKETING_EMAIL_ELIGIBLE = 'marketing_email_eligible';
    public const AUDIENCE_MARKETING_SMS_ELIGIBLE = 'marketing_sms_eligible';
    public const AUDIENCE_BIRTHDAY_THIS_MONTH = 'birthday_this_month';
    public const AUDIENCE_FIRST_TIME_VISITORS = 'first_time_visitors';
    public const AUDIENCE_NO_RECENT_VISIT_45_DAYS = 'no_recent_visit_45_days';
    public const AUDIENCE_MANUAL_LIST = 'manual_list';

    public function __construct(private MarketingContactAudienceRepository $repo)
    {
    }

    /**
     * @return list<array{key:string,label:string,kind:string}>
     */
    public function smartListDefinitions(): array
    {
        return [
            ['key' => self::AUDIENCE_ALL_CONTACTS, 'label' => 'All Contacts', 'kind' => 'system'],
            ['key' => self::AUDIENCE_MARKETING_EMAIL_ELIGIBLE, 'label' => 'Marketing Email Eligible', 'kind' => 'smart'],
            ['key' => self::AUDIENCE_MARKETING_SMS_ELIGIBLE, 'label' => 'Marketing SMS Eligible', 'kind' => 'smart'],
            ['key' => self::AUDIENCE_BIRTHDAY_THIS_MONTH, 'label' => 'Birthday This Month', 'kind' => 'smart'],
            ['key' => self::AUDIENCE_FIRST_TIME_VISITORS, 'label' => 'First Time Visitors', 'kind' => 'smart'],
            ['key' => self::AUDIENCE_NO_RECENT_VISIT_45_DAYS, 'label' => 'No Recent Visit (45 days)', 'kind' => 'smart'],
        ];
    }

    /**
     * @return array{audience_key:string,manual_list_id:int|null,contacts:list<array<string,mixed>>,total:int}
     */
    public function readAudience(
        int $branchId,
        string $audienceKey,
        ?int $manualListId,
        string $search,
        int $limit,
        int $offset
    ): array {
        $resolved = $this->resolveAudienceKey($audienceKey);
        $effectiveManualListId = $resolved === self::AUDIENCE_MANUAL_LIST ? ($manualListId !== null && $manualListId > 0 ? $manualListId : null) : null;
        $rows = $this->repo->listContacts($branchId, $resolved, $effectiveManualListId, $search, $limit, $offset);
        $total = $this->repo->countContacts($branchId, $resolved, $effectiveManualListId, $search);
        $contacts = [];
        foreach ($rows as $row) {
            $contacts[] = $this->normalizeContactRow($row);
        }

        return [
            'audience_key' => $resolved,
            'manual_list_id' => $effectiveManualListId,
            'contacts' => $contacts,
            'total' => $total,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function smartListCounts(int $branchId): array
    {
        $out = [];
        foreach ($this->smartListDefinitions() as $def) {
            $key = (string) ($def['key'] ?? '');
            $out[$key] = $this->repo->countForAudience($branchId, $key);
        }

        return $out;
    }

    public function resolveAudienceKey(string $raw): string
    {
        $key = trim($raw);
        if ($key === '') {
            return self::AUDIENCE_ALL_CONTACTS;
        }
        foreach ($this->smartListDefinitions() as $def) {
            if ((string) ($def['key'] ?? '') === $key) {
                return $key;
            }
        }
        if ($key === self::AUDIENCE_MANUAL_LIST) {
            return $key;
        }

        return self::AUDIENCE_ALL_CONTACTS;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeContactRow(array $row): array
    {
        $email = trim((string) ($row['email'] ?? ''));
        $mobile = trim((string) ($row['mobile_phone'] ?? ''));
        $hasEmail = $email !== '';
        $hasMobile = $mobile !== '';
        $emailEligible = MarketingContactEligibilityPolicy::emailEligible($row);
        $smsEligible = MarketingContactEligibilityPolicy::smsEligible($row);
        $marketingOptIn = (int) ($row['marketing_opt_in'] ?? 0) === 1;

        return [
            'contact_id' => 'client_' . (int) ($row['client_id'] ?? 0),
            'client_id' => (int) ($row['client_id'] ?? 0),
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'email' => $email,
            'mobile_phone' => $mobile,
            'email_marketing_eligible' => $emailEligible,
            'sms_marketing_eligible' => $smsEligible,
            'has_email' => $hasEmail,
            'has_mobile' => $hasMobile,
            'unsubscribed' => !$marketingOptIn,
            'blocked' => false,
            'last_visit_at' => isset($row['last_visit_at']) && $row['last_visit_at'] !== null ? (string) $row['last_visit_at'] : null,
            'birthday' => isset($row['birthday']) && $row['birthday'] !== null ? (string) $row['birthday'] : null,
            'created_at' => isset($row['created_at']) && $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'completed_visits' => (int) ($row['completed_visits'] ?? 0),
        ];
    }
}

