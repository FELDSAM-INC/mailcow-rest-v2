<?php

namespace Tests\Unit\Support;

use App\Support\Files\SharedFileMissing;
use App\Support\Files\SharedFileWriter;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Tests\Support\Settings\UsesSharedFiles;
use Tests\TestCase;

/**
 * Feature 013 T011 (research R13, R14): the shared map and Sieve files are rewritten in place, under
 * an exclusive lock, with the exact bytes; a missing file is never created; the version tag is
 * compared under the lock; and, like legacy, a map is touched again once the clock is a second past
 * the write, through the injected Sleeper.
 */
class SharedFileWriterTest extends TestCase
{
    use UsesSharedFiles;

    private function writer(): SharedFileWriter
    {
        return $this->app->make(SharedFileWriter::class);
    }

    public function test_the_file_is_rewritten_in_place_with_the_exact_bytes(): void
    {
        $path = $this->files->mapPath('global_rcpt_blacklist.map');
        $inode = $this->files->mapInode('global_rcpt_blacklist.map');
        $mode = fileperms($path) & 0777;
        $before = (string) file_get_contents($path);

        $old = $this->writer()->replaceInPlace($path, "/a/i\n/b/\n", null, fn (string $content): string => sha1($content));

        $this->assertSame($before, $old);
        $this->assertSame("/a/i\n/b/\n", $this->files->mapContent('global_rcpt_blacklist.map'));
        $this->assertSame($inode, $this->files->mapInode('global_rcpt_blacklist.map'));
        $this->assertSame($mode, fileperms($path) & 0777);
    }

    public function test_a_shorter_content_leaves_no_old_bytes(): void
    {
        $path = $this->files->mapPath('bad_words.map');
        file_put_contents($path, str_repeat('x', 1000));

        $this->writer()->replaceInPlace($path, "short\n", null, fn (string $c): string => sha1($c));

        $this->assertSame("short\n", $this->files->mapContent('bad_words.map'));
    }

    public function test_a_missing_file_is_refused_and_never_created(): void
    {
        $this->files->removeMap('bad_words.map');

        try {
            $this->writer()->replaceInPlace($this->files->mapPath('bad_words.map'), "x\n", null, fn (string $c): string => sha1($c));
            $this->fail('A missing file must be refused.');
        } catch (SharedFileMissing) {
            $this->assertFileDoesNotExist($this->files->mapPath('bad_words.map'));
        }
    }

    public function test_an_outdated_tag_is_refused_under_the_lock_and_changes_nothing(): void
    {
        $path = $this->files->mapPath('bad_words.map');
        $before = $this->files->mapContent('bad_words.map');

        try {
            $this->writer()->replaceInPlace($path, "x\n", '"stale"', fn (string $c): string => '"'.sha1($c).'"');
            $this->fail('An outdated tag must be refused.');
        } catch (PreconditionFailedHttpException) {
            $this->assertSame($before, $this->files->mapContent('bad_words.map'));
        }
    }

    public function test_the_current_tag_and_a_wildcard_pass(): void
    {
        $path = $this->files->mapPath('bad_words.map');
        $tag = '"'.sha1($this->files->mapContent('bad_words.map')).'"';

        $this->writer()->replaceInPlace($path, "one\n", $tag, fn (string $c): string => '"'.sha1($c).'"');
        $this->writer()->replaceInPlace($path, "two\n", '*', fn (string $c): string => '"'.sha1($c).'"');

        $this->assertSame("two\n", $this->files->mapContent('bad_words.map'));
    }

    public function test_the_lock_is_held_while_the_tag_is_compared_and_the_file_written(): void
    {
        $path = $this->files->mapPath('bad_words.map');
        $locked = null;

        $this->writer()->replaceInPlace($path, "x\n", null, function (string $content) use ($path, &$locked): string {
            $other = fopen($path, 'r');
            $this->assertIsResource($other);
            $locked = ! flock($other, LOCK_EX | LOCK_NB);
            fclose($other);

            return sha1($content);
        });

        $this->assertTrue($locked, 'A second writer must not get the lock during the write.');
        $other = fopen($path, 'r');
        $this->assertIsResource($other);
        $this->assertTrue(flock($other, LOCK_EX | LOCK_NB), 'The lock must be released afterwards.');
        fclose($other);
    }

    public function test_touch_after_waits_until_a_second_past_the_write_and_sets_a_later_mtime(): void
    {
        $path = $this->files->mapPath('bad_words.map');
        touch($path, time() - 100);

        $this->writer()->replaceInPlace($path, "x\n", null, fn (string $c): string => sha1($c), touchAfter: true);
        clearstatcache(true, $path);
        $writtenAt = time();

        $this->assertNotSame([], $this->sleeper()->slept, 'The writer waits through the Sleeper.');
        $this->assertLessThanOrEqual(1.5, max($this->sleeper()->slept));
        $this->assertGreaterThanOrEqual($writtenAt, (int) filemtime($path));
        $this->assertGreaterThan(0, array_sum($this->sleeper()->slept));
    }

    public function test_without_touch_after_nothing_waits(): void
    {
        $this->writer()->replaceInPlace($this->files->sievePath('before'), "# x\n", null, fn (string $c): string => sha1($c));

        $this->assertSame([], $this->sleeper()->slept);
        $this->assertSame("# x\n", $this->files->sieve('before'));
    }

    public function test_read_returns_the_content_or_null_for_a_missing_file(): void
    {
        $this->assertSame($this->files->sieve('after'), $this->writer()->read($this->files->sievePath('after')));
        $this->files->removeSieve('after');
        $this->assertNull($this->writer()->read($this->files->sievePath('after')));
    }
}
