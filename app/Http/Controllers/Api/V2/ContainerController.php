<?php

namespace App\Http\Controllers\Api\V2;

use App\Clients\DockerApi\ContainerSnapshot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\ListContainersRequest;
use App\Services\Monitoring\ContainerPresenter;
use App\Services\Monitoring\ContainerRegistry;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * listContainers and getContainer (feature 011 FR-008 to FR-010): the containers of this
 * installation from the shared snapshot (at most 5 seconds old).
 */
class ContainerController extends Controller
{
    public function index(ListContainersRequest $request, ContainerRegistry $registry, ContainerPresenter $presenter): JsonResponse
    {
        $list = $request->listQuery();
        $snapshot = $registry->snapshot();
        $running = $request->running();
        $search = $list->search === null ? null : mb_strtolower($list->search);

        $containers = array_values(array_filter($snapshot->payload, fn (ContainerSnapshot $c): bool => ($running === null || $c->running === $running)
            && ($search === null || str_contains(mb_strtolower($c->service), $search) || str_contains(mb_strtolower($c->image), $search))));

        $key = fn (ContainerSnapshot $c): string => match ($list->sort) {
            'state' => $c->status,
            'startedAt' => $c->startedAt ?? '',
            default => $c->service,
        };
        usort($containers, function (ContainerSnapshot $a, ContainerSnapshot $b) use ($key, $list): int {
            $order = strcmp($key($a), $key($b)) ?: strcmp($a->service, $b->service);

            return $list->order === 'desc' ? -$order : $order;
        });

        $page = array_slice($containers, $list->offset, $list->limit);
        $collectedAt = $snapshot->collectedAtString();

        return response()->json([
            'data' => array_map(fn (ContainerSnapshot $c): array => $presenter->present($c, $collectedAt), $page),
            'meta' => $list->meta(count($containers)),
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function show(string $containerName, ContainerRegistry $registry, ContainerPresenter $presenter): JsonResponse
    {
        $snapshot = $registry->snapshot();

        foreach ($snapshot->payload as $container) {
            if ($container->service === $containerName) {
                return response()->json(['data' => $presenter->present($container, $snapshot->collectedAtString())], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        throw new NotFoundHttpException('The container does not exist.');
    }
}
