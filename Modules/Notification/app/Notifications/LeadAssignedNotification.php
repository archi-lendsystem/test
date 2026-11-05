<?php

namespace Modules\Notification\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeadAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $leadUuid,
        private readonly ?string $leadId,
        private readonly string $assignedByName,
        private readonly ?string $assignedRole,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'lead_assigned',
            'title' => 'New lead assigned to you',
            'message' => "{$this->assignedByName} assigned lead {$this->displayLeadId()} to you"
                . ($this->assignedRole ? " as {$this->assignedRole}" : '') . '.',
            'lead_uuid' => $this->leadUuid,
            'lead_id' => $this->leadId,
        ];
    }

    private function displayLeadId(): string
    {
        return $this->leadId ?? $this->leadUuid;
    }
}
