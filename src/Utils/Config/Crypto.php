<?php

/*
|--------------------------------------------------------------------------
| Field encryption
|
| Utils\Models\Crypto\Crypto encrypts values you hand it, when you hand them over.
| Nothing is encrypted automatically.
|
| Generate key material with: staticphp crypto key
|--------------------------------------------------------------------------
*/

$config['crypto'] = [

    // Key id used for anything encrypted from now on. Stored values carry the id they were
    // written with, so this can change without touching a single row.
    //
    // Up to 16 characters of a-z, 0-9 and underscore.
    'key' => 'k1',

    // Key id to the name of the environment variable holding that key.
    //
    // The variable name is here, never the key itself: this file is committed and keys are
    // not. Anything that can read the repository would otherwise be able to read the
    // database.
    //
    // Retired ids stay listed. Removing one does not retire a key, it makes everything that
    // key encrypted permanently unreadable - drop it only once `staticphp crypto rotate`
    // reports nothing left under it.
    'keys' => [
        'k1' => 'STATICPHP_CRYPTO_K1',
        // 'k0' => 'STATICPHP_CRYPTO_K0',
    ],

    // Environment variable holding the key for blindIndex().
    //
    // Separate from the encryption keys because the index is deterministic, and so is the
    // weaker of the two. It also cannot be rotated the way an encryption key can: changing
    // it changes every index, which means recomputing the index column for every row.
    'index_key' => 'STATICPHP_CRYPTO_INDEX',

    // How key material is read, given the variable name above. Null uses getenv().
    //
    // Point this at a secrets manager where there is one:
    //
    //     'resolver' => fn(string $name): ?string => Vault::read($name),
    'resolver' => null,
];
