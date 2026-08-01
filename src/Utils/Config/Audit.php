<?php

/*
|--------------------------------------------------------------------------
| Audit trail
|
| Override any of this from the application by creating Application/Config/Audit.php
| and adding 'Audit' to $config['autoload_configs'].
|--------------------------------------------------------------------------
*/

$config['audit'] = [

    // Which entry of $config['db']['pdo'] the trail is written to. The same connection the
    // audited change runs on, so the audit row lives or dies with it.
    'connection' => 'default',

    // Where rows land. A string is one table for everything, which is the default because
    // "what did this user do today" and the request grouping both need a single table.
    //
    // A callable receiving the AuditEvent splits the trail instead, for a deployment that
    // wants per-module tables or postgres partitioning by hand:
    //
    //     'table' => fn ($event) => 'logs.history_' . $event->module,
    //
    // The columns are identical either way, so splitting now and consolidating later is a
    // data move rather than a rewrite.
    'table' => 'audit_log',

    // Whether a failed audit write throws. Leave this on: on postgres the failed insert has
    // already aborted the surrounding transaction, so catching it does not save the change,
    // it only hides why the commit fails afterwards. Off, the failure goes to the error log
    // and the call continues - availability over completeness, which is rarely the trade an
    // audit trail wants to make.
    'strict' => true,

    // Refuse to audit an update or delete matching more than this many rows. A mistyped
    // condition should fail loudly rather than write half a million rows inside someone
    // else's transaction.
    'max_rows' => 1000,

    // Column read from the affected row to fill entity_id.
    'id_key' => 'id',

    // callable(): array{type: string, id: string, name: string}
    // Core has no notion of authentication, so the application names the actor:
    //
    //     'actor' => fn () => [
    //         'type' => 'user',
    //         'id' => (string) ($_SESSION['user']['id'] ?? ''),
    //         'name' => (string) ($_SESSION['user']['name'] ?? ''),
    //     ],
    //
    // The name is stored on the row rather than joined later, so deleting or renaming a
    // user does not rewrite what the log says they did.
    'actor' => null,

    // callable(): array{url: string, ip_address: string, user_agent: string}
    // Null uses the request itself, through the same proxy header handling as the rest of
    // the framework.
    'context' => null,

    // Columns whose values must never reach the trail, per table. The key is still recorded
    // so the log shows that a password changed, with the value replaced by Diff::REDACTED.
    //
    //     'exclude' => ['users' => ['password', 'remember_token']],
    //
    // @var array<string, list<string>>
    'exclude' => [],
];
