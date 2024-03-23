<?php

namespace Kalnoy\Nestedset\Eloquent\Concerns\Part;

trait Checker
{
    /**
     * Get whether a node is a descendant of another node.
     */
    public function isDescendantOf(self $other): bool
    {
        return $this->getLft() > $other->getLft()
            && $this->getLft() < $other->getRgt()
            && $this->isSameScope($other);
    }

    /**
     * Get whether a node is itself or a descendant of another node.
     */
    public function isSelfOrDescendantOf(self $other): bool
    {
        return $this->getLft() >= $other->getLft()
            && $this->getLft() < $other->getRgt();
    }

    /**
     * Get whether the node is immediate children of another node.
     */
    public function isChildOf(self $other): bool
    {
        return $this->getParentId() == $other->getKey();
    }

    /**
     * Get whether the node is a sibling of another node.
     */
    public function isSiblingOf(self $other): bool
    {
        return $this->getParentId() == $other->getParentId();
    }

    /**
     * Get whether the node is an ancestor of another node, including immediate parent.
     */
    public function isAncestorOf(self $other): bool
    {
        return $other->isDescendantOf($this);
    }

    /**
     * Get whether the node is itself or an ancestor of other node, including immediate parent.
     */
    public function isSelfOrAncestorOf(self $other): bool
    {
        return $other->isSelfOrDescendantOf($this);
    }
}
