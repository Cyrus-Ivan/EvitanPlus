<?php

use App\Providers\NativeAppServiceProvider;

return [
    /**
     * The version of your app.
     * It is used to determine if the app needs to be updated.
     * Increment this value every time you release a new version of your app.
     */
    'version' => env('NATIVEPHP_APP_VERSION', '1.0.0'),

    /**
     * The ID of your application. This should be a unique identifier
     * usually in the form of a reverse domain name.
     * For example: com.nativephp.app
     */
    'app_id' => env('NATIVEPHP_APP_ID', 'com.evitan.plus'),

    /**
     * If your application allows deep linking, you can specify the scheme
     * to use here. This is the scheme that will be used to open your
     * application from within other applications.
     * For example: "nativephp"
     *
     * This would allow you to open your application using a URL like:
     * nativephp://some/path
     */
    'deeplink_scheme' => env('NATIVEPHP_DEEPLINK_SCHEME'),

    /**
     * The author of your application.
     */
    'author' => env('NATIVEPHP_APP_AUTHOR', 'Evitan Plus'),

    /**
     * The copyright notice for your application.
     */
    'copyright' => env('NATIVEPHP_APP_COPYRIGHT', '© 2026 Evitan Plus'),

    /**
     * The description of your application.
     */
    'description' => env('NATIVEPHP_APP_DESCRIPTION', 'Offline-first retail point of sale'),

    /**
     * The Website of your application.
     */
    'website' => env('NATIVEPHP_APP_WEBSITE', ''),

    /**
     * The default service provider for your application. This provider
     * takes care of bootstrapping your application and configuring
     * any global hotkeys, menus, windows, etc.
     */
    'provider' => NativeAppServiceProvider::class,

    /**
     * A list of environment keys that should be removed from the
     * .env file when the application is bundled for production.
     * You may use wildcards to match multiple keys.
     */
    'cleanup_env_keys' => [
        // Never ship the dev key/env: without APP_KEY the packaged app
        // generates a per-install key (EncryptionKeyBootstrapper), and absent
        // APP_ENV/APP_DEBUG Laravel defaults to production/false (the runtime
        // also injects both via the process environment).
        'APP_KEY',
        'APP_ENV',
        'APP_DEBUG',
        'AWS_*',
        'AZURE_*',
        'GITHUB_*',
        'DO_SPACES_*',
        '*_SECRET',
        'ZEPHPYR_*',
        'NATIVEPHP_UPDATER_PATH',
        'NATIVEPHP_APPLE_ID',
        'NATIVEPHP_APPLE_ID_PASS',
        'NATIVEPHP_APPLE_TEAM_ID',
        'NATIVEPHP_AZURE_PUBLISHER_NAME',
        'NATIVEPHP_AZURE_ENDPOINT',
        'NATIVEPHP_AZURE_CERTIFICATE_PROFILE_NAME',
        'NATIVEPHP_AZURE_CODE_SIGNING_ACCOUNT_NAME',

        // Defense in depth for the Evitan Plus split (MULTIBRANCH-BRAINSTORM.md
        // §18). The Provisioning Console keeps its admin credentials in
        // console/.env, which is excluded structurally below — but CleansEnvFile
        // only ever touches the ROOT .env, so these patterns are what protects a
        // developer who pastes admin creds into the POS .env by mistake.
        //
        // NOTE: deliberately NOT 'DB_*'. The shipped app's own SQLite settings
        // (DB_CONNECTION, DB_DATABASE, DB_FOREIGN_KEYS) live under that prefix
        // and must survive into the build. That is precisely why the console
        // uses CONSOLE_DB_* and the branch connection uses BRANCH_DB_*.
        'CONSOLE_*',
        'BRANCH_DB_*',
        'SUPABASE_*',
    ],

    /**
     * A list of files and folders that should be removed from the
     * final app before it is bundled for production.
     * You may use glob / wildcard patterns here.
     */
    'cleanup_exclude_files' => [
        'build',
        'temp',
        'content',
        'node_modules',
        '*/tests',
        // Project internals that must not ship inside the installer (the
        // built-in excludes only cover database/*.sqlite — tests/e2e/.runtime
        // holds a seeded database copy). The backup feature was removed in
        // M11.6, but dev machines still carry real business data in
        // storage/backups from before then; keep excluding it.
        'storage/backups/*',
        '.phpunit.result.cache',
        'tests',
        'docs',
        'test-results',
        '.claude',
        'CLAUDE.md',
        'BRAINSTORM.md',
        'ROADMAP.md',
        'playwright.config.ts',
        'phpstan.neon',
        'phpstan-baseline.neon',

        // The Provisioning Console is a separate Laravel app that must never
        // ship (MULTIBRANCH-BRAINSTORM.md §18). A BARE directory name is the
        // only form both exclusion paths understand:
        //   - NativePHP's copier fnmatch()es each entry's relative path inside
        //     a RecursiveCallbackFilterIterator, so rejecting the directory
        //     prunes the entire subtree — console/.env is never even visited
        //     (vendor/nativephp/electron/src/Traits/CopiesToBuildDirectory.php);
        //   - Symfony Finder's exclude() in BundleCommand treats slash-free
        //     entries as directory names but preg_quote()s anything containing
        //     a slash, so 'console/*' would compile to a regex that can never
        //     match.
        // Do not "improve" this to 'console/*'.
        // Gated by tests/Feature/Build/BuildExclusionTest.php.
        'console',
        'MULTIBRANCH-BRAINSTORM.md',
        'MULTIBRANCH-ROADMAP.md',
    ],

    /**
     * The NativePHP updater configuration.
     */
    'updater' => [
        /**
         * Whether or not the updater is enabled. Please note that the
         * updater will only work when your application is bundled
         * for production.
         *
         * Disabled for Evitan Plus: the app is offline-first with no update
         * feed configured — leaving this on makes every launch fire a failing
         * update check against the empty provider below. Releases are
         * installed manually.
         */
        'enabled' => env('NATIVEPHP_UPDATER_ENABLED', false),

        /**
         * The updater provider to use.
         * Supported: "github", "s3", "spaces"
         */
        'default' => env('NATIVEPHP_UPDATER_PROVIDER', 'spaces'),

        'providers' => [
            'github' => [
                'driver' => 'github',
                'repo' => env('GITHUB_REPO'),
                'owner' => env('GITHUB_OWNER'),
                'token' => env('GITHUB_TOKEN'),
                'vPrefixedTagName' => env('GITHUB_V_PREFIXED_TAG_NAME', true),
                'private' => env('GITHUB_PRIVATE', false),
                'channel' => env('GITHUB_CHANNEL', 'latest'),
                'releaseType' => env('GITHUB_RELEASE_TYPE', 'draft'),
            ],

            's3' => [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION'),
                'bucket' => env('AWS_BUCKET'),
                'endpoint' => env('AWS_ENDPOINT'),
                'path' => env('NATIVEPHP_UPDATER_PATH', null),
            ],

            'spaces' => [
                'driver' => 'spaces',
                'key' => env('DO_SPACES_KEY_ID'),
                'secret' => env('DO_SPACES_SECRET_ACCESS_KEY'),
                'name' => env('DO_SPACES_NAME'),
                'region' => env('DO_SPACES_REGION'),
                'path' => env('NATIVEPHP_UPDATER_PATH', null),
            ],
        ],
    ],

    /**
     * The queue workers that get auto-started on your application start.
     * Evitan Plus queues nothing (no jobs, no ShouldQueue anywhere), so no
     * always-on worker process is spawned.
     */
    'queue_workers' => [],

    /**
     * Define your own scripts to run before and after the build process.
     * Note: a failing prebuild does NOT abort native:build — the release
     * procedure still runs `npm run build` as an explicit gate first.
     */
    'prebuild' => [
        'npm run build',
    ],

    'postbuild' => [
        // 'rm -rf public/build',
    ],

    /**
     * Custom PHP binary path.
     */
    'binary_path' => env('NATIVEPHP_PHP_BINARY_PATH', null),
];
