<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Fixtures;

use Kanin\Database\Model;

final class User extends Model
{
    protected array $fillable = ['name', 'email'];

    protected array $hidden = ['password'];
}
