<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class ItemModel extends Model
{
    protected $connection = 'catalog';

    protected $table = 'items';

    protected $casts = ['active' => 'boolean', 'details' => 'object'];
}
