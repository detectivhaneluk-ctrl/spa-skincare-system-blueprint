<?php

declare(strict_types=1);

/**
 * MARKETING-PROMOTIONS-RUNTIME-PROOF-AND-HONESTY-02
 *
 * Usage:
 *   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe system/scripts/dev-only/proof_marketing_promotions_runtime_honesty_02.php
 */

$base = dirname(__DIR__, 2);
require $base . '/bootstrap.php';
require $base . '/modules/bootstrap.php';

$db = app(\Core\App\Database::class);
$baseUrl = rtrim((string) config('app.url', 'http://127.0.0.1:8899'), '/');
$csrfName = (string) config('app.csrf_token_name', 'csrf_token');
$code = 'PROMO-RT-02-X';

echo 'base_url=' . $baseUrl . PHP_EOL;

$cleanupRows = $db->fetchAll(
    "SELECT id, branch_id
     FROM marketing_special_offers
     WHERE code = ?
       AND deleted_at IS NULL",
    [$code]
);
foreach ($cleanupRows as $row) {
    $db->query(
        'UPDATE marketing_special_offers SET deleted_at = NOW(), is_active = 0 WHERE id = ?',
        [(int) ($row['id'] ?? 0)]
    );
}
echo 'cleanup_active_rows_for_code=' . count($cleanupRows) . PHP_EOL;

/**
 * @return array{ch: CurlHandle,cookie:string}
 */
function httpClient(): array
{
    $cookie = tempnam(sys_get_temp_dir(), 'promo-proof-cookie-');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_TIMEOUT => 30,
    ]);

    return ['ch' => $ch, 'cookie' => $cookie];
}

/**
 * @return array{status:int,headers:string,body:string}
 */
function request(CurlHandle $ch, string $method, string $url, array $form = []): array
{
    curl_setopt($ch, CURLOPT_URL, $url);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, null);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = (string) substr($raw, 0, $headerSize);
    $body = (string) substr($raw, $headerSize);

    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

function extractCsrf(string $html, string $csrfName): string
{
    $needle = 'name="' . $csrfName . '" value="';
    $pos = strpos($html, $needle);
    if ($pos === false) {
        throw new RuntimeException('CSRF token not found in HTML.');
    }
    $start = $pos + strlen($needle);
    $end = strpos($html, '"', $start);
    if ($end === false) {
        throw new RuntimeException('CSRF token end quote not found.');
    }
    $value = substr($html, $start, $end - $start);

    return html_entity_decode((string) $value, ENT_QUOTES);
}

function loginToApp(CurlHandle $ch, string $baseUrl, string $csrfName, string $email, string $password): void
{
    $loginPage = request($ch, 'GET', $baseUrl . '/login');
    $csrf = extractCsrf($loginPage['body'], $csrfName);
    $post = request($ch, 'POST', $baseUrl . '/login', [
        $csrfName => $csrf,
        'email' => $email,
        'password' => $password,
    ]);
    if (!in_array($post['status'], [302, 303], true)) {
        throw new RuntimeException('Login POST did not redirect for ' . $email . '.');
    }
}

function logoutFromApp(CurlHandle $ch, string $baseUrl, string $csrfName): void
{
    $page = request($ch, 'GET', $baseUrl . '/marketing/promotions/special-offers');
    $csrf = extractCsrf($page['body'], $csrfName);
    request($ch, 'POST', $baseUrl . '/logout', [$csrfName => $csrf]);
}

$b11 = ['email' => 'promo_admin_b11@example.com', 'password' => 'PromoPass123!', 'branch_id' => 11];
$b12 = ['email' => 'promo_admin_b12@example.com', 'password' => 'PromoPass123!', 'branch_id' => 12];

$client = httpClient();
$ch = $client['ch'];

try {
    loginToApp($ch, $baseUrl, $csrfName, $b11['email'], $b11['password']);
    echo 'login_branch_11=pass' . PHP_EOL;

    $zero = request($ch, 'GET', $baseUrl . '/marketing/promotions/special-offers?name=__no_results__');
    echo 'empty_list_text_honest=' . (str_contains($zero['body'], 'Results 0 of 0') ? 'pass' : 'fail') . PHP_EOL;

    $main = request($ch, 'GET', $baseUrl . '/marketing/promotions/special-offers');
    echo 'reorganize_honest_message=' . (str_contains($main['body'], 'Reorganize Special Offers is not available yet') ? 'pass' : 'fail') . PHP_EOL;
    $csrfMain = extractCsrf($main['body'], $csrfName);

    $create = request($ch, 'POST', $baseUrl . '/marketing/promotions/special-offers', [
        $csrfName => $csrfMain,
        'name' => 'Runtime Proof Offer B11',
        'code' => $code,
        'origin' => 'manual',
        'adjustment_type' => 'percent',
        'adjustment_value' => '10',
        'offer_option' => 'internal_only',
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-30',
    ]);
    echo 'create_offer_http=' . (in_array($create['status'], [302, 303], true) ? 'pass' : 'fail') . PHP_EOL;

    $rowB11 = $db->fetchOne(
        "SELECT *
         FROM marketing_special_offers
         WHERE branch_id = ?
           AND code = ?
           AND deleted_at IS NULL
         ORDER BY id DESC
         LIMIT 1",
        [$b11['branch_id'], $code]
    );
    $offerIdB11 = (int) ($rowB11['id'] ?? 0);
    echo 'create_offer_persisted=' . ($offerIdB11 > 0 ? 'pass' : 'fail') . PHP_EOL;
    echo 'offer_option_persistence_truth=' . ((string) ($rowB11['offer_option'] ?? '') === 'internal_only' ? 'pass' : 'fail') . PHP_EOL;
    echo 'valid_date_window_saved=' . (((string) ($rowB11['start_date'] ?? '') === '2026-04-01' && (string) ($rowB11['end_date'] ?? '') === '2026-04-30') ? 'pass' : 'fail') . PHP_EOL;
    echo 'create_offer_starts_inactive_h006=' . (((int) ($rowB11['is_active'] ?? 1) === 0) ? 'pass' : 'fail') . PHP_EOL;

    $editPage = request($ch, 'GET', $baseUrl . '/marketing/promotions/special-offers');
    $csrfEdit = extractCsrf($editPage['body'], $csrfName);
    $update = request($ch, 'POST', $baseUrl . '/marketing/promotions/special-offers/' . $offerIdB11, [
        $csrfName => $csrfEdit,
        'name' => 'Runtime Proof Offer B11 Updated',
        'code' => $code,
        'origin' => 'auto',
        'adjustment_type' => 'fixed',
        'adjustment_value' => '88.80',
        'offer_option' => 'hide_from_customer',
        'start_date' => '2026-04-02',
        'end_date' => '2026-04-29',
    ]);
    $updatedRow = $db->fetchOne('SELECT * FROM marketing_special_offers WHERE id = ? LIMIT 1', [$offerIdB11]);
    $updatedOk = in_array($update['status'], [302, 303], true)
        && (string) ($updatedRow['origin'] ?? '') === 'auto'
        && (string) ($updatedRow['adjustment_type'] ?? '') === 'fixed'
        && (string) ($updatedRow['offer_option'] ?? '') === 'hide_from_customer';
    echo 'edit_update_offer=' . ($updatedOk ? 'pass' : 'fail') . PHP_EOL;

    $dupPage = request($ch, 'GET', $baseUrl . '/marketing/promotions/special-offers');
    $csrfDup = extractCsrf($dupPage['body'], $csrfName);
    request($ch, 'POST', $baseUrl . '/marketing/promotions/special-offers', [
        $csrfName => $csrfDup,
        'name' => 'Runtime Duplicate B11',
        'code' => $code,
        'origin' => 'manual',
        'adjustment_type' => 'percent',
        'adjustment_value' => '5',
        'offer_option' => 'all',
        'start_date' => '',
        'end_date' => '',
    ]);
    $dupLanding = request($ch, 'GET', $baseUrl . '/marketing/promotions/special-offers');
    echo 'same_branch_duplicate_rejected=' . (str_contains($dupLanding['body'], 'Promo code already exists in this branch.') ? 'pass' : 'fail') . PHP_EOL;

    // H-006: UI cannot activate; only legacy is_active=1 rows can POST toggle to clear the flag.
    $db->query('UPDATE marketing_special_offers SET is_active = 1 WHERE id = ?', [$offerIdB11]);
    $togglePage = request($ch, 'GET', $baseUrl . '/marketing/promotions/special-offers');
    $csrfToggle = extractCsrf($togglePage['body'], $csrfName);
    request($ch, 'POST', $baseUrl . '/marketing/promotions/special-offers/' . $offerIdB11 . '/toggle-active', [$csrfName => $csrfToggle]);
    $afterToggle1 = $db->fetchOne('SELECT is_active FROM marketing_special_offers WHERE id = ? LIMIT 1', [$offerIdB11]);
    $toggle1Ok = (int) ($afterToggle1['is_active'] ?? 1) === 0;
    $toggleList1 = request($ch, 'GET', $baseUrl . '/marketing/promotions/special-offers');
    $toggle1Ui = str_contains($toggleList1['body'], 'Legacy') || str_contains($toggleList1['body'], 'admin-only');
    $togglePage2 = request($ch, 'GET', $baseUrl . '/marketing/promotions/special-offers');
    $csrfToggle2 = extractCsrf($togglePage2['body'], $csrfName);
    request($ch, 'POST', $baseUrl . '/marketing/promotions/special-offers/' . $offerIdB11 . '/toggle-active', [$csrfName => $csrfToggle2]);
    $afterToggle2 = $db->fetchOne('SELECT is_active FROM marketing_special_offers WHERE id = ? LIMIT 1', [$offerIdB11]);
    $toggleList2 = request($ch, 'GET', $baseUrl . '/marketing/promotions/special-offers');
    $toggle2Ok = (int) ($afterToggle2['is_active'] ?? 0) === 0
        && str_contains($toggleList2['body'], 'Cannot activate');
    echo 'legacy_clear_then_activate_blocked_h006=' . (($toggle1Ok && $toggle2Ok && $toggle1Ui) ? 'pass' : 'fail') . PHP_EOL;

    $badDatePage = request($ch, 'GET', $baseUrl . '/marketing/promotions/special-offers');
    $csrfBadDate = extractCsrf($badDatePage['body'], $csrfName);
    request($ch, 'POST', $baseUrl . '/marketing/promotions/special-offers/' . $offerIdB11, [
        $csrfName => $csrfBadDate,
        'name' => 'Bad Date Attempt',
        'code' => $code,
        'origin' => 'manual',
        'adjustment_type' => 'percent',
        'adjustment_value' => '9',
        'offer_option' => 'all',
        'start_date' => '2026-05-01',
        'end_date' => '2026-04-01',
    ]);
    $badDateLanding = request($ch, 'GET', $baseUrl . '/marketing/promotions/special-offers');
    echo 'invalid_date_window_rejected=' . (str_contains($badDateLanding['body'], 'End date cannot be before start date.') ? 'pass' : 'fail') . PHP_EOL;

    $delPage = request($ch, 'GET', $baseUrl . '/marketing/promotions/special-offers');
    $csrfDelete = extractCsrf($delPage['body'], $csrfName);
    request($ch, 'POST', $baseUrl . '/marketing/promotions/special-offers/' . $offerIdB11 . '/delete', [$csrfName => $csrfDelete]);
    $deletedRow = $db->fetchOne('SELECT deleted_at, is_active FROM marketing_special_offers WHERE id = ? LIMIT 1', [$offerIdB11]);
    $softDeleted = !empty($deletedRow['deleted_at']) && (int) ($deletedRow['is_active'] ?? 1) === 0;
    echo 'soft_delete_works=' . ($softDeleted ? 'pass' : 'fail') . PHP_EOL;

    logoutFromApp($ch, $baseUrl, $csrfName);
    echo 'logout_branch_11=pass' . PHP_EOL;

    loginToApp($ch, $baseUrl, $csrfName, $b12['email'], $b12['password']);
    echo 'login_branch_12=pass' . PHP_EOL;
    $b12Page = request($ch, 'GET', $baseUrl . '/marketing/promotions/special-offers');
    $csrfB12 = extractCsrf($b12Page['body'], $csrfName);
    $createB12 = request($ch, 'POST', $baseUrl . '/marketing/promotions/special-offers', [
        $csrfName => $csrfB12,
        'name' => 'Runtime Proof Offer B12',
        'code' => $code,
        'origin' => 'manual',
        'adjustment_type' => 'percent',
        'adjustment_value' => '6',
        'offer_option' => 'internal_only',
        'start_date' => '',
        'end_date' => '',
    ]);
    $rowB12 = $db->fetchOne(
        "SELECT id, offer_option
         FROM marketing_special_offers
         WHERE branch_id = ?
           AND code = ?
           AND deleted_at IS NULL
         ORDER BY id DESC
         LIMIT 1",
        [$b12['branch_id'], $code]
    );
    $crossBranchAllowed = in_array($createB12['status'], [302, 303], true) && !empty($rowB12['id']);
    echo 'cross_branch_duplicate_allowed=' . ($crossBranchAllowed ? 'pass' : 'fail') . PHP_EOL;
    echo 'branch_12_offer_option_persisted=' . (((string) ($rowB12['offer_option'] ?? '') === 'internal_only') ? 'pass' : 'fail') . PHP_EOL;
} finally {
    curl_close($ch);
    @unlink($client['cookie']);
}
