<?php

namespace App\Exceptions;

/**
 * The version gate refused (MULTIBRANCH-BRAINSTORM.md §13).
 *
 * Its own class, not a bare SetupFailedException, because from M6 on this is the
 * one sync failure that is neither an error nor something waiting fixes: the
 * schema has moved ahead of this build, sync is refused in BOTH directions, and
 * the till goes on selling offline until someone installs a newer build. The
 * cycle records it as `version_blocked` rather than as a network fault
 * (§11.6 `sync_status.connection_state`).
 */
class VersionBlockedException extends SetupFailedException
{
    public function __construct(
        public readonly string $appVersion,
        public readonly string $minAppVersion,
    ) {
        parent::__construct(
            "This build is version {$appVersion}, but the business database requires "
            ."version {$minAppVersion} or newer. Install a newer build on this device and run setup again."
        );
    }
}
