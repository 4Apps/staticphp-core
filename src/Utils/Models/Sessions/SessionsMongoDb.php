<?php

/*
|--------------------------------------------------------------------------
| MongoDB session handler
|--------------------------------------------------------------------------
*/

namespace StaticPHP\Utils\Models\Sessions;

use MongoDB\Client;

class SessionsMongoDb extends Sessions
{
    protected ?string $mdbConnectionString = null;

    /*
     * mongodb/mongodb is a suggestion rather than a requirement, so its classes are not
     * there to name in a type. See the ignoreErrors entry for this file in phpstan.neon.
     */
    protected mixed $mdbConnection = null;
    protected mixed $mdbDatabase = null;
    protected mixed $mdbCollection = null;

    public function __construct(
        string $connectionString,
        string $databaseName = 'sessions',
        string $sessionName = 'SMDB',
        ?Sessions $backupHandler = null
    ) {
        $this->mdbConnectionString = $connectionString;
        $this->mdbConnection = new Client($this->mdbConnectionString);
        $this->mdbDatabase = $this->mdbConnection->{$databaseName};
        $this->mdbCollection = $this->mdbDatabase->php_sessions;

        parent::__construct($sessionName, $backupHandler);
    }

    public function read(string $id): string|false
    {
        $data = $this->mdbCollection
            ->findOne(['id' => $this->id($id), 'salt' => $this->salt]);
        if (!empty($data['data'])) {
            return $data['data'];
        }

        return parent::read($id);
    }

    public function write(string $id, string $data): bool
    {
        $itemData['id'] = $this->id($id);
        $itemData['data'] = $data;
        $itemData['salt'] = $this->salt;
        $itemData['timestamp'] = time();
        $this->mdbCollection->updateOne(
            ['id' => $this->id($id), 'salt' => $this->salt],
            ['$set' => $itemData],
            ['upsert' => true]
        );

        return parent::write($id, $data);
    }

    public function destroy(string $id): bool
    {
        $this->mdbCollection->deleteOne(['id' => $this->id($id)]);

        return parent::destroy($id);
    }

    public function gc(int $maxLifetime): int|false
    {
        $this->mdbCollection->deleteMany(['timestamp' => ['$lt' => (time() - $maxLifetime)]]);

        return parent::gc($maxLifetime);
    }
}
