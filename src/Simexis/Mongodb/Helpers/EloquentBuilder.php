<?php

namespace Simexis\Mongodb\Helpers;

use Illuminate\Database\Eloquent\Builder;

class EloquentBuilder extends Builder
{
    use QueriesRelationships;
}
