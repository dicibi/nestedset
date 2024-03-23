<?php

namespace Kalnoy\Nestedset\Eloquent\Concerns\Part;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Kalnoy\Nestedset\Eloquent\Collection;

trait NodeAttribute
{
    public function getParentId(): int|string|null
    {
        return $this->getAttributeValue($this->getParentIdName());
    }

    public function getLft(): ?int
    {
        return $this->getAttributeValue($this->getLftName());
    }

    public function getRgt(): ?int
    {
        return $this->getAttributeValue($this->getRgtName());
    }

    public function setLft(int $value): static
    {
        $this->attributes[$this->getLftName()] = $value;

        return $this;
    }

    public function setRgt(int $value): static
    {
        $this->attributes[$this->getRgtName()] = $value;

        return $this;
    }

    public function setParentId(int|string|null $value): static
    {
        $this->attributes[$this->getParentIdName()] = $value;

        return $this;
    }

    /**
     * Returns node that is next to the current node without constraining to siblings.
     *
     * This can be either a next sibling or a next sibling of the parent node.
     *
     * @param  array<array-key, string>  $columns
     * @return self
     */
    public function getNextNode(array $columns = ['*']): static
    {
        return $this->nextNodes()->defaultOrder()->first($columns);
    }

    /**
     * Returns node before current node without constraining to siblings.
     *
     * This can be either a prev sibling or parent node.
     *
     *
     * @param  array<array-key, string>  $columns
     * @return self
     */
    public function getPrevNode(array $columns = ['*']): static
    {
        return $this->prevNodes()->defaultOrder('desc')->first($columns);
    }

    /**
     * @param  array<array-key, string>  $columns
     */
    public function getAncestors(array $columns = ['*']): EloquentCollection
    {
        return $this->ancestors()->get($columns);
    }

    /**
     * @return Collection<int, self>
     */
    public function getDescendants(array $columns = ['*']): Collection
    {
        // todo create type check for this

        /** @var Collection<int, self> */
        return $this->descendants()->get($columns);
    }

    /**
     * @return Collection<int, self>
     */
    public function getSiblings(array $columns = ['*']): Collection
    {
        // todo create type check for this

        /** @var Collection<int, self> */
        return $this->siblings()->get($columns);
    }

    /**
     * @return Collection<int, self>
     */
    public function getNextSiblings(array $columns = ['*']): Collection
    {
        // todo create type check for this

        /** @var Collection<int, self> */
        return $this->nextSiblings()->get($columns);
    }

    /**
     * @return Collection<int, self>
     */
    public function getPrevSiblings(array $columns = ['*']): Collection
    {
        // todo create type check for this

        /** @var Collection<int, self> */
        return $this->prevSiblings()->get($columns);
    }

    /**
     * @param  array<int, string>  $columns
     * @return self
     */
    public function getNextSibling(array $columns = ['*']): static
    {
        return $this->nextSiblings()->defaultOrder()->first($columns);
    }

    /**
     * @param  array<int, string>  $columns
     * @return self
     */
    public function getPrevSibling(array $columns = ['*']): static
    {
        return $this->prevSiblings()->defaultOrder('desc')->first($columns);
    }
}
