<?php

declare(strict_types=1);

namespace Modules\Clients\Services;

/**
 * Canonical catalog of system field keys for layouts and admin. Custom fields use keys {@code custom:{id}}.
 *
 * {@see self::customerDetailsImmutablePrefixKeys()} — fixed prefix for the customer_details layout, aligned with
 * manual registration intake ({@see ClientRegistrationValidator}) and new-client essentials (name, email, phones, notes).
 */
final class ClientFieldCatalogService
{
    public const CUSTOM_KEY_PREFIX = 'custom:';

    /**
     * Keys that must stay present, in this order, at the start of the `customer_details` layout.
     * Operators can add fields after this block (e.g. custom “skin type”) but cannot remove or reorder these.
     *
     * @return list<string>
     */
    public function customerDetailsImmutablePrefixKeys(): array
    {
        return ['first_name', 'last_name', 'email', 'phone_contact_block', 'notes'];
    }

    public function isCustomerDetailsImmutableKey(string $key): bool
    {
        return in_array($key, $this->customerDetailsImmutablePrefixKeys(), true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function systemFieldDefinitions(): array
    {
        $s = static fn (string $label, string $column, string $type, bool $cfg = true, bool $inDetails = true, bool $inSidebar = false): array => [
            'label' => $label,
            'kind' => 'system_scalar',
            'column' => $column,
            'admin_field_type' => $type,
            'configurable' => $cfg,
            'source' => 'system',
            'details_profile_default' => $inDetails,
            'sidebar_profile_default' => $inSidebar,
        ];

        return [
            'first_name' => $s('First name', 'first_name', 'single_line_text', false) + ['details_profile_default' => true],
            'last_name' => $s('Last name', 'last_name', 'single_line_text', false) + ['details_profile_default' => true],
            'email' => $s('Email', 'email', 'email', true, true, true),
            'phone_contact_block' => [
                'label' => 'Phone numbers',
                'kind' => 'block',
                'block' => 'phone_contact',
                'admin_field_type' => 'summary_read_only',
                'configurable' => true,
                'source' => 'system',
                'details_profile_default' => true,
                'sidebar_profile_default' => false,
            ],
            'home_address_block' => [
                'label' => 'Home address',
                'kind' => 'block',
                'block' => 'home_address',
                'admin_field_type' => 'address',
                'configurable' => true,
                'source' => 'system',
                'details_profile_default' => true,
                'sidebar_profile_default' => false,
            ],
            'delivery_block' => [
                'label' => 'Delivery address & same-as-home',
                'kind' => 'block',
                'block' => 'delivery',
                'admin_field_type' => 'address',
                'configurable' => true,
                'source' => 'system',
                'details_profile_default' => true,
                'sidebar_profile_default' => false,
            ],
            'birth_date' => $s('Birth date', 'birth_date', 'date'),
            'anniversary' => $s('Important date / anniversary', 'anniversary', 'date'),
            'occupation' => $s('Occupation', 'occupation', 'single_line_text'),
            'gender' => $s('Gender', 'gender', 'picklist'),
            'language' => $s('Language', 'language', 'single_line_text'),
            'preferred_contact_method' => $s('Preferred contact method', 'preferred_contact_method', 'picklist'),
            'receive_emails' => $s('Receive emails (transactional)', 'receive_emails', 'boolean'),
            'receive_sms' => $s('Receive SMS', 'receive_sms', 'boolean'),
            'marketing_opt_in' => $s('Marketing opt-in (legacy)', 'marketing_opt_in', 'boolean'),
            'booking_alert' => $s('Booking alert text', 'booking_alert', 'paragraph_text'),
            'check_in_alert' => $s('Check-in alert text', 'check_in_alert', 'paragraph_text'),
            'check_out_alert' => $s('Check-out alert text', 'check_out_alert', 'paragraph_text'),
            'referral_information' => $s('Referral information', 'referral_information', 'paragraph_text'),
            'referral_history' => $s('Referral history', 'referral_history', 'paragraph_text'),
            'referred_by' => $s('Referred by', 'referred_by', 'single_line_text'),
            'customer_origin' => $s('Customer origin', 'customer_origin', 'single_line_text'),
            'emergency_contact_name' => $s('Emergency contact name', 'emergency_contact_name', 'single_line_text'),
            'emergency_contact_phone' => $s('Emergency contact phone', 'emergency_contact_phone', 'phone'),
            'emergency_contact_relationship' => $s('Emergency contact relationship', 'emergency_contact_relationship', 'single_line_text'),
            'inactive_flag' => $s('Inactive flag', 'inactive_flag', 'boolean', true, true, true),
            'notes' => $s('Notes', 'notes', 'paragraph_text'),
            'summary_primary_phone' => [
                'label' => 'Primary phone (display)',
                'kind' => 'computed_display',
                'admin_field_type' => 'summary_read_only',
                'configurable' => true,
                'source' => 'system',
                'details_profile_default' => false,
                'sidebar_profile_default' => true,
            ],
        ];
    }

    /**
     * System fields shown on the Client Fields settings screen: only fields the org can place on layouts.
     * Immutable rows (e.g. first name, last name) are omitted so operators are not shown data they cannot control.
     *
     * @return array<string, array<string, mixed>>
     */
    public function systemFieldsConfigurableForLayouts(): array
    {
        return array_filter(
            $this->systemFieldDefinitions(),
            static fn (array $meta): bool => !empty($meta['configurable'])
        );
    }

    /**
     * Human-readable field format for admin UI (replaces developer-oriented type keys).
     */
    public static function humanizeFieldTypeLabel(string $type): string
    {
        $t = trim($type);
        if ($t === '') {
            return '';
        }

        $map = [
            'single_line_text' => 'Text',
            'paragraph_text' => 'Long text',
            'summary_read_only' => 'Auto-generated',
            'picklist' => 'Choice',
            'boolean' => 'On / off',
            'email' => 'Email',
            'phone' => 'Phone',
            'address' => 'Address',
            'date' => 'Date',
            'text' => 'Text',
            'textarea' => 'Long text',
            'number' => 'Number',
            'select' => 'Choice',
            'multiselect' => 'Multiple choices',
        ];

        if (isset($map[$t])) {
            return $map[$t];
        }

        $normalized = str_replace(['-', '_'], ' ', strtolower($t));

        return ucwords($normalized);
    }

    public function isSystemFieldKey(string $key): bool
    {
        return $key !== '' && !str_starts_with($key, self::CUSTOM_KEY_PREFIX) && isset($this->systemFieldDefinitions()[$key]);
    }

    public function parseCustomFieldId(string $key): ?int
    {
        if (!str_starts_with($key, self::CUSTOM_KEY_PREFIX)) {
            return null;
        }
        $id = (int) substr($key, strlen(self::CUSTOM_KEY_PREFIX));

        return $id > 0 ? $id : null;
    }

    public function customFieldLayoutKey(int $definitionId): string
    {
        return self::CUSTOM_KEY_PREFIX . $definitionId;
    }
}
