<?php

return [

    /*
    | Databases per target. Every target uses the same server and runtime role;
    | only the database name differs. Tests never touch the dev database.
    */
    'databases' => [
        'dev' => env('ARKON_DB_DEV', 'arkonlaravel'),
        'test' => env('ARKON_DB_TEST', 'arkonlaravel_test'),
        'e2e' => env('ARKON_DB_E2E', 'arkonlaravel_e2e'),
    ],

    /*
    | File with the schema-owner credentials (relative to the project root). Read
    | only by migration tooling and the test harness, never loaded into the
    | environment of the web app.
    */
    'migration_env_file' => '.migrate.env',

    /*
    | Uploaded media. Relative paths are resolved from the project root.
    */
    'media_root' => env('ARKON_MEDIA_ROOT', 'storage/app/media'),

    'seed_hosts' => env('ARKON_SEED_HOSTS', 'arkonlaravel.test'),

    /*
    | How long the runtime role check is cached between requests (seconds).
    */
    'runtime_check_ttl' => 300,

];
