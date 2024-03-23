<?php

namespace Kalnoy\Nestedset;

use Illuminate\Database\Schema\Blueprint;
use Kalnoy\Nestedset\Eloquent\Concerns\NodeTrait;

class NestedSet
{
    /**
     * The name of default lft column.
     */
    const string LFT = '_lft';

    /**
     * The name of default rgt column.
     */
    const string RGT = '_rgt';

    /**
     * The name of default parent id column.
     */
    const string PARENT_ID = 'parent_id';

    /**
     * Insert direction.
     */
    const int BEFORE = 1;

    /**
     * Insert direction.
     */
    const int AFTER = 2;

    /**
     * Add default nested set columns to the table. Also create an index.
     */
    public static function columns(Blueprint $table): void
    {
        $table->unsignedBigInteger(self::LFT)->default(0);
        $table->unsignedBigInteger(self::RGT)->default(0);
        $table->unsignedBigInteger(self::PARENT_ID)->nullable();

        $table->index(static::getDefaultColumns());
    }

    /**
     * Drop NestedSet columns.
     */
    public static function dropColumns(Blueprint $table): void
    {
        $columns = static::getDefaultColumns();

        $table->dropIndex($columns);
        $table->dropColumn($columns);
    }

    /**
     * Get a list of default columns.
     *
     * @return array<array-key, string>
     */
    public static function getDefaultColumns(): array
    {
        return [static::LFT, static::RGT, static::PARENT_ID];
    }

    /**
     * Replaces instanceof calls for this trait.
     */
    public static function hasNodeTrait(mixed $node): bool
    {
        return is_object($node) && in_array(NodeTrait::class, (array) $node);
    }
}
