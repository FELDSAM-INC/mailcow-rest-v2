<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-account web app preferences (API-owned table `restv2_preferences`, R21). Written by
 * App\Services\Preferences\PreferencesService only.
 *
 * @property int $id
 * @property string $account_kind
 * @property string $username
 * @property string|null $language
 * @property string|null $theme
 */
class Preference extends Model
{
    protected $table = 'restv2_preferences';

    protected $guarded = ['id'];
}
