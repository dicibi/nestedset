<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class NestedSetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Blueprint::macro('nestedSet', function () {
            /** @var Blueprint $this */
            NestedSet::columns($this);
        });

        Blueprint::macro('dropNestedSet', function () {
            /** @var Blueprint $this */
            NestedSet::dropColumns($this);
        });
    }
}
