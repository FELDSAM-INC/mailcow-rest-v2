<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Role;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\WriteMailboxGroupRequest;
use App\Models\Mailcow\SchemaVersion;
use App\Services\MailboxGroups\GroupReader;
use App\Services\MailboxGroups\GroupScope;
use App\Services\MailboxGroups\GroupService;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MailboxGroupController extends Controller
{
    public function __construct(private readonly GroupScope $scope, private readonly GroupReader $reader, private readonly GroupService $service) {}

    public function index(Request $request): JsonResponse
    {
        $actor = RequestContext::requirePrincipal($request);
        $this->scope->authorize($actor);
        $list = ListQuery::fromRequest($request, ['id' => 'id', 'name' => 'name', 'domain' => 'domain', 'address' => 'address', 'active' => 'active', 'priority' => 'priority'], 'name');
        $query = $list->applySearch($this->scope->query($actor), ['name', 'domain', 'address', 'description']);

        $query->select('mailbox_group.*')->selectSub(
            DB::table('mailbox_group_member')->whereColumn('group_id', 'mailbox_group.id')->selectRaw('COUNT(*)'), 'member_count');

        return response()->json($list->paginate($query, fn ($row): array => [
            'id' => (int) $row->id, 'name' => $row->name, 'domain' => $row->domain, 'address' => $row->address,
            'description' => $row->description, 'active' => (bool) $row->active, 'memberCount' => (int) $row->member_count,
            'templateId' => $row->template_id === null ? null : (int) $row->template_id,
            'priority' => (int) $row->priority, 'identityManaged' => $row->idp_ref !== null,
        ], 'id'));
    }

    public function show(Request $request, int $groupId): JsonResponse
    {
        $actor = RequestContext::requirePrincipal($request);
        $this->scope->authorize($actor);
        $data = $this->reader->read($groupId, $actor);

        return response()->json(['data' => $data], 200, ['ETag' => $data['etag']]);
    }

    public function store(WriteMailboxGroupRequest $request): JsonResponse
    {
        $data = $this->service->save(null, $request->values(), RequestContext::requirePrincipal($request), null);

        return response()->json(['data' => $data], 201, ['ETag' => $data['etag'], 'Location' => '/api/v2/mailbox-groups/'.$data['id']]);
    }

    public function update(WriteMailboxGroupRequest $request, int $groupId): JsonResponse
    {
        $data = $this->service->save($groupId, $request->values(), RequestContext::requirePrincipal($request), $request->header('If-Match'));

        return response()->json(['data' => $data], 200, ['ETag' => $data['etag']]);
    }

    public function destroy(Request $request, int $groupId): JsonResponse
    {
        return response()->json(['data' => ['warnings' => $this->service->delete($groupId, RequestContext::requirePrincipal($request), $request->header('If-Match'))]]);
    }

    /** Mailbox users see their memberships, never other members or the group's grants. */
    public function memberships(Request $request, string $mailbox): JsonResponse
    {
        $actor = RequestContext::requirePrincipal($request);
        if (($actor->role === Role::MailboxUser && $actor->username !== $mailbox)
            || ($actor->role !== Role::MailboxUser && ! $this->scope->mailbox($actor, $mailbox))) {
            throw new NotFoundHttpException;
        }
        $rows = ! SchemaVersion::hasNovember2026Features() ? [] : DB::table('mailbox_group_member as m')
            ->join('mailbox_group as g', 'g.id', '=', 'm.group_id')->where('m.username', $mailbox)
            ->orderBy('g.name')->get(['g.id', 'g.name', 'g.address', 'g.active', 'm.source'])->map(fn ($r): array => [
                'id' => (int) $r->id, 'name' => $r->name, 'address' => $r->address, 'active' => (bool) $r->active, 'source' => $r->source,
            ])->all();

        return response()->json(['data' => $rows]);
    }
}
