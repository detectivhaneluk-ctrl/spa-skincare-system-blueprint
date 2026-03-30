<?php

declare(strict_types=1);

namespace Modules\Clients\Services;

use Modules\Clients\Support\PublicContactNormalizer;

/**
 * Server-side validation for staff client create/update payloads (messages kept practical / backward-compatible).
 */
final class ClientInputValidator
{
    /**
     * @param array<string, mixed> $data Parsed client payload (see {@see \Modules\Clients\Controllers\ClientController})
     * @param list<array<string, mixed>> $customFieldDefinitions Active definitions for the current branch/org context
     * @return array<string, string> field => message
     */
    public function validate(array $data, array $customFieldDefinitions = []): array
    {
        $errors = [];
        if (($data['first_name'] ?? '') === '') {
            $errors['first_name'] = 'First name is required.';
        }
        if (($data['last_name'] ?? '') === '') {
            $errors['last_name'] = 'Last name is required.';
        }
        $email = $data['email'] ?? null;
        if ($email !== null && $email !== '') {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'Please enter a valid email address.';
            } elseif (strlen($email) > 255) {
                $errors['email'] = 'This field is too long.';
            }
        }
        $pcm = $data['preferred_contact_method'] ?? null;
        if ($pcm !== null && $pcm !== '' && !in_array((string) $pcm, ['phone', 'email', 'sms'], true)) {
            $errors['preferred_contact_method'] = 'Preferred contact method must be phone, email, or sms.';
        }
        $gender = $data['gender'] ?? null;
        if ($gender !== null && $gender !== '') {
            if (!in_array((string) $gender, ['male', 'female', 'other'], true)) {
                $errors['gender'] = 'Gender value is not allowed.';
            }
        }
        foreach (['birth_date', 'anniversary'] as $dateKey) {
            $v = $data[$dateKey] ?? null;
            if ($v !== null && $v !== '') {
                if (!$this->isReasonableSqlDate((string) $v)) {
                    $errors[$dateKey] = 'Enter a valid date (YYYY-MM-DD).';
                }
            }
        }
        $phoneKeys = ['phone_home', 'phone_mobile', 'phone_work', 'emergency_contact_phone'];
        foreach ($phoneKeys as $pk) {
            $raw = $data[$pk] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            $s = trim((string) $raw);
            if (strlen($s) > 50) {
                $errors[$pk] = 'This field is too long.';
                continue;
            }
            $digits = preg_replace('/\D+/', '', $s);
            if ($digits !== null && strlen($digits) > 20) {
                $errors[$pk] = 'Phone number has too many digits.';
            }
        }
        $maxLens = [
            'phone_home' => 50,
            'phone_mobile' => 50,
            'mobile_operator' => 100,
            'phone_work' => 50,
            'phone_work_ext' => 30,
            'home_address_1' => 255,
            'home_address_2' => 255,
            'home_city' => 120,
            'home_postal_code' => 32,
            'home_country' => 100,
            'delivery_address_1' => 255,
            'delivery_address_2' => 255,
            'delivery_city' => 120,
            'delivery_postal_code' => 32,
            'delivery_country' => 100,
            'occupation' => 200,
            'language' => 50,
            'booking_alert' => 500,
            'check_in_alert' => 500,
            'check_out_alert' => 500,
            'referred_by' => 200,
            'customer_origin' => 120,
            'emergency_contact_name' => 200,
            'emergency_contact_phone' => 50,
            'emergency_contact_relationship' => 120,
        ];
        foreach ($maxLens as $key => $max) {
            $val = $data[$key] ?? null;
            if ($val !== null && $val !== '' && mb_strlen((string) $val) > $max) {
                $errors[$key] = 'This field is too long.';
            }
        }
        if (!in_array((int) ($data['delivery_same_as_home'] ?? 0), [0, 1], true)) {
            $errors['delivery_same_as_home'] = 'Invalid value.';
        }
        $values = is_array($data['custom_fields'] ?? null) ? $data['custom_fields'] : [];
        foreach ($customFieldDefinitions as $def) {
            $id = (int) $def['id'];
            if ((int) ($def['is_required'] ?? 0) !== 1) {
                continue;
            }
            $raw = $values[$id] ?? '';
            if (($def['field_type'] ?? '') === 'boolean') {
                if ($raw === '' || $raw === null) {
                    $errors['custom_field_' . $id] = (string) $def['label'] . ' is required.';
                }
            } else {
                $val = trim((string) $raw);
                if ($val === '') {
                    $errors['custom_field_' . $id] = (string) $def['label'] . ' is required.';
                }
            }
        }
        foreach ($customFieldDefinitions as $def) {
            $id = (int) $def['id'];
            $ek = 'custom_field_' . $id;
            if (isset($errors[$ek])) {
                continue;
            }
            $val = trim((string) ($values[$id] ?? ''));
            if ($val === '') {
                continue;
            }
            $ft = (string) ($def['field_type'] ?? '');
            if ($ft === 'email' && filter_var($val, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$ek] = 'Please enter a valid email address.';
            }
            if ($ft === 'phone') {
                $d = PublicContactNormalizer::normalizePhoneDigitsForMatch($val);
                if ($d === null) {
                    $errors[$ek] = 'Phone number is not valid.';
                }
            }
        }

        return $errors;
    }

    private function isReasonableSqlDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $parts = array_map('intval', explode('-', $value));
        if (count($parts) !== 3) {
            return false;
        }
        [$y, $m, $d] = $parts;
        if ($y < 1900 || $y > 2100) {
            return false;
        }

        return checkdate($m, $d, $y);
    }
}
