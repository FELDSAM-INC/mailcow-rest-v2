<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ListAuditEventsRequest;
use App\Http\Resources\Api\V2\AuditEventResource;
use App\Models\AuditEvent;
use App\Support\Domain\DomainName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * listAuditEvents (FR-047, FR-059, FR-060): administrators list the immutable audit records with
 * filters, newest first by default. Records cannot be changed or deleted through the API.
 */
class AuditEventController extends Controller
{
    public function index(ListAuditEventsRequest $request): JsonResponse
    {
        $filters = $request->filters();
        $query = AuditEvent::query();

        if ($filters['from'] !== null) {
            $query->where('occurred_at', '>=', $filters['from']->format('Y-m-d H:i:s.u'));
        }

        if ($filters['to'] !== null) {
            $query->where('occurred_at', '<', $filters['to']->format('Y-m-d H:i:s.u'));
        }

        foreach (['eventTypes' => 'event_type', 'authMethods' => 'auth_method'] as $filter => $column) {
            if ($filters[$filter] !== []) {
                $query->whereIn($column, $filters[$filter]);
            }
        }

        foreach (['outcome' => 'outcome', 'targetType' => 'target_type', 'requestId' => 'request_id'] as $filter => $column) {
            if ($filters[$filter] !== null) {
                $query->where($column, $filters[$filter]);
            }
        }

        if ($filters['targetId'] !== null) {
            self::whereTargetId($query, $filters['targetId'], $filters['targetType']);
        }

        if ($filters['actor'] !== null) {
            $actor = $filters['actor'];
            $query->where(fn (Builder $q) => $q->where('actor_username', $actor)->orWhere('actor_api_key_id', $actor));
        }

        if ($filters['clientAddress'] !== null) {
            self::whereClientAddress($query, $filters['clientAddress']);
        }

        // Records are written during impersonations only from feature 007 on.
        if ($filters['impersonatedAccount'] !== null) {
            $query->whereRaw('1 = 0');
        }

        $list = $request->listQuery();
        $list->applySearch($query, ['actor_username', 'target_name', 'user_agent']);
        $page = $list->paginate($query, fn (mixed $event): array => (new AuditEventResource($event))->toArray($request), 'id');

        return response()->json($page, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Records of a target identifier (feature 002 FR-058, research D16). Domain names (targets
     * `domain`, `domainFooter` and feature 010's `mtaStsPolicy`) are matched in their normalised
     * ASCII form, whatever form and letter case were given; DKIM keys (`dkimKey`, feature 010 FR-055)
     * also by the exact spelling, under which legacy may have stored an unused key; without
     * `targetType`, a value that normalises to a domain name matches it as given or normalised, so
     * that other identifiers (for example ULIDs) still match exactly.
     *
     * @param  Builder<AuditEvent>  $query
     */
    private static function whereTargetId(Builder $query, string $targetId, ?string $targetType): void
    {
        $normalised = DomainName::normalize($targetId);

        if (in_array($targetType, ['domain', 'domainFooter', 'mtaStsPolicy'], true)) {
            $query->where('target_id', $normalised ?? $targetId);
        } elseif (($targetType === 'dkimKey' || $targetType === null) && $normalised !== null && $normalised !== $targetId) {
            $query->whereIn('target_id', [$targetId, $normalised]);
        } else {
            $query->where('target_id', $targetId);
        }
    }

    /**
     * Records from an address, or from within a prefix, compared in MariaDB on the binary form of
     * the stored address, so that paging stays in SQL.
     *
     * @param  Builder<AuditEvent>  $query
     * @param  string  $network  canonical `address/prefix` (App\Rules\IpNetwork::normalize())
     */
    private static function whereClientAddress(Builder $query, string $network): void
    {
        [$address, $prefix] = explode('/', $network);
        $prefix = (int) $prefix;
        $packed = (string) inet_pton($address);
        $bits = strlen($packed) * 8;

        if ($prefix === $bits) {
            $query->whereRaw('INET6_ATON(`client_address`) = INET6_ATON(?)', [$address]);

            return;
        }

        if ($bits === 32) {
            $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
            $query->whereRaw("`client_address` NOT LIKE '%:%' AND (INET_ATON(`client_address`) & ?) = ?", [$mask, (int) ip2long($address) & $mask]);

            return;
        }

        $hex = strtoupper(bin2hex($packed));
        $digits = intdiv($prefix, 4);
        $query->whereRaw("`client_address` LIKE '%:%' AND LEFT(HEX(INET6_ATON(`client_address`)), ?) = ?", [$digits, substr($hex, 0, $digits)]);

        if ($prefix % 4 !== 0) {
            $mask = (0xF << (4 - $prefix % 4)) & 0xF;
            $query->whereRaw('(CONV(SUBSTRING(HEX(INET6_ATON(`client_address`)), ?, 1), 16, 10) & ?) = ?', [$digits + 1, $mask, hexdec($hex[$digits]) & $mask]);
        }
    }
}
