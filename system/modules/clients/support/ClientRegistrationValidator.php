<?php

declare(strict_types=1);

namespace Modules\Clients\Support;

/**
 * Validates manual registration intake: full_name, phone, email, notes.
 * Client record layouts use split name + structured phone block; the fixed `customer_details` prefix in
 * {@see \Modules\Clients\Services\ClientFieldCatalogService::customerDetailsImmutablePrefixKeys()} matches that model.
 */
final class ClientRegistrationValidator
{
    /**
     * @return array<string, string> field => message
     */
    public static function validateCreate(array $data): array
    {
        $errors = [];

        $fullName = trim((string) ($data['full_name'] ?? ''));
        if ($fullName === '' || mb_strlen($fullName) > 200) {
            $errors['full_name'] = 'Full name is required and must be under 200 characters.';
        }

        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255)) {
            $errors['email'] = 'Please provide a valid email address.';
        }

        $phone = trim((string) ($data['phone'] ?? ''));
        if ($phone !== '') {
            $digits = preg_replace('/\D+/', '', $phone) ?? '';
            if ($digits === '' || strlen($digits) < 7 || strlen($digits) > 20) {
                $errors['phone'] = 'Please provide a valid phone number.';
            }
        }

        $source = trim((string) ($data['source'] ?? 'manual'));
        if ($source === '') {
            $source = 'manual';
        }
        $allowedSources = ['manual', 'phone_call', 'web_form', 'online_booking'];
        if (!in_array($source, $allowedSources, true)) {
            $errors['source'] = 'Invalid registration source.';
        }

        $notes = trim((string) ($data['notes'] ?? ''));
        if (mb_strlen($notes) > 5000) {
            $errors['notes'] = 'Notes must be 5000 characters or fewer.';
        }

        return $errors;
    }
}
