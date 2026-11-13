<?php

namespace Modules\Notification\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LucOverdueNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $leadUuid,
        private readonly ?string $leadId,
        private readonly string $borrowerName,
        private readonly int $daysOverdue,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'luc_overdue',
            'title' => 'LUC overdue',
            'message' => "Loan utilization check for {$this->borrowerName} ({$this->displayLeadId()}) is overdue by {$this->daysOverdue} day(s).",
            'lead_uuid' => $this->leadUuid,
            'lead_id' => $this->leadId,
            'days_overdue' => $this->daysOverdue,
        ];
    }

    private function displayLeadId(): string
    {
        return $this->leadId ?? $this->leadUuid;
    }
}
