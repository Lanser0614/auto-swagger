<?php

namespace Tests\Fixtures\Parsing;

use Illuminate\Database\Eloquent\Model;

/** @property string $title
 * @property string|null $note
 */
class OrderModel extends Model
{
    protected $casts = ['active' => 'boolean', 'price' => 'decimal:2'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
