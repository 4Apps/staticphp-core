<?php

namespace StaticPHP\Utils\Models\Migrations;

/**
 * One migration, paired with whatever the tracking table knows about it.
 */
class MigrationState
{
    /**
     * @access public
     * @param string         $name  Migration filename
     * @param State          $state
     * @param ?MigrationFile $file  Null when the file is gone (MISSING)
     * @param ?AppliedRow    $row   Null when it has never run (PENDING)
     */
    public function __construct(
        public readonly string $name,
        public readonly State $state,
        public readonly ?MigrationFile $file,
        public readonly ?AppliedRow $row
    ) {
    }

    /**
     * The file, for the states that are defined to have one.
     *
     * Only a MISSING migration has no file. Anything that can be queued, validated or run
     * came off disk, so the callers that reach for the file are entitled to it - this says
     * which state broke that expectation instead of failing on a null property.
     *
     * @access public
     * @return MigrationFile
     * @throws MigrationError When the file is gone
     */
    public function requireFile(): MigrationFile
    {
        return $this->file ?? throw new MigrationError(
            "Migration \"{$this->name}\" is {$this->state->value} and has no file on disk"
        );
    }
}
