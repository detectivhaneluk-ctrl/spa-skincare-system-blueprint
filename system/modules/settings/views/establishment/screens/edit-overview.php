<section class="settings-establishment">
    <?php
    $currentCurrency = trim((string) ($establishment['currency'] ?? ''));
    $currentTimezone = trim((string) ($establishment['timezone'] ?? ''));
    $currencyOptions = [
        'USD' => 'US Dollar',
        'EUR' => 'Euro',
        'GBP' => 'British Pound',
        'CHF' => 'Swiss Franc',
        'CAD' => 'Canadian Dollar',
        'AUD' => 'Australian Dollar',
        'NZD' => 'New Zealand Dollar',
        'JPY' => 'Japanese Yen',
        'CNY' => 'Chinese Yuan',
        'HKD' => 'Hong Kong Dollar',
        'SGD' => 'Singapore Dollar',
        'KRW' => 'South Korean Won',
        'INR' => 'Indian Rupee',
        'AED' => 'UAE Dirham',
        'SAR' => 'Saudi Riyal',
        'TRY' => 'Turkish Lira',
        'SEK' => 'Swedish Krona',
        'NOK' => 'Norwegian Krone',
        'DKK' => 'Danish Krone',
        'PLN' => 'Polish Zloty',
        'CZK' => 'Czech Koruna',
        'HUF' => 'Hungarian Forint',
        'RON' => 'Romanian Leu',
        'BGN' => 'Bulgarian Lev',
        'AMD' => 'Armenian Dram',
        'GEL' => 'Georgian Lari',
        'RUB' => 'Russian Ruble',
        'UAH' => 'Ukrainian Hryvnia',
        'ILS' => 'Israeli New Shekel',
        'ZAR' => 'South African Rand',
        'BRL' => 'Brazilian Real',
        'MXN' => 'Mexican Peso',
        'KZT' => 'Kazakhstani Tenge',
    ];
    $allTimezones = timezone_identifiers_list();
    $timezoneIndex = array_fill_keys($allTimezones, true);
    $hasKnownTimezone = $currentTimezone !== '' && isset($timezoneIndex[$currentTimezone]);
    $timezonePreferredOrder = [
        'Africa',
        'America',
        'Antarctica',
        'Arctic',
        'Asia',
        'Atlantic',
        'Australia',
        'Europe',
        'Indian',
        'Pacific',
    ];
    $timezoneGroups = [];
    foreach ($allTimezones as $timezone) {
        $region = strtok($timezone, '/');
        if ($region === false || $region === '') {
            $region = 'Other';
        }
        if (!isset($timezoneGroups[$region])) {
            $timezoneGroups[$region] = [];
        }
        $timezoneGroups[$region][] = $timezone;
    }
    ?>
    <header class="settings-establishment__hero">
        <h2 class="settings-establishment__title">Edit Establishment Overview</h2>
        <p class="settings-establishment__lead">This form updates only the currently write-backed establishment settings fields.</p>
    </header>

    <form method="post" action="/settings" class="settings-form settings-establishment">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="section" value="establishment">
        <input type="hidden" name="screen" value="edit-overview">

        <section class="settings-establishment-card">
            <div class="settings-establishment-form-grid">
                <div class="setting-row"><label for="establishment-name">Name</label><input type="text" id="establishment-name" name="settings[establishment.name]" value="<?= htmlspecialchars((string) ($establishment['name'] ?? '')) ?>"></div>
                <div class="setting-row"><label for="establishment-phone">Phone</label><input type="text" id="establishment-phone" name="settings[establishment.phone]" value="<?= htmlspecialchars((string) ($establishment['phone'] ?? '')) ?>"></div>
                <div class="setting-row"><label for="establishment-email">Email</label><input type="text" id="establishment-email" name="settings[establishment.email]" value="<?= htmlspecialchars((string) ($establishment['email'] ?? '')) ?>"></div>
                <div class="setting-row"><label for="establishment-language">Language</label><input type="text" id="establishment-language" name="settings[establishment.language]" value="<?= htmlspecialchars((string) ($establishment['language'] ?? '')) ?>" placeholder="e.g. en"></div>
                <div class="setting-row setting-row--full"><label for="establishment-address">Address</label><input type="text" id="establishment-address" name="settings[establishment.address]" value="<?= htmlspecialchars((string) ($establishment['address'] ?? '')) ?>"></div>
                <div class="setting-row">
                    <label for="establishment-currency">Currency</label>
                    <select id="establishment-currency" name="settings[establishment.currency]">
                        <option value="" <?= $currentCurrency === '' ? 'selected' : '' ?>>Select currency</option>
                        <?php if ($currentCurrency !== '' && !isset($currencyOptions[$currentCurrency])): ?>
                        <option value="<?= htmlspecialchars($currentCurrency) ?>" selected>CURRENT (legacy): <?= htmlspecialchars($currentCurrency) ?></option>
                        <?php endif; ?>
                        <?php foreach ($currencyOptions as $code => $label): ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= $currentCurrency === $code ? 'selected' : '' ?>><?= htmlspecialchars($code . ' - ' . $label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="setting-row">
                    <label for="establishment-timezone">Time Zone</label>
                    <select id="establishment-timezone" name="settings[establishment.timezone]">
                        <option value="" <?= $currentTimezone === '' ? 'selected' : '' ?>>Select time zone</option>
                        <?php if ($currentTimezone !== '' && !$hasKnownTimezone): ?>
                        <option value="<?= htmlspecialchars($currentTimezone) ?>" selected>CURRENT (legacy): <?= htmlspecialchars($currentTimezone) ?></option>
                        <?php endif; ?>
                        <?php foreach ($timezonePreferredOrder as $region): ?>
                            <?php if (!isset($timezoneGroups[$region])) { continue; } ?>
                            <optgroup label="<?= htmlspecialchars($region) ?>">
                                <?php foreach ($timezoneGroups[$region] as $timezone): ?>
                                <option value="<?= htmlspecialchars($timezone) ?>" <?= $currentTimezone === $timezone ? 'selected' : '' ?>><?= htmlspecialchars($timezone) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php unset($timezoneGroups[$region]); ?>
                        <?php endforeach; ?>
                        <?php foreach ($timezoneGroups as $region => $regionZones): ?>
                            <optgroup label="<?= htmlspecialchars($region) ?>">
                                <?php foreach ($regionZones as $timezone): ?>
                                <option value="<?= htmlspecialchars($timezone) ?>" <?= $currentTimezone === $timezone ? 'selected' : '' ?>><?= htmlspecialchars($timezone) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </section>

        <section class="settings-establishment-card">
            <div class="settings-establishment-actions">
                <button type="submit" class="settings-establishment-btn settings-establishment-btn--primary">Save Changes</button>
                <a class="settings-establishment-btn" href="<?= htmlspecialchars($establishmentUrl('overview')) ?>">Cancel</a>
            </div>
        </section>
    </form>
</section>
