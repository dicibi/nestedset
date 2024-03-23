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
class AncestorsRelation extends BaseRelation
{
    #[\Override]
    public function addConstraints(): void
    {
        if ( ! static::$constraints) return;

        $this->query->whereAncestorOf($this->parent)
            ->applyNestedSetScope();
    }

    #[\Override]
    protected function matches(Model $model, Model $related): bool
    {
        // @phpstan-ignore-next-line
        /** @var TModelClass&NodeTrait $related */

        return $related->isAncestorOf($model);
    }

    #[\Override]
    protected function addEagerConstraint(QueryBuilder $query, Model $model): void
    {
        $query->orWhereAncestorOf($model);
    }

    #[\Override]
    protected function relationExistenceCondition(string $hash, string $table, string $lft, string $rgt): string
    {
        $key = $this->getBaseQuery()->getGrammar()->wrap($this->parent->getKeyName());

        return "$table.$rgt between $hash.$lft and $hash.$rgt and $table.$key <> $hash.$key";
    }
}
