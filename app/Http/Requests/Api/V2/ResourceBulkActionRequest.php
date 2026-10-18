<?php

namespace App\Http\Requests\Api\V2;

/**
 * Body of `applyResourceBulkAction`: `activate`, `deactivate` or `delete` on 1 to 100 resources.
 */
class ResourceBulkActionRequest extends AliasBulkActionRequest
{
    /** @var list<string> */
    protected array $actions = ['activate', 'deactivate', 'delete'];
}
