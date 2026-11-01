<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Persists one ordinary host record for the required-only upload workflow. */
final class StandaloneMediaOwner extends Model
{
    protected $table = 'standalone_media_owners';

    /** @var list<string> */
    protected $fillable = ['name'];
}
