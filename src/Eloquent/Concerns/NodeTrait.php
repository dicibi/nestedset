<?php

namespace Kalnoy\Nestedset\Eloquent\Concerns;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Kalnoy\Nestedset\Eloquent\Collection;
use Kalnoy\Nestedset\Eloquent\QueryBuilder;
use LogicException;

/**
 * @method static QueryBuilder<self> query()
 * @method QueryBuilder<self> newQuery()
 */
trait NodeTrait
{
    use Part\Relation;
    use Part\Checker;
    use Part\NodeAttribute;
    use Part\ColumnNaming;

    /**
     * Pending operation.
     *
     * @var array<string, array<int, mixed>>|null
     */
    protected ?array $pending = null;

    /**
     * Whether the node has moved since last save.
     */
    protected bool $moved = false;

    public static Carbon $deletedAt;

    /**
     * Keep track the number of performed operations.
     */
    public static int $actionsPerformed = 0;

    /**
     * Sign on model events.
     */
    public static function bootNodeTrait(): void
    {
        static::saving(function (self $model) {
            $model->callPendingAction();
        });

        static::deleting(function (self $model) {
            // We will need fresh data to delete the node safely
            $model->refreshNode();
        });

        static::deleted(function (self $model) {
            $model->deleteDescendants();
        });

        if (static::usesSoftDelete()) {
            static::restoring(function (self $model) {
                // todo possibly a bug here
                static::$deletedAt = $model->{$model->getDeletedAtColumn()};
            });

            static::restored(function ($model) {
                $model->restoreDescendants(static::$deletedAt);
            });
        }
    }

    /**
     * Set an action.
     */
    protected function setNodeAction(string $action): static
    {
        $this->pending = func_get_args();

        return $this;
    }

    protected function callPendingAction(): void
    {
        $this->moved = false;

        if (! $this->pending && ! $this->exists) {
            $this->makeRoot();
        }

        if (! $this->pending) {
            return;
        }

        $method = 'action'.ucfirst(array_shift($this->pending));
        $parameters = $this->pending;

        $this->pending = null;

        $this->moved = call_user_func_array([$this, $method], $parameters);
    }

    public static function usesSoftDelete(): bool
    {
        static $softDelete;

        if (is_null($softDelete)) {
            $instance = new static;

            return $softDelete = method_exists($instance, 'bootSoftDeletes');
        }

        return $softDelete;
    }

    protected function actionRaw(): bool
    {
        return true;
    }

    protected function actionRoot(): bool
    {
        // Simplest case that does not affect other nodes.
        if (! $this->exists) {
            $cut = $this->getLowerBound() + 1;

            $this->setLft($cut);
            $this->setRgt($cut + 1);

            return true;
        }

        return $this->insertAt($this->getLowerBound() + 1);
    }

    protected function getLowerBound(): int
    {
        return (int) $this->newNestedSetQuery()->max($this->getRgtName());
    }

    protected function actionAppendOrPrepend(self $parent, bool $prepend = false): bool
    {
        $parent->refreshNode();

        $cut = $prepend ? $parent->getLft() + 1 : $parent->getRgt();

        if (! $this->insertAt($cut)) {
            return false;
        }

        $parent->refreshNode();

        return true;
    }

    /**
     * Apply parent model.
     */
    protected function setParent(?self $value): static
    {
        $this->setParentId($value?->getKey())
            ->setRelation('parent', $value);

        return $this;
    }

    protected function actionBeforeOrAfter(self $node, bool $after = false): bool
    {
        $node->refreshNode();

        return $this->insertAt($after ? $node->getRgt() + 1 : $node->getLft());
    }

    /**
     * Refresh node's crucial attributes.
     */
    public function refreshNode(): void
    {
        if (! $this->exists || static::$actionsPerformed === 0) {
            return;
        }

        $attributes = $this->newNestedSetQuery()->getNodeData($this->getKey());

        $this->attributes = array_merge($this->attributes, $attributes);
    }

    /**
     * Get a query for siblings of the node.
     *
     * @return QueryBuilder<self>
     */
    public function siblings(): QueryBuilder
    {
        return $this->newScopedQuery()
            ->where($this->getKeyName(), '<>', $this->getKey())
            ->where($this->getParentIdName(), '=', $this->getParentId());
    }

    /**
     * Get the node siblings and the node itself.
     *
     * @return QueryBuilder<self>
     */
    public function siblingsAndSelf(): QueryBuilder
    {
        return $this->newScopedQuery()
            ->where($this->getParentIdName(), '=', $this->getParentId());
    }

    /**
     * Get query for the node siblings and the node itself.
     *
     * @param  array<array-key, string>  $columns
     * @return EloquentCollection<self>
     */
    public function getSiblingsAndSelf(array $columns = ['*']): EloquentCollection
    {
        return $this->siblingsAndSelf()->get($columns);
    }

    /**
     * Get query for siblings after the node.
     *
     * @return QueryBuilder<self>
     */
    public function nextSiblings(): QueryBuilder
    {
        return $this->nextNodes()
            ->where($this->getParentIdName(), '=', $this->getParentId());
    }

    /**
     * Get query for siblings before the node.
     *
     * @return QueryBuilder<self>
     */
    public function prevSiblings(): QueryBuilder
    {
        return $this->prevNodes()
            ->where($this->getParentIdName(), '=', $this->getParentId());
    }

    /**
     * Get query for nodes after current node.
     *
     * @return QueryBuilder<self>
     */
    public function nextNodes(): QueryBuilder
    {
        return $this->newScopedQuery()
            ->where($this->getLftName(), '>', $this->getLft());
    }

    /**
     * Get query for nodes before current node in reversed order.
     *
     * @return QueryBuilder<self>
     */
    public function prevNodes(): QueryBuilder
    {
        return $this->newScopedQuery()
            ->where($this->getLftName(), '<', $this->getLft());
    }

    /**
     * Make this node a root node.
     */
    public function makeRoot(): static
    {
        $this->setParent(null)->dirtyBounds();

        return $this->setNodeAction('root');
    }

    /**
     * Save node as root.
     */
    public function saveAsRoot(): bool
    {
        if ($this->exists && $this->isRoot()) {
            return $this->save();
        }

        return $this->makeRoot()->save();
    }

    /**
     * Append and save a node.
     *
     * @param  static  $node
     */
    public function appendNode(self $node): bool
    {
        return $node->appendToNode($this)->save();
    }

    /**
     * Prepend and save a node.
     *
     * @param  static  $node
     */
    public function prependNode(self $node): bool
    {
        return $node->prependToNode($this)->save();
    }

    /**
     * Append a node to the new parent.
     *
     * @param  static  $parent
     */
    public function appendToNode(self $parent): static
    {
        return $this->appendOrPrependTo($parent);
    }

    /**
     * Prepend a node to the new parent.
     *
     * @param  static  $parent
     */
    public function prependToNode(self $parent): static
    {
        return $this->appendOrPrependTo($parent, true);
    }

    /**
     * @param  static  $parent
     */
    public function appendOrPrependTo(self $parent, bool $prepend = false): static
    {
        $this->assertNodeExists($parent)
            ->assertNotDescendant($parent)
            ->assertSameScope($parent);

        $this->setParent($parent)->dirtyBounds();

        return $this->setNodeAction('appendOrPrepend', $parent, $prepend);
    }

    /**
     * Insert self after a node.
     *
     * @param static $node
     * @return self
     */
    public function afterNode(self $node): static
    {
        return $this->beforeOrAfterNode($node, true);
    }

    /**
     * Insert self before node.
     *
     * @param static $node
     * @return self
     */
    public function beforeNode(self $node): static
    {
        return $this->beforeOrAfterNode($node);
    }

    /**
     * @param static $node
     * @param bool $after
     * @return self
     */
    public function beforeOrAfterNode(self $node, bool $after = false): static
    {
        $this->assertNodeExists($node)
            ->assertNotDescendant($node)
            ->assertSameScope($node);

        if (! $this->isSiblingOf($node)) {
            $this->setParent($node->getRelationValue('parent'));
        }

        $this->dirtyBounds();

        return $this->setNodeAction('beforeOrAfter', $node, $after);
    }

    /**
     * Insert self after a node and save.
     */
    public function insertAfterNode(self $node): bool
    {
        return $this->afterNode($node)->save();
    }

    /**
     * Insert self before a node and save.
     */
    public function insertBeforeNode(self $node): bool
    {
        if (! $this->beforeNode($node)->save()) {
            return false;
        }

        // We'll update the target node since it will be moved
        $node->refreshNode();

        return true;
    }

    public function rawNode(int $lft, int $rgt, int|null $parentId): static
    {
        $this->setLft($lft)->setRgt($rgt)->setParentId($parentId);

        return $this->setNodeAction('raw');
    }

    /**
     * Move node up given amount of positions.
     */
    public function up(int $amount = 1): bool
    {
        $sibling = $this->prevSiblings()
            ->defaultOrder('desc')
            ->skip($amount - 1)
            ->first();

        if (! $sibling) {
            return false;
        }

        return $this->insertBeforeNode($sibling);
    }

    /**
     * Move node down given amount of positions.
     */
    public function down(int $amount = 1): bool
    {
        $sibling = $this->nextSiblings()
            ->defaultOrder()
            ->skip($amount - 1)
            ->first();

        if (! $sibling) {
            return false;
        }

        return $this->insertAfterNode($sibling);
    }

    /**
     * Insert node at specific position.
     */
    protected function insertAt(int $position): bool
    {
        static::$actionsPerformed++;

        return $this->exists
            ? $this->moveNode($position)
            : $this->insertNode($position);
    }

    /**
     * Move a node to the new position.
     */
    protected function moveNode(int $position): bool
    {
        $updated = $this->newNestedSetQuery()
            ->moveNode($this->getKey(), $position) > 0;

        if ($updated) {
            $this->refreshNode();
        }

        return $updated;
    }

    /**
     * Insert new node at specified position.
     */
    protected function insertNode(int $position): bool
    {
        $this->newNestedSetQuery()->makeGap($position, 2);

        $height = $this->getNodeHeight();

        $this->setLft($position);
        $this->setRgt($position + $height - 1);

        return true;
    }

    /**
     * Update the tree when the node is removed physically.
     */
    protected function deleteDescendants(): void
    {
        $lft = $this->getLft();
        $rgt = $this->getRgt();

        $method = $this->usesSoftDelete() && $this->forceDeleting
            ? 'forceDelete'
            : 'delete';

        $this->descendants()->{$method}();

        if ($this->hardDeleting()) {
            $height = $rgt - $lft + 1;

            $this->newNestedSetQuery()->makeGap($rgt + 1, -$height);

            // In case if user wants to re-create the node
            $this->makeRoot();

            static::$actionsPerformed++;
        }
    }

    /**
     * Restore the descendants.
     */
    protected function restoreDescendants($deletedAt): void
    {
        $this->descendants()
            ->where($this->getDeletedAtColumn(), '>=', $deletedAt)
            ->restore();
    }

    /**
     * @param  Builder  $query
     * @return QueryBuilder<self>
     */
    public function newEloquentBuilder($query): QueryBuilder
    {
        return new QueryBuilder($query);
    }

    /**
     * Get a new base query that includes deleted nodes.
     *
     * @return QueryBuilder<self>
     */
    public function newNestedSetQuery(?string $table = null): QueryBuilder
    {
        $builder = $this->usesSoftDelete()
            ? $this->withTrashed()
            : $this->newQuery();

        return $this->applyNestedSetScope($builder, $table);
    }

    public function newScopedQuery(?string $table = null): QueryBuilder
    {
        return $this->applyNestedSetScope($this->newQuery(), $table);
    }

    /**
     * @template TQueryBuilder
     *
     * @param TQueryBuilder $query
     */
    public function applyNestedSetScope($query, ?string $table = null): mixed
    {
        if (! $scoped = $this->getScopeAttributes()) {
            return $query;
        }

        $table ??= $this->getTable();

        foreach ($scoped as $attribute) {
            $query->where("$table.$attribute", '=', $this->getAttributeValue($attribute));
        }

        return $query;
    }

    protected function getScopeAttributes(): ?array
    {
        return null;
    }

    public static function scoped(array $attributes): QueryBuilder
    {
        $instance = new static;

        $instance->setRawAttributes($attributes);

        return $instance->newScopedQuery();
    }

    /**
     * @param array $models
     * @return Collection<int, self>
     */
    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }

    /**
     * todo reevaluate this method
     *
     * @param array $attributes
     * @param NodeTrait|null $parent
     * @return mixed
     */
    public static function create(array $attributes = [], ?self $parent = null): mixed
    {
        $children = Arr::pull($attributes, 'children');

        $instance = new static($attributes);

        if ($parent) {
            $instance->appendToNode($parent);
        }

        $instance->save();

        // Now create children
        $relation = new EloquentCollection;

        foreach ((array) $children as $child) {
            $relation->add($child = static::create($child, $instance));

            $child->setRelation('parent', $instance);
        }

        $instance->refreshNode();

        return $instance->setRelation('children', $relation);
    }

    /**
     * Get node height (rgt - lft + 1).
     *
     * @return int
     */
    public function getNodeHeight(): int
    {
        if (! $this->exists) {
            return 2;
        }

        return $this->getRgt() - $this->getLft() + 1;
    }

    /**
     * Get the number of descendant nodes.
     *
     * @return int
     */
    public function getDescendantCount(): int
    {
        return ceil($this->getNodeHeight() / 2) - 1;
    }

    /**
     * Set the value of model's parent id key.
     *
     * Behind the scene node is appended to found parent node.
     *
     *
     * @throws Exception If parent node doesn't exists
     */
    public function setParentIdAttribute(int|string|null $value): void
    {
        if ($this->getParentId() == $value) {
            return;
        }

        if ($value) {
            /** @var self $parent */
            $parent = $this->newScopedQuery()->findOrFail($value);

            $this->appendToNode($parent);
        } else {
            $this->makeRoot();
        }
    }

    public function isRoot(): bool
    {
        return is_null($this->getParentId());
    }

    public function isLeaf(): bool
    {
        return $this->getLft() + 1 == $this->getRgt();
    }

    /**
     * @return array
     */
    protected function getArrayableRelations(): array
    {
        $result = parent::getArrayableRelations();

        // To fix #17 when converting a tree to json falling to infinite recursion.
        unset($result['parent']);

        return $result;
    }

    /**
     * Get whether user is intended to delete the model from a database entirely.
     *
     * @return bool
     */
    protected function hardDeleting(): bool
    {
        return ! $this->usesSoftDelete() || $this->forceDeleting;
    }

    /**
     * @return array<array-key, int>
     */
    public function getBounds(): array
    {
        return [$this->getLft(), $this->getRgt()];
    }

    protected function dirtyBounds(): static
    {
        $this->original[$this->getLftName()] = null;
        $this->original[$this->getRgtName()] = null;

        return $this;
    }

    protected function assertNotDescendant(self $node): static
    {
        if ($node->is($this) || $node->isDescendantOf($this)) {
            throw new LogicException('Node must not be a descendant.');
        }

        return $this;
    }

    protected function assertNodeExists(self $node): static
    {
        if (! $node->getLft() || ! $node->getRgt()) {
            throw new LogicException('Node must exists.');
        }

        return $this;
    }

    protected function assertSameScope(self $node): void
    {
        if (! $scoped = $this->getScopeAttributes()) {
            return;
        }

        foreach ($scoped as $attr) {
            if ($this->getAttribute($attr) != $node->getAttribute($attr)) {
                throw new LogicException('Nodes must be in the same scope');
            }
        }
    }

    protected function isSameScope(self $node): bool
    {
        if (! $scoped = $this->getScopeAttributes()) {
            return true;
        }

        foreach ($scoped as $attr) {
            if ($this->getAttribute($attr) != $node->getAttribute($attr)) {
                return false;
            }
        }

        return true;
    }

    public function hasMoved(): bool
    {
        return $this->moved;
    }

    public function replicate(?array $except = null): Model
    {
        $defaults = [
            $this->getParentIdName(),
            $this->getLftName(),
            $this->getRgtName(),
        ];

        $except = $except ? array_unique(array_merge($except, $defaults)) : $defaults;

        return parent::replicate($except);
    }
}
