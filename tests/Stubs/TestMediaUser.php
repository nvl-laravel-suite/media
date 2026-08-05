<?php

declare(strict_types=1);

namespace Nvl\Media\Tests\Stubs;

use Illuminate\Foundation\Auth\User;
use Nvl\Media\Contracts\HasMedia;
use Nvl\Media\Traits\InteractsWithMedia;

class TestMediaUser extends User implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'users';

    protected $guarded = [];
}
