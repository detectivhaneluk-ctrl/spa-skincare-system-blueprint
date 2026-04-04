<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Appointments;

use Modules\Appointments\Services\CalendarAppointmentBadgeResolver;
use PHPUnit\Framework\TestCase;

final class CalendarAppointmentBadgeResolverTest extends TestCase
{
    public function testEmptyRowReturnsNoBadges(): void
    {
        $r = new CalendarAppointmentBadgeResolver();
        $out = $r->resolve(['status' => 'scheduled']);
        $this->assertSame([], $out['calendar_badges']);
        $this->assertNull($out['calendar_dominant_badge']);
    }

    public function testCancelledDominatesAndAppearsInStrip(): void
    {
        $r = new CalendarAppointmentBadgeResolver();
        $out = $r->resolve([
            'status' => 'cancelled',
            'has_notes_flag' => 1,
        ]);
        $this->assertNotNull($out['calendar_dominant_badge']);
        $this->assertSame('appt_cancelled', $out['calendar_dominant_badge']['code']);
        $codes = array_column($out['calendar_badges'], 'code');
        $this->assertContains('appt_cancelled', $codes);
        $this->assertContains('has_notes', $codes);
    }

    public function testNewClientRequiresZeroPriorAndPositiveClientId(): void
    {
        $r = new CalendarAppointmentBadgeResolver();
        $none = $r->resolve([
            'status' => 'scheduled',
            'client_id' => 5,
            'client_prior_appts_before' => 1,
        ]);
        $codes = array_column($none['calendar_badges'], 'code');
        $this->assertNotContains('new_client', $codes);

        $yes = $r->resolve([
            'status' => 'scheduled',
            'client_id' => 5,
            'client_prior_appts_before' => 0,
        ]);
        $codes2 = array_column($yes['calendar_badges'], 'code');
        $this->assertContains('new_client', $codes2);
    }

    public function testInvoiceSuppressedWhenAppointmentCancelled(): void
    {
        $r = new CalendarAppointmentBadgeResolver();
        $out = $r->resolve([
            'status' => 'cancelled',
            'linked_invoice' => [
                'id' => 9,
                'status' => 'open',
                'total_amount' => 50.0,
                'paid_amount' => 0.0,
            ],
        ]);
        $codes = array_column($out['calendar_badges'], 'code');
        $this->assertNotContains('invoice_open_balance', $codes);
    }

    public function testInvoiceOpenBalanceVersusPaid(): void
    {
        $r = new CalendarAppointmentBadgeResolver();
        $open = $r->resolve([
            'status' => 'scheduled',
            'linked_invoice' => [
                'id' => 10,
                'status' => 'open',
                'total_amount' => 100.0,
                'paid_amount' => 0.0,
            ],
        ]);
        $this->assertSame('invoice_open_balance', $open['calendar_dominant_badge']['code']);

        $paid = $r->resolve([
            'status' => 'scheduled',
            'linked_invoice' => [
                'id' => 11,
                'status' => 'paid',
                'total_amount' => 100.0,
                'paid_amount' => 100.0,
            ],
        ]);
        $this->assertSame('invoice_paid', $paid['calendar_dominant_badge']['code']);
    }

    public function testStoredMetaBookingSource(): void
    {
        $r = new CalendarAppointmentBadgeResolver();
        $out = $r->resolve([
            'status' => 'scheduled',
            'appointment_calendar_meta' => ['booking_source' => 'facebook'],
        ]);
        $codes = array_column($out['calendar_badges'], 'code');
        $this->assertContains('booking_src_facebook', $codes);
    }

    public function testMergeCalendarMetaPatchClearsBookingSource(): void
    {
        $merged = \Modules\Appointments\Services\CalendarBadgeRegistry::mergeCalendarMetaPatch(
            ['booking_source' => 'phone', 'group_booking' => true],
            ['booking_source' => '']
        );
        $this->assertArrayNotHasKey('booking_source', $merged);
        $this->assertTrue($merged['group_booking']);
    }
}
