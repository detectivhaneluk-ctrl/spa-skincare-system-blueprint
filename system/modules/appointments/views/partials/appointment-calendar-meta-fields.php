<?php
declare(strict_types=1);

use Modules\Appointments\Services\CalendarBadgeRegistry;

$calMeta = [];
$rawCal = $appointment['appointment_calendar_meta'] ?? null;
if ($rawCal !== null && $rawCal !== '') {
    $decodedCal = json_decode((string) $rawCal, true);
    if (is_array($decodedCal)) {
        $calMeta = $decodedCal;
    }
}
$bsSel = (string) ($calMeta['booking_source'] ?? '');
$groupOn = !empty($calMeta['group_booking']);
$coupleOn = !empty($calMeta['couple_booking']);
$sgpSel = (string) ($calMeta['staff_gender_preference'] ?? '');
?>
<section class="appt-create-section" aria-labelledby="appt-cal-meta-sec">
    <h2 class="appt-create-section__title" id="appt-cal-meta-sec">Calendar tags</h2>
    <p class="hint">Optional. Shown as icons on the day calendar when applicable.</p>
    <div class="appt-create-section__body appt-create-section__body--split">
        <div class="form-row">
            <label for="calendar_booking_source">Booking source</label>
            <select id="calendar_booking_source" name="calendar_booking_source">
                <option value="">— Not set —</option>
                <?php foreach (CalendarBadgeRegistry::BOOKING_SOURCE_VALUES as $bs): ?>
                <option value="<?= htmlspecialchars($bs) ?>" <?= $bsSel === $bs ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $bs))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label for="calendar_staff_gender_preference">Staff gender preference</label>
            <select id="calendar_staff_gender_preference" name="calendar_staff_gender_preference">
                <option value="">— Not set —</option>
                <option value="any" <?= $sgpSel === 'any' ? 'selected' : '' ?>>Any</option>
                <option value="male" <?= $sgpSel === 'male' ? 'selected' : '' ?>>Male</option>
                <option value="female" <?= $sgpSel === 'female' ? 'selected' : '' ?>>Female</option>
            </select>
        </div>
        <div class="form-row form-row--checkboxes">
            <input type="hidden" name="calendar_group_booking" value="0">
            <label class="appt-cal-meta-check">
                <input type="checkbox" name="calendar_group_booking" value="1" <?= $groupOn ? 'checked' : '' ?>>
                Group booking
            </label>
        </div>
        <div class="form-row form-row--checkboxes">
            <input type="hidden" name="calendar_couple_booking" value="0">
            <label class="appt-cal-meta-check">
                <input type="checkbox" name="calendar_couple_booking" value="1" <?= $coupleOn ? 'checked' : '' ?>>
                Couple / pair
            </label>
        </div>
    </div>
</section>
