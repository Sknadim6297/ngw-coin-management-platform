<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLogModel;

class AuditLogger
{
    public function __construct(
        private readonly AuditLogModel $logs = new AuditLogModel()
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function log(
        string $action,
        ?int $actorUserId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        array $metadata = []
    ): void {
        $request = service('request');

        $this->logs->insert([
            'actor_user_id' => $actorUserId,
            'action'        => $action,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'ip_address'    => $request->getIPAddress(),
            'user_agent'    => substr((string) $request->getUserAgent(), 0, 255),
            'metadata'      => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
    }
}
