<?php

namespace App\Models\Mailcow;

use Illuminate\Database\Eloquent\Model;

/**
 * Base of the models of upstream mailcow tables (constitution III): never migrated, no Laravel
 * timestamps (mailcow maintains `created` and `modified`), and read-only through mass assignment.
 * Upstream writes happen only in app/Services.
 */
abstract class MailcowModel extends Model
{
    protected $connection = 'mysql';

    public $timestamps = false;

    protected $guarded = ['*'];
}
