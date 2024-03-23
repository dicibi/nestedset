<?php

namespace Kalnoy\Nestedset\Eloquent\Concerns\Part;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kalnoy\Nestedset\Eloquent\Relations\AncestorsRelation;
use Kalnoy\Nestedset\Eloquent\Relations\DescendantsRelation;

trait Relation
{
    /**
     * Relation to the parent.
     *
     * @return BelongsTo<self, self>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(get_class($this), $this->getParentIdName())
            ->setModel($this);
    }

    /**
     * Relation to children.
     *
     * @return HasMany<self>
     */
    public function children(): HasMany
    {
        return $this->hasMany(get_class($this), $this->getParentIdName())->setModel($this);
    }

    /**
     * Relation to descendants.
     *
     * @return DescendantsRelation<self>
     */
    public function descendants(): DescendantsRelation
    {
        return new DescendantsRelation($this->newQuery(), $this);
    }

    /**
     * Relation to ancestors.
     *
     * @return AncestorsRelation<self>
     */
    public function ancestors(): AncestorsRelation
    {
        return new AncestorsRelation($this->newQuery(), $this);
    }
}
