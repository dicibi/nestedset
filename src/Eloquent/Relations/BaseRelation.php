<?php

namespace Kalnoy\Nestedset\Eloquent\Relations;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Kalnoy\Nestedset\Eloquent\Collection;
use Kalnoy\Nestedset\Eloquent\Concerns\NodeTrait;
use Kalnoy\Nestedset\Eloquent\QueryBuilder;
use Kalnoy\Nestedset\NestedSet;
use Override;

/**
 * @template TModelClass of Model
 *
 * @template-extends Relation<TModelClass>
 *
 * @property QueryBuilder<TModelClass> $query
 * @property (TModelClass&NodeTrait) $parent
 * @property (TModelClass&NodeTrait) $related
 */
abstract class BaseRelation extends Relation
{
    /**
     * The count of self joins.
     *
     * @var int
     */
    protected static $selfJoinCount = 0;

    /**
     * AncestorsRelation constructor.
     *
     * @param QueryBuilder<TModelClass> $builder
     * @param Model $model
     */
    public function __construct(QueryBuilder $builder, Model $model)
    {
        if (!NestedSet::hasNodeTrait($model)) {
            throw new InvalidArgumentException('Model must use NodeTrait.');
        }

        parent::__construct($builder, $model);
    }

    /**
     * @param TModelClass $model
     * @param TModelClass $related
     *
     * @return bool
     * @noinspection PhpDocSignatureInspection
     */
    abstract protected function matches(Model $model, Model $related): bool;

    /**
     * @param QueryBuilder<TModelClass> $query
     * @param Model $model
     *
     * @return void
     */
    abstract protected function addEagerConstraint(QueryBuilder $query, Model $model): void;

    /**
     * @param string $hash
     * @param string $table
     * @param string $lft
     * @param string $rgt
     *
     * @return string
     */
    abstract protected function relationExistenceCondition(string $hash, string $table, string $lft, string $rgt): string;

    /**
     * @param EloquentBuilder<TModelClass> $query
     * @param EloquentBuilder<TModelClass> $parentQuery
     * @param array<array-key, string>|string $columns
     *
     * @return QueryBuilder<TModelClass>
     */
    #[Override]
    public function getRelationExistenceQuery(
        EloquentBuilder $query,
        EloquentBuilder $parentQuery,
                        $columns = ['*']
    ): QueryBuilder
    {
        // @phpstan-ignore-next-line
        /** @var (Model&NodeTrait) $parentModel */
        $parentModel = $this->getParent()->replicate();

        $query = $parentModel->newScopedQuery()->select($columns);

        $table = $query->getModel()->getTable();

        $query->from($table . ' as ' . $hash = $this->getRelationCountHash());

        $query->getModel()->setTable($hash);

        $grammar = $query->getQuery()->getGrammar();

        $condition = $this->relationExistenceCondition(
            $grammar->wrapTable($hash),
            $grammar->wrapTable($table),
            $grammar->wrap($this->parent->getLftName()),
            $grammar->wrap($this->parent->getRgtName()));

        return $query->whereRaw($condition);
    }

    /**
     * Initialize the relation on a set of models.
     *
     * @param array<array-key, TModelClass> $models
     * @param string $relation
     *
     * @return array<array-key, TModelClass>
     */
    #[Override]
    public function initRelation(array $models, $relation): array
    {
        return $models;
    }

    /**
     * Get a relationship join table hash.
     *
     * @param bool $incrementJoinCount
     * @return string
     */
    #[Override]
    public function getRelationCountHash($incrementJoinCount = true): string
    {
        return 'nested_set_' . ($incrementJoinCount ? static::$selfJoinCount++ : static::$selfJoinCount);
    }

    /**
     * Get the results of the relationship.
     *
     * @return Collection<int, TModelClass>
     */
    #[Override]
    public function getResults(): Collection
    {
        return $this->query->get();
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param array<array-key, TModelClass> $models
     *
     * @return void
     */
    #[Override]
    public function addEagerConstraints(array $models): void
    {
        $this->query->whereNested(function (Builder $inner) use ($models) {
            // We will use this query to apply constraints to the
            // base query builder
            $outer = $this->parent->newQuery()->setQuery($inner);

            foreach ($models as $model) {
                $this->addEagerConstraint($outer, $model);
            }
        });
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param array<array-key, TModelClass> $models
     * @param EloquentCollection<int, TModelClass> $results
     * @param string $relation
     *
     * @return array<array-key, TModelClass>
     */
    #[Override]
    public function match(array $models, EloquentCollection $results, $relation): array
    {
        foreach ($models as $model) {
            $related = $this->matchForModel($model, $results);

            $model->setRelation($relation, $related);
        }

        return $models;
    }

    /**
     * @param TModelClass $model
     * @param EloquentCollection<int, TModelClass> $results
     *
     * @return Collection<int, TModelClass>
     */
    protected function matchForModel(Model $model, EloquentCollection $results): Collection
    {
        $result = $this->related->newCollection();

        foreach ($results as $related) {
            if ($this->matches($model, $related)) {
                $result->push($related);
            }
        }

        return $result;
    }

    /**
     * Get the plain foreign key.
     *
     * @return string
     */
    public function getForeignKeyName(): string
    {
        // Return a stub value for relation
        // resolvers which need this function.
        return NestedSet::PARENT_ID;
    }
}
