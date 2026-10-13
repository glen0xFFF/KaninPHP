<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Fixtures;

use Kanin\Database\Model;

final class Account extends Model
{
    protected string $table = 'legacy_accounts';

    protected string $primaryKey = 'account_id';

    protected array $fillable = ['account_id', 'owner'];
}
