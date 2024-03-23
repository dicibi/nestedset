<?php /** @noinspection PhpUnused */

namespace Kalnoy\Nestedset\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Arr;
use Kalnoy\Nestedset\Eloquent\Concerns\NodeTrait;
use Kalnoy\Nestedset\NestedSet;
use LogicException;

/**
 * @template TModelClass of Model
 *
 * @template-extends Builder<TModelClass>
 *
 * @property (TModelClass&NodeTrait) $model
 */
class QueryBuilder extends Builder
{
    /**
     * Get node's `lft` and `rgt` values.
     *
     * @param int|string $id
     * @param bool $required
     *
     * @return array{_lft: int, _rgt: int}
     */
    public function getNodeData(int|string $id, bool $required = false): array
    {
        $query = $this->toBase();

        $query->where($this->model->getKeyName(), '=', $id);

        $data = $query->first([
            $this->model->getLftName(),
            $this->model->getRgtName(),
        ]);

        if (!$data && $required) {
            throw new ModelNotFoundException;
        }

        /** @var array{_lft: int, _rgt: int} */
        return (array)$data;
    }

    /**
     * Get plain node data.
     *
     * @param int|string $id
     * @param bool $required
     * @return array<array-key, int>
     */
    public function getPlainNodeData(int|string $id, bool $required = false): array
    {
        return array_values($this->getNodeData($id, $required));
    }

    /**
     * Scope limits query to select just root node.
     *
     * @return $this
     */
    public function whereIsRoot(): static
    {
        $this->query->whereNull($this->model->getParentIdName());

        return $this;
    }

    /**
     * Limit results to ancestors of specified node.
     *
     * @param mixed $id
     * @param bool $andSelf
     * @param 'and' | 'or' $boolean
     * @return $this
     */
    public function whereAncestorOf(mixed $id, bool $andSelf = false, string $boolean = 'and'): static
    {
        $keyName = $this->model->getTable() . '.' . $this->model->getKeyName();

        /** @var (Model&NodeTrait)|null $parentModel */
        $parentModel = null;

        if ($id instanceof Model && NestedSet::hasNodeTrait($id)) {
            // @phpstan-ignore-next-line
            /** @var (Model&NodeTrait) $parentModel */
            $parentModel = $id;
            $value = '?';

            $this->query->addBinding($parentModel->getRgt());

            $id = $parentModel->getKey();
        } else {
            $valueQuery = $this->model
                ->newQuery()
                ->toBase()
                ->select('_.' . $this->model->getRgtName())
                ->from($this->model->getTable() . ' as _')
                ->where($this->model->getKeyName(), '=', $id)
                ->limit(1);

            $this->query->mergeBindings($valueQuery);

            $value = '(' . $valueQuery->toSql() . ')';
        }

        $this->query->whereNested(function ($inner) use ($parentModel, $value, $andSelf, $id, $keyName) {
            [$lft, $rgt] = $this->wrappedColumns();
            $wrappedTable = $this->query->getGrammar()->wrapTable($this->model->getTable());

            $inner->whereRaw("$value between $wrappedTable.$lft and $wrappedTable.$rgt");

            if (!$andSelf) {
                $inner->where($keyName, '<>', $id);
            }

            // @phpstan-ignore-next-line
            $parentModel?->applyNestedSetScope($inner);
        }, $boolean);

        return $this;
    }

    /**
     * @param mixed $id
     * @param bool $andSelf
     * @return $this
     */
    public function orWhereAncestorOf(mixed $id, bool $andSelf = false): static
    {
        return $this->whereAncestorOf($id, $andSelf, 'or');
    }

    /**
     * @param mixed $id
     * @return $this
     */
    public function whereAncestorOrSelf(mixed $id): static
    {
        return $this->whereAncestorOf($id, true);
    }

    /**
     * Get ancestors of specified node.
     *
     * @param mixed $id
     * @param array<array-key, string>|string $columns
     * @return Collection<int, TModelClass>
     */
    public function ancestorsOf(mixed $id, array|string $columns = ['*']): Collection
    {
        return $this->whereAncestorOf($id)->get($columns);
    }

    /**
     * Get ancestors and the node itself.
     *
     * @param mixed $id
     * @param array<array-key, string>|string $columns
     * @return Collection<int, TModelClass>
     */
    public function ancestorsAndSelf(mixed $id, array|string $columns = ['*']): Collection
    {
        return $this->whereAncestorOf($id, true)->get($columns);
    }

    /**
     * Add node selection statement between specified range.
     *
     * @param array<array-key, int> $values
     * @param 'and' | 'or' $boolean
     * @param bool $not
     * @param Query|null $query
     * @return $this
     * @since 2.0
     */
    public function whereNodeBetween(
        array      $values,
        string     $boolean = 'and',
        bool       $not = false,
        Query|null $query = null,
    ): static
    {
        ($query ?? $this->query)
            ->whereBetween(
                column: $this->model->getTable() . '.' . $this->model->getLftName(),
                values: $values,
                boolean: $boolean,
                not: $not,
            );

        return $this;
    }

    /**
     * Add a node selection statement between specified range joined with `or` operator.
     *
     * @param array<array-key, int> $values
     * @return $this
     *
     */
    public function orWhereNodeBetween(array $values): static
    {
        return $this->whereNodeBetween($values, 'or');
    }

    /**
     * Add a constraint statement to descendants of specified node.
     *
     * @param mixed $id
     * @param 'and' | 'or' $boolean
     * @param bool $not
     * @param bool $andSelf
     * @return $this
     * @since 2.0
     *
     */
    public function whereDescendantOf(
        mixed  $id,
        string $boolean = 'and',
        bool   $not = false,
        bool   $andSelf = false,
    ): static
    {
        $this->query->whereNested(function (Query $inner) use ($id, $andSelf, $not) {
            if ($id instanceof Model && NestedSet::hasNodeTrait($id)) {
                // @phpstan-ignore-next-line
                /** @var (Model&NodeTrait) $childModel */
                $childModel = $id;
                $childModel->applyNestedSetScope($inner);
                $data = $childModel->getBounds();
            } else {
                // We apply scope only when the Node was passed as $id.
                // In other cases, according to docs, query should be scoped() before calling this method
                $data = $this->model->newNestedSetQuery()
                    ->getPlainNodeData($id, true);
            }

            // Don't include the node
            if (!$andSelf) {
                $data[0]++;
            }

            return $this->whereNodeBetween($data, 'and', $not, $inner);
        }, $boolean);

        return $this;
    }

    /**
     * @param mixed $id
     * @return $this
     */
    public function whereNotDescendantOf(mixed $id): static
    {
        return $this->whereDescendantOf($id, 'and', true);
    }

    /**
     * @param mixed $id
     * @return $this
     */
    public function orWhereDescendantOf(mixed $id): static
    {
        return $this->whereDescendantOf($id, 'or');
    }

    /**
     * @param mixed $id
     * @return $this
     */
    public function orWhereNotDescendantOf(mixed $id): static
    {
        return $this->whereDescendantOf($id, 'or', true);
    }

    /**
     * @param mixed $id
     * @param 'and' | 'or' $boolean
     * @param bool $not
     * @return $this
     */
    public function whereDescendantOrSelf(mixed $id, string $boolean = 'and', bool $not = false): static
    {
        return $this->whereDescendantOf($id, $boolean, $not, true);
    }

    /**
     * Get descendants of specified node.
     *
     * @param mixed $id
     * @param array<array-key, string>|string $columns
     * @param bool $andSelf
     * @return Collection<int, TModelClass>
     */
    public function descendantsOf(mixed $id, array|string $columns = ['*'], bool $andSelf = false): Collection
    {
        try {
            return $this->whereDescendantOf($id, 'and', false, $andSelf)->get($columns);
        } catch (ModelNotFoundException) {
            return $this->model->newCollection();
        }
    }

    /**
     * @param mixed $id
     * @param array<array-key, string>|string $columns
     * @return Collection<int, TModelClass>
     */
    public function descendantsAndSelf(mixed $id, array|string $columns = ['*']): Collection
    {
        return $this->descendantsOf($id, $columns, true);
    }

    /**
     * @param mixed $id
     * @param string $operator
     * @param 'and' | 'or' $boolean
     * @return $this
     */
    protected function whereIsBeforeOrAfter(mixed $id, string $operator, string $boolean): static
    {
        if ($id instanceof Model && NestedSet::hasNodeTrait($id)) {
            // @phpstan-ignore-next-line
            /** @var (Model&NodeTrait) $model */
            $model = $id;

            $value = '?';

            $this->query->addBinding($model->getLft());
        } else {
            $valueQuery = $this->model
                ->newQuery()
                ->toBase()
                ->select('_n.' . $this->model->getLftName())
                ->from($this->model->getTable() . ' as _n')
                ->where('_n.' . $this->model->getKeyName(), '=', $id);

            $this->query->mergeBindings($valueQuery);

            $value = '(' . $valueQuery->toSql() . ')';
        }

        [$lft] = $this->wrappedColumns();

        $this->query->whereRaw("$lft $operator $value", [], $boolean);

        return $this;
    }

    /**
     * Constraint nodes to those that are after specified node.
     *
     * @param mixed $id
     * @param 'and' | 'or' $boolean
     * @return $this
     */
    public function whereIsAfter(mixed $id, string $boolean = 'and'): static
    {
        return $this->whereIsBeforeOrAfter($id, '>', $boolean);
    }

    /**
     * Constraint nodes to those that are before specified node.
     *
     * @param mixed $id
     * @param 'and' | 'or' $boolean
     * @return $this
     */
    public function whereIsBefore(mixed $id, string $boolean = 'and'): static
    {
        return $this->whereIsBeforeOrAfter($id, '<', $boolean);
    }

    /**
     * @return $this
     */
    public function whereIsLeaf(): static
    {
        [$lft, $rgt] = $this->wrappedColumns();

        // @phpstan-ignore-next-line
        return $this->whereRaw("$lft = $rgt - 1");
    }

    /**
     * @param array<array-key, string>|string $columns
     * @return Collection<int, TModelClass>
     */
    public function leaves(array|string $columns = ['*']): Collection
    {
        return $this->whereIsLeaf()->get($columns);
    }

    /**
     * Include depth level in the result.
     *
     * @param string $as
     * @return $this
     */
    public function withDepth(string $as = 'depth'): static
    {
        if ($this->query->columns === null) {
            $this->query->columns = ['*'];
        }

        $table = $this->wrappedTable();

        [$lft, $rgt] = $this->wrappedColumns();

        $alias = '_d';
        $wrappedAlias = $this->query->getGrammar()->wrapTable($alias);

        $query = $this->model
            ->newScopedQuery('_d')
            ->toBase()
            ->selectRaw('count(1) - 1')
            ->from($this->model->getTable() . ' as ' . $alias)
            ->whereRaw("$table.$lft between $wrappedAlias.$lft and $wrappedAlias.$rgt");

        $this->query->selectSub($query, $as);

        return $this;
    }

    /**
     * Get wrapped `lft` and `rgt` column names.
     *
     * @return array<array-key, string>
     */
    protected function wrappedColumns(): array
    {
        $grammar = $this->query->getGrammar();

        return [
            $grammar->wrap($this->model->getLftName()),
            $grammar->wrap($this->model->getRgtName()),
        ];
    }

    /**
     * Get a wrapped table name.
     *
     * @return string
     */
    protected function wrappedTable(): string
    {
        return $this->query->getGrammar()->wrapTable($this->getQuery()->from);
    }

    /**
     * Wrap model's key name.
     *
     * @return string
     */
    protected function wrappedKey(): string
    {
        return $this->query->getGrammar()->wrap($this->model->getKeyName());
    }

    /**
     * Exclude root node from the result.
     *
     * @return $this
     */
    public function withoutRoot(): static
    {
        $this->query->whereNotNull($this->model->getParentIdName());

        return $this;
    }

    /**
     * Order by node position.
     *
     * @param 'asc' | 'desc' $direction
     * @return $this
     */
    public function defaultOrder(string $direction = 'asc'): static
    {
        // reset orders
        $this->query->orders = [];

        $this->query->orderBy($this->model->getLftName(), $direction);

        return $this;
    }

    /**
     * Order by reversed node position.
     *
     * @return $this
     */
    public function reversed(): static
    {
        return $this->defaultOrder('desc');
    }

    /**
     * Move a node to the new position.
     *
     * @param int|string $key
     * @param int $position
     * @return int
     */
    public function moveNode(int|string $key, int $position): int
    {
        [$lft, $rgt] = $this->model
            ->newNestedSetQuery()
            ->getPlainNodeData($key, true);

        if ($lft < $position && $position <= $rgt) {
            throw new LogicException('Cannot move node into itself.');
        }

        // Get boundaries of nodes that should be moved to new position
        $from = min($lft, $position);
        $to = max($rgt, $position - 1);

        // The height of the node that is being moved
        $height = $rgt - $lft + 1;

        // The distance that our node will travel to reach it's destination
        $distance = $to - $from + 1 - $height;

        // If no distance to travel, just return
        if ($distance === 0) {
            return 0;
        }

        if ($position > $lft) {
            $height *= -1;
        } else {
            $distance *= -1;
        }

        $params = compact('lft', 'rgt', 'from', 'to', 'height', 'distance');

        $boundary = [$from, $to];

        $query = $this->toBase()->where(function (Query $inner) use ($boundary) {
            $inner->whereBetween($this->model->getLftName(), $boundary);
            $inner->orWhereBetween($this->model->getRgtName(), $boundary);
        });

        return $query->update($this->patch($params));
    }

    /**
     * Make or remove a gap in the tree. Negative height will remove gap.
     *
     * @param int $cut
     * @param int $height
     * @return int
     */
    public function makeGap(int $cut, int $height): int
    {
        $params = compact('cut', 'height');

        $query = $this->toBase()->whereNested(function (Query $inner) use ($cut) {
            $inner->where($this->model->getLftName(), '>=', $cut);
            $inner->orWhere($this->model->getRgtName(), '>=', $cut);
        });

        return $query->update($this->patch($params));
    }

    /**
     * Get a patch for columns.
     *
     * @param array{lft?: int, rgt?: int, from?: int, to?: int, cut?: int, height?: int, distance?: int,} $params
     * @return array<string, Expression>
     */
    protected function patch(array $params): array
    {
        $grammar = $this->query->getGrammar();

        $columns = [];

        foreach ([$this->model->getLftName(), $this->model->getRgtName()] as $col) {
            $columns[$col] = $this->columnPatch($grammar->wrap($col), $params);
        }

        /** @var array<string, Expression> */
        return $columns;
    }

    /**
     * Get a patch for single column.
     *
     * @param string $col
     * @param array<string, mixed> $params
     * @return Expression
     */
    protected function columnPatch(string $col, array $params): Expression
    {
        extract($params);

        /** @var int $height */
        if ($height > 0) {
            $height = '+' . $height;
        }

        if (isset($cut)) {
            return new Expression("case when $col >= $cut then $col$height else $col end");
        }

        /** @var int $distance */
        /** @var int $lft */
        /** @var int $rgt */
        /** @var int $from */
        /** @var int $to */
        if ($distance > 0) {
            $distance = '+' . $distance;
        }

        return new Expression('case ' .
            "when $col between $lft and $rgt then $col$distance " . // Move the node
            "when $col between $from and $to then $col$height " . // Move other nodes
            "else $col end"
        );
    }

    /**
     * Get statistics of errors in the tree.
     *
     * @return array{oddness: int, duplicates: int, wrong_parent: int, missing_parent: int}
     */
    public function countErrors(): array
    {
        $checks = [];

        // Check if lft and rgt values are ok
        $checks['oddness'] = function (): Query {
            return $this->model
                ->newNestedSetQuery()
                ->toBase()
                ->whereNested(function (Query $inner) {
                    [$lft, $rgt] = $this->wrappedColumns();

                    $inner->whereRaw("$lft >= $rgt")
                        ->orWhereRaw("($rgt - $lft) % 2 = 0");
                });
        };

        // Check if lft and rgt values are unique
        $checks['duplicates'] = function (): Query {
            $table = $this->wrappedTable();
            $keyName = $this->wrappedKey();

            $firstAlias = 'c1';
            $secondAlias = 'c2';

            $waFirst = $this->query->getGrammar()->wrapTable($firstAlias);
            $waSecond = $this->query->getGrammar()->wrapTable($secondAlias);

            /** @noinspection PhpParamsInspection */
            $query = $this->model
                ->newNestedSetQuery($firstAlias)
                ->toBase()
                ->from($this->query->raw("$table as $waFirst, $table $waSecond"))
                ->whereRaw("$waFirst.$keyName < $waSecond.$keyName")
                ->whereNested(function (Query $inner) use ($waFirst, $waSecond) {
                    [$lft, $rgt] = $this->wrappedColumns();

                    $inner->orWhereRaw("$waFirst.$lft=$waSecond.$lft")
                        ->orWhereRaw("$waFirst.$rgt=$waSecond.$rgt")
                        ->orWhereRaw("$waFirst.$lft=$waSecond.$rgt")
                        ->orWhereRaw("$waFirst.$rgt=$waSecond.$lft");
                });

            return $this->model->applyNestedSetScope($query, $secondAlias);
        };

        // Check if parent_id is set correctly
        $checks['wrong_parent'] = function (): Query {
            $table = $this->wrappedTable();
            $keyName = $this->wrappedKey();

            $grammar = $this->query->getGrammar();

            $parentIdName = $grammar->wrap($this->model->getParentIdName());

            $parentAlias = 'p';
            $childAlias = 'c';
            $intermAlias = 'i';

            $waParent = $grammar->wrapTable($parentAlias);
            $waChild = $grammar->wrapTable($childAlias);
            $waInterm = $grammar->wrapTable($intermAlias);

            /** @noinspection PhpParamsInspection */
            $query = $this->model
                ->newNestedSetQuery('c')
                ->toBase()
                ->from($this->query->raw("$table as $waChild, $table as $waParent, $table as $waInterm"))
                ->whereRaw("$waChild.$parentIdName=$waParent.$keyName")
                ->whereRaw("$waInterm.$keyName <> $waParent.$keyName")
                ->whereRaw("$waInterm.$keyName <> $waChild.$keyName")
                ->whereNested(function (Query $inner) use ($waInterm, $waChild, $waParent) {
                    [$lft, $rgt] = $this->wrappedColumns();

                    $inner->whereRaw("$waChild.$lft not between $waParent.$lft and $waParent.$rgt")
                        ->orWhereRaw("$waChild.$lft between $waInterm.$lft and $waInterm.$rgt")
                        ->whereRaw("$waInterm.$lft between $waParent.$lft and $waParent.$rgt");
                });

            $this->model->applyNestedSetScope($query, $parentAlias);
            $this->model->applyNestedSetScope($query, $intermAlias);

            return $query;
        };

        // Check for nodes that have missing parent
        $checks['missing_parent'] = function (): Query {
            return $this->model
                ->newNestedSetQuery()
                ->toBase()
                ->whereNested(function (Query $inner) {
                    $grammar = $this->query->getGrammar();

                    $table = $this->wrappedTable();
                    $keyName = $this->wrappedKey();
                    $parentIdName = $grammar->wrap($this->model->getParentIdName());
                    $alias = 'p';
                    $wrappedAlias = $grammar->wrapTable($alias);

                    /** @noinspection PhpParamsInspection */
                    $existsCheck = $this->model
                        ->newNestedSetQuery()
                        ->toBase()
                        ->selectRaw('1')
                        ->from($this->query->raw("$table as $wrappedAlias"))
                        ->whereRaw("$table.$parentIdName = $wrappedAlias.$keyName")
                        ->limit(1);

                    $this->model->applyNestedSetScope($existsCheck, $alias);

                    $inner->whereRaw("$parentIdName is not null")
                        ->addWhereExistsQuery($existsCheck, 'and', true);
                });
        };

        $query = $this->query->newQuery();

        foreach ($checks as $key => $callback) {
            $inner = $callback();

            $inner->selectRaw('count(1)');

            $query->selectSub($inner, $key);
        }

        /** @var array{oddness: int, duplicates: int, wrong_parent: int, missing_parent: int} */
        return (array)$query->first();
    }

    /**
     * Get the number of total errors of the tree.
     *
     * @return int
     */
    public function getTotalErrors(): int
    {
        return array_sum($this->countErrors());
    }

    /**
     * Get whether the tree is broken.
     *
     * @return bool
     */
    public function isBroken(): bool
    {
        return $this->getTotalErrors() > 0;
    }

    /**
     * Fixes the tree based on parentage info.
     *
     * Nodes with invalid parent are saved as roots.
     *
     * @param (TModelClass&NodeTrait)|null $root
     * @return int The number of changed nodes
     */
    public function fixTree(Model|null $root = null): int
    {
        $columns = [
            $this->model->getKeyName(),
            $this->model->getParentIdName(),
            $this->model->getLftName(),
            $this->model->getRgtName(),
        ];

        $dictionary = $this->model
            ->newNestedSetQuery()
            ->when($root, function (self $query) use ($root) {
                return $query->whereDescendantOf($root);
            })
            ->defaultOrder()
            ->get($columns)
            ->groupBy($this->model->getParentIdName())
            ->all();

        return $this->fixNodes($dictionary, $root);
    }

    /**
     * @param (Model&NodeTrait)|null $root
     * @return int
     */
    public function fixSubtree(Model|null $root): int
    {
        return $this->fixTree($root);
    }

    /**
     * @param array<int|string, Collection<array-key, TModelClass>> $dictionary
     * @param (TModelClass&NodeTrait)|null $parent
     * @return int
     */
    protected function fixNodes(array &$dictionary, Model|null $parent = null): int
    {
        /** @var int|string $parentId */
        $parentId = $parent?->getKey();
        // @phpstan-ignore-next-line
        $cut = $parent ? $parent->getLft() + 1 : 1;

        /** @var array<array-key, TModelClass> $updated */
        $updated = [];
        $moved = 0;

        $cut = self::reorderNodes($dictionary, $updated, $parentId, $cut);

        // Save nodes that have invalid parent as roots
        while (!empty($dictionary)) {
            $dictionary[null] = reset($dictionary);

            unset($dictionary[key($dictionary)]);

            $cut = self::reorderNodes($dictionary, $updated, $parentId, $cut);
        }

        // @phpstan-ignore-next-line
        if ($parent && ($grown = $cut - $parent->getRgt()) != 0) {
            // @phpstan-ignore-next-line
            $moved = $this->model->newScopedQuery()->makeGap($parent->getRgt() + 1, $grown);
            // @phpstan-ignore-next-line
            $updated[] = $parent->rawNode($parent->getLft(), $cut, $parent->getParentId());
        }

        foreach ($updated as $model) {
            $model->save();
        }

        return count($updated) + $moved;
    }

    /**
     * @param array<int|string, Collection<array-key, TModelClass>> $dictionary
     * @param array<array-key, TModelClass> $updated
     * @param int|string|null $parentId
     * @param int $cut
     * @return int
     */
    protected static function reorderNodes(
        array           &$dictionary,
        array           &$updated,
        int|string|null $parentId = null,
        int             $cut = 1,
    ): int
    {
        if (!isset($dictionary[$parentId])) {
            return $cut;
        }

        // @phpstan-ignore-next-line
        /** @var (Model&NodeTrait) $model */

        foreach ($dictionary[$parentId] as $model) {
            $lft = $cut;
            /** @var int|string $parentId */
            $childId = $model->getKey();
            $cut = self::reorderNodes($dictionary, $updated, $childId, $cut + 1);

            if ($model->rawNode($lft, $cut, $parentId)->isDirty()) {
                $updated[] = $model;
            }

            $cut++;
        }

        unset($dictionary[$parentId]);

        return $cut;
    }

    /**
     * Rebuild the tree based on raw data.
     *
     * If item data does not contain primary key, a new node will be created.
     *
     * @param array<string, mixed> $data
     * @param bool $delete Whether to delete nodes that exist but not in the data array
     * @param (TModelClass&NodeTrait)|null $root
     * @return int
     */
    public function rebuildTree(
        array      $data,
        bool       $delete = false,
        Model|null $root = null,
    ): int
    {
        if ($this->model::usesSoftDelete()) {
            /**
             * @noinspection PhpUndefinedMethodInspection
             * @phpstan-ignore-next-line
             */
            $this->withTrashed();
        }

        /** @var array<array-key, TModelClass> $existing */
        $existing = $this
            ->when($root, function (self $query) use ($root) {
                return $query->whereDescendantOf($root);
            })
            ->get()
            ->getDictionary();

        $dictionary = [];
        /** @var int|string $parentId */
        $parentId = $root?->getKey();

        $this->buildRebuildDictionary($dictionary, $data, $existing, $parentId);

        if (!empty($existing)) {
            if ($delete && !$this->model->usesSoftDelete()) {
                $this->model
                    ->newScopedQuery()
                    ->whereIn($this->model->getKeyName(), array_keys($existing))
                    ->delete();
            } else {
                foreach ($existing as $model) {
                    $dictionary[$model->getParentId()][] = $model;

                    if ($delete
                        && $this->model->usesSoftDelete()
                        && !$model->{$model->getDeletedAtColumn()}
                    ) {
                        $time = $this->model->fromDateTime($this->model->freshTimestamp());

                        $model->{$model->getDeletedAtColumn()} = $time;
                    }
                }
            }
        }

        return $this->fixNodes($dictionary, $root);
    }

    /**
     * @param (TModelClass&NodeTrait)|null $root
     * @param array<string, mixed> $data
     * @param bool $delete
     * @return int
     */
    public function rebuildSubtree(Model|null $root, array $data, bool $delete = false): int
    {
        return $this->rebuildTree($data, $delete, $root);
    }

    /**
     * @param array<int|string, Collection<array-key, TModelClass>> $dictionary
     * @param array<int|string, mixed> $data
     * @param array<array-key, TModelClass> $existing
     * @param int|string|null $parentId
     */
    protected function buildRebuildDictionary(
        array           &$dictionary,
        array           $data,
        array           &$existing,
        int|string|null $parentId = null,
    ): void
    {
        $keyName = $this->model->getKeyName();

        foreach ($data as $itemData) {
            /** @var array<string, mixed> $itemData */

            if (!isset($itemData[$keyName])) {
                $model = $this->model->newInstance($this->model->getAttributes());

                // Set some values that will be fixed later
                $model->rawNode(0, 0, $parentId);
            } else {
                if (!isset($existing[$key = $itemData[$keyName]])) {
                    throw new ModelNotFoundException;
                }

                $model = $existing[$key];

                // Disable any tree actions
                $model->rawNode($model->getLft(), $model->getRgt(), $parentId);

                unset($existing[$key]);
            }

            $model->fill(Arr::except($itemData, 'children'))->save();

            $dictionary[$parentId][] = $model;

            if (!isset($itemData['children'])) {
                continue;
            }

            $this->buildRebuildDictionary(
                $dictionary,
                // @phpstan-ignore-next-line
                $itemData['children'],
                $existing,
                $model->getKey());
        }
    }

    /**
     * @param string|null $table
     * @return $this
     */
    public function applyNestedSetScope(string $table = null): static
    {
        return $this->model->applyNestedSetScope($this, $table);
    }

    /**
     * Get the root node.
     *
     *
     * @param array<array-key, string>|string $columns
     * @return TModelClass|null
     */
    public function root(array|string $columns = ['*'])
    {
        /** @var TModelClass|null */
        return $this->whereIsRoot()->first($columns);
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param array<array-key, string>|string $columns
     * @return Collection<int, TModelClass>
     */
    public function get($columns = ['*']): Collection
    {
        /** @var Collection<int, TModelClass> */
        return parent::get($columns);
    }
}
