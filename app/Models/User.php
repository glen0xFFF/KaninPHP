<?php

declare(strict_types=1);

namespace App\Models;

use Kanin\Database\Model;

/**
 * A row in the "users" table (the table name comes from the class name).
 */
class User extends Model
{
    /**
     * Only these columns can be set through create() and fill().
     */
    protected array $fillable = ['name', 'email'];
}
