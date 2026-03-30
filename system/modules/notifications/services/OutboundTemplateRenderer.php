<?php

declare(strict_types=1);

namespace Modules\Notifications\Services;

/**
 * Single place for minimal transactional plaintext templates (no HTML layout).
 *
 * Template keys align with {@see OutboundTransactionalNotificationService} event keys.
 */
final class OutboundTemplateRenderer
{
    /**
     * @param array<string, scalar|null> $ctx
     * @return array{subject: string, body: string}
     */
    public function render(string $templateKey, array $ctx): array
    {
        $repl = [];
        foreach ($ctx as $k => $v) {
            $repl['{{' . $k . '}}'] = $v === null ? '' : (string) $v;
        }

        $pack = match ($templateKey) {
            'appointment.confirmation' => [
                'subject' => 'Appointment confirmation #{{appointment_id}}',
                'body' => "Hello {{client_first_name}} {{client_last_name}},\n\n"
                    . "Your appointment is confirmed.\n"
                    . "When: {{appointment_start_at}} – {{appointment_end_at}}\n"
                    . "Service: {{service_name}}\n"
                    . "Staff: {{staff_name}}\n"
                    . "Reference: #{{appointment_id}}\n",
            ],
            'appointment.cancelled' => [
                'subject' => 'Appointment cancelled #{{appointment_id}}',
                'body' => "Hello {{client_first_name}} {{client_last_name}},\n\n"
                    . "Your appointment scheduled for {{appointment_start_at}} has been cancelled.\n"
                    . "Reference: #{{appointment_id}}\n",
            ],
            'appointment.rescheduled' => [
                'subject' => 'Appointment rescheduled #{{appointment_id}}',
                'body' => "Hello {{client_first_name}} {{client_last_name}},\n\n"
                    . "Your appointment has been moved.\n"
                    . "New time: {{appointment_start_at}} – {{appointment_end_at}}\n"
                    . "Service: {{service_name}}\n"
                    . "Staff: {{staff_name}}\n"
                    . "Reference: #{{appointment_id}}\n",
            ],
            'waitlist.offer' => [
                'subject' => 'Waitlist slot offered #{{waitlist_id}}',
                'body' => "Hello {{client_first_name}} {{client_last_name}},\n\n"
                    . "A slot may be available for you on {{preferred_date}}.\n"
                    . "{{offer_expiry_note}}\n"
                    . "Waitlist reference: #{{waitlist_id}}\n",
            ],
            'membership.renewal_reminder' => [
                'subject' => 'Membership renewal reminder — {{plan_name}}',
                'body' => "Hello {{client_first_name}} {{client_last_name}},\n\n"
                    . "Your membership \"{{plan_name}}\" is set to end on {{ends_at}}.\n"
                    . "Membership reference: #{{client_membership_id}}\n",
            ],
            default => throw new \InvalidArgumentException('Unknown outbound template key: ' . $templateKey),
        };

        return [
            'subject' => $this->apply($pack['subject'], $repl),
            'body' => $this->apply($pack['body'], $repl),
        ];
    }

    /**
     * @param array<string, string> $repl
     */
    private function apply(string $tpl, array $repl): string
    {
        return strtr($tpl, $repl);
    }
}
