<?php

namespace Tests\Support\Settings;

/**
 * Gives a test its own copy of mailcow's shared files (Tests\Support\Settings\SharedFilesSandbox),
 * created before and removed after each test by Laravel's trait hooks.
 */
trait UsesSharedFiles
{
    protected SharedFilesSandbox $files;

    protected function setUpUsesSharedFiles(): void
    {
        $this->files = new SharedFilesSandbox;
    }

    protected function tearDownUsesSharedFiles(): void
    {
        $this->files->remove();
    }
}
