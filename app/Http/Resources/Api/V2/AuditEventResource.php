<?php

namespace App\Http\Resources\Api\V2;

use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contract `AuditEvent` (FR-046). Records never change, so they carry no version tag.
 *
 * @property AuditEvent $resource
 */
class AuditEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $event = $this->resource;

        return [
            'id' => $event->id,
            'occurredAt' => $event->occurred_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'eventType' => $event->event_type,
            'outcome' => $event->outcome,
            'actor' => [
                'username' => $event->actor_username,
                'role' => $event->actor_role,
                'apiKeyId' => $event->actor_api_key_id,
            ],
            'target' => $event->target_type === null ? null : [
                'type' => $event->target_type,
                'id' => $event->target_id,
                'name' => $event->target_name,
            ],
            'clientAddress' => $event->client_address,
            'userAgent' => $event->user_agent,
            'authMethod' => $event->auth_method,
            'failureReason' => $event->failure_reason,
            'requestId' => $event->request_id,
            // A JSON object, also when empty.
            'details' => (object) $event->details,
        ];
    }
}
