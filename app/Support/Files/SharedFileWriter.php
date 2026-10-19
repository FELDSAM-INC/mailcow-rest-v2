<?php

namespace App\Support\Files;

use App\Support\Clock\Clock;
use App\Support\Clock\Sleeper;
use App\Support\Http\ETag;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Rewrites files that other mailcow containers read, in place (feature 013 research R13, R14). The
 * Rspamd map directory belongs to Rspamd's user and the global Sieve files are single-file bind
 * mounts, so a new file can neither be created there nor renamed into place: the existing file is
 * opened without creating it, locked exclusively (serialising this API's writers; legacy does not
 * lock), the version tag is compared under the lock, and the content is replaced by one write,
 * flushed and synced. The inode, owner and mode stay as they are.
 *
 * With $touchAfter, the file is touched again once the clock is a second past the write, as legacy
 * does (`sleep(1.5); touch()`): Rspamd checks map files by their modification time in whole
 * seconds, so a later time makes it read the complete content at its next check even if it read the
 * file during the write.
 */
class SharedFileWriter
{
    /** Legacy waits 1.5 s before its touch. */
    private const MAX_TOUCH_WAIT = 1.5;

    public function __construct(
        private readonly Sleeper $sleeper,
        private readonly Clock $clock,
    ) {}

    /** The file's content, or null when it does not exist or cannot be read. */
    public function read(string $path): ?string
    {
        clearstatcache(true, $path);

        if (! is_file($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        return is_string($content) ? $content : null;
    }

    /**
     * Replaces the content of an existing file and returns the previous content.
     *
     * @param  callable(string): string  $etagOf  the version tag of a content
     *
     * @throws SharedFileMissing when the file does not exist
     * @throws PreconditionFailedHttpException when $ifMatch no longer matches
     */
    public function replaceInPlace(string $path, string $bytes, ?string $ifMatch, callable $etagOf, bool $touchAfter = false): string
    {
        clearstatcache(true, $path);

        if (! is_file($path)) {
            throw new SharedFileMissing("{$path} does not exist.");
        }

        // `r+` never creates the file (unlike `c+`), so a file removed meanwhile is refused too.
        $handle = @fopen($path, 'r+');

        if ($handle === false) {
            clearstatcache(true, $path);

            throw file_exists($path) ? new RuntimeException("{$path} cannot be opened for writing.") : new SharedFileMissing("{$path} does not exist.");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException("{$path} cannot be locked.");
            }

            $old = (string) stream_get_contents($handle, -1, 0);

            if (ETag::preconditionFails($ifMatch, $etagOf($old))) {
                throw new PreconditionFailedHttpException('The file has changed since it was read.');
            }

            if (! ftruncate($handle, 0) || fseek($handle, 0) !== 0) {
                throw new RuntimeException("{$path} cannot be truncated.");
            }

            $written = fwrite($handle, $bytes);

            if ($written !== strlen($bytes)) {
                throw new RuntimeException("{$path} was written incompletely.");
            }

            fflush($handle);
            fsync($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        if ($touchAfter) {
            $this->touchLater($path);
        }

        return $old;
    }

    private function touchLater(string $path): void
    {
        clearstatcache(true, $path);
        $written = (int) filemtime($path);
        $now = $this->clock->now()->getTimestampMs() / 1000;
        $wait = min(self::MAX_TOUCH_WAIT, max(0.0, $written + 1 - $now));

        if ($wait > 0) {
            $this->sleeper->sleepSeconds($wait);
        }

        $later = max($written + 1, (int) floor($this->clock->now()->getTimestampMs() / 1000));
        @touch($path, $later);
    }
}
