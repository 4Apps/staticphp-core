<?php

namespace StaticPHP\Utils\Models\Audit;

/**
 * One row of the audit trail, before it reaches a table.
 *
 * Readonly, because an audit entry that can be edited after the fact is not an audit
 * entry. The facade fills in whatever the caller left empty through withResolved().
 */
readonly class AuditEvent
{
    /**
     * The three events the wrappers produce. `event` is free text - Audit::record() takes
     * anything - because ddz's smallint and LabNumbering's postgres enum both turn adding
     * an event into a migration.
     *
     * @var string
     * @access public
     */
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const DELETED = 'deleted';

    /**
     * @access public
     * @param string                    $event      created, updated, deleted or anything else
     * @param string                    $entityType Table name, not a class name
     * @param string                    $entityId   Text, so bigint and uuid keys both fit
     * @param string                    $module     Which part of the application changed this
     * @param ?array<string, mixed>     $oldValues  Null for an insert
     * @param ?array<string, mixed>     $newValues  Null for a delete
     * @param string                    $actorType  user, cron, api-key, ...
     * @param string                    $actorId
     * @param string                    $actorName  Denormalised, so a deleted user does not
     *                                              rewrite history
     * @param string                    $requestId  Groups one request's changes
     * @param string                    $url
     * @param string                    $ipAddress
     * @param string                    $userAgent
     * @param list<string>              $tags
     * @param ?array<string, mixed>     $context    Whatever else the application wants
     * @param ?string                   $createdAt  Null lets the store stamp it
     */
    public function __construct(
        public string $event,
        public string $entityType,
        public string $entityId = '',
        public string $module = '',
        public ?array $oldValues = null,
        public ?array $newValues = null,
        public string $actorType = '',
        public string $actorId = '',
        public string $actorName = '',
        public string $requestId = '',
        public string $url = '',
        public string $ipAddress = '',
        public string $userAgent = '',
        public array $tags = [],
        public ?array $context = null,
        public ?string $createdAt = null,
    ) {
    }

    /**
     * A copy with the framework-resolved fields filled in.
     *
     * Only empty fields are replaced: a caller that named the actor explicitly - an import
     * recording who requested it rather than who is logged in - keeps what it passed.
     *
     * @access public
     * @param  array{type?: string, id?: string, name?: string}         $actor
     * @param  array{url?: string, ip_address?: string, user_agent?: string} $context
     * @param  string                                                   $requestId
     * @return self
     */
    public function withResolved(array $actor, array $context, string $requestId): self
    {
        return new self(
            event: $this->event,
            entityType: $this->entityType,
            entityId: $this->entityId,
            module: $this->module,
            oldValues: $this->oldValues,
            newValues: $this->newValues,
            actorType: ($this->actorType !== '' ? $this->actorType : ($actor['type'] ?? '')),
            actorId: ($this->actorId !== '' ? $this->actorId : ($actor['id'] ?? '')),
            actorName: ($this->actorName !== '' ? $this->actorName : ($actor['name'] ?? '')),
            requestId: ($this->requestId !== '' ? $this->requestId : $requestId),
            url: ($this->url !== '' ? $this->url : ($context['url'] ?? '')),
            ipAddress: ($this->ipAddress !== '' ? $this->ipAddress : ($context['ip_address'] ?? '')),
            userAgent: ($this->userAgent !== '' ? $this->userAgent : ($context['user_agent'] ?? '')),
            tags: $this->tags,
            context: $this->context,
            createdAt: $this->createdAt,
        );
    }
}
