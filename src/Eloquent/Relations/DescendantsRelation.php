<?php

namespace Kalnoy\Nestedset\Eloquent\Relations;

use Illuminate\Database\Eloquent\Model;
use Kalnoy\Nestedset\Eloquent\Concerns\NodeTrait;
use Kalnoy\Nestedset\Eloquent\QueryBuilder;

/**
 * @template TModelClass of Model
 *
 * @template-extends BaseRelation<TModelClass>
 */
class DescendantsRelation extends BaseRelation
{
    #[\Override]
    public function addConstraints(): void
    {
        if ( ! static::$constraints) return;

        $this->query->whereDescendantOf($this->parent)
        ->applyNestedSetScope();
    }

    #[\Override]
    protected function addEagerConstraint(QueryBuilder $query, Model $model): void
    {
        $query->orWhereDescendantOf($model);
    }

    #[\Override]
    protected function matches(Model $model, Model $related): bool
    {
        // @phpstan-ignore-next-line
        /** @var TModelClass&NodeTrait $related */

        return $related->isDescendantOf($model);
    }

    #[\Override]
    protected function relationExistenceCondition(string $hash, string $table, string $lft, string $rgt): string
    {
        return "$hash.$lft between $table.$lft + 1 and $table.$rgt";
    }
}