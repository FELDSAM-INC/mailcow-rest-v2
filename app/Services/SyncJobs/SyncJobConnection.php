<?php

namespace App\Services\SyncJobs;

use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ValidationProblemException;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Models\Mailcow\SchemaVersion;
use App\Models\Mailcow\SyncJob;
use App\Services\SyncSources\SourceReader;
use App\Services\SyncSources\SourceScope;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Validates attaching an existing source without redirecting a stored password or borrowing another mailbox's token. */
final class SyncJobConnection
{
    public function __construct(private readonly SourceScope $scope, private readonly SourceReader $reader) {}

    /** @param array<string, mixed> $members
     * @return array<string, mixed>
     */
    public function resolve(Principal $actor, array $members, string $mailbox, ?SyncJob $job = null): array
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            foreach (['sourceId', 'priority'] as $field) {
                if (array_key_exists($field, $members)) {
                    $this->invalid($field, 'notEligible', 'This setting requires mailcow 2026-11.');
                }
            }

            return $members;
        }
        if (array_key_exists('priority', $members) && $actor->role !== Role::Admin) {
            throw new AccessDeniedHttpException('Only administrators can change global queue priority.');
        }
        if (! array_key_exists('sourceId', $members)) {
            return $members;
        }
        $this->scope->authorize($actor);
        $source = $this->reader->row((int) $members['sourceId'], $actor);
        if (! $source->active) {
            $this->invalid('sourceId', 'notEligible', 'The source is inactive.');
        }
        $connection = ['remoteHost' => $source->host1, 'remotePort' => (int) $source->port1,
            'encryption' => SyncJobEncryption::from($source->enc1 ?? 'TLS')->toApi()];
        foreach ($connection as $field => $value) {
            if (array_key_exists($field, $members) && $members[$field] !== $value) {
                $this->invalid($field, 'conflictsWith', 'Connection fields are managed by the selected source.');
            }
        }
        $members = array_merge($members, $connection);
        $remote = (string) ($members['remoteUser'] ?? $job->user1 ?? '');
        if ($source->auth_type === 'XOAUTH2') {
            if ($source->oauth_flow === 'authorization_code' && ! DB::table('imapsync_source_oauth_token')
                ->where('source_id', $source->id)->where('owner', $mailbox)->where('username', $remote)->whereNotNull('access_token')->where('access_token', '<>', '')->exists()) {
                $this->invalid('remoteUser', 'notEligible', 'Connect this remote account for the target mailbox first.');
            }
            // A shared token is fetched by upstream cron; jobs may be prepared before it arrives.
            $members['remotePassword'] = '';
        } elseif (($job === null || (int) $job->getAttribute('source_id') !== (int) $source->id) && empty($members['remotePassword'])) {
            $this->invalid('remotePassword', 'secretRequired', 'Supply the password for the selected source.');
        }

        return $members;
    }

    public function assertRunnable(SyncJob $job): void
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            return;
        }
        if (! $job->getAttribute('source_active')) {
            $this->invalid('sourceId', 'notEligible', 'The source is inactive.');
        }
        if ($job->getAttribute('authmech1') !== 'XOAUTH2') {
            return;
        }
        $source = DB::table('imapsync_source')->where('id', $job->getAttribute('source_id'))->first();
        if ($source?->oauth_flow === 'authorization_code' && ! DB::table('imapsync_source_oauth_token')
            ->where('source_id', $source->id)->where('owner', $job->user2)->where('username', $job->user1)
            ->whereNotNull('access_token')->where('access_token', '<>', '')->exists()) {
            $this->invalid('remoteUser', 'notEligible', 'Connect this remote account for the target mailbox first.');
        }
    }

    private function invalid(string $field, string $code, string $message): never
    {
        throw new ValidationProblemException([$field => [ApiFormRequest::make($code, [], $message)]]);
    }
}
