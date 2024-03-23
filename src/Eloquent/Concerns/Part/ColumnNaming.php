<?php

namespace Kalnoy\Nestedset\Eloquent\Concerns\Part;

use Kalnoy\Nestedset\NestedSet;

trait ColumnNaming
{
    public function getLftName(): string
    {
        return NestedSet::LFT;
    }

    public function getRgtName(): string
    {
        return NestedSet::RGT;
    }

    public function getParentIdName(): string
    {
        return NestedSet::PARENT_ID;
    }
}
