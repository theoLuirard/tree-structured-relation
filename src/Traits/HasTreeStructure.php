<?php

namespace App\Models\Traits;

use App\Models\Relations\BelongsToManyTreeRelation;
use App\Models\Relations\HasManyTreeRelation;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
|   hasTreeStructure
|--------------------------------------------------------------------------
|
| Define trait to use tree structure 
| Define children relation
| Define parent & parents relation
| Define function to compute path 
|
*/


trait HasTreeStructure
{


    /*
    |--------------------------------------------------------------------------
    | Constants
    |--------------------------------------------------------------------------
    |
    | Defines constants for the tree structure
    |
    */

    /**
     * The parent column name 
     * 
     * @var string
     */
    public static string $parent_column_name = 'parent_id';

    /**
     * The path column name 
     * 
     * @var string
     */
    public static string $path_column_name = 'path';

    /**
     * The separator used in path 
     * 
     * @var string 
     */
    public static string $path_separator = '/';

    /**
     * The property name used in explicit path 
     * 
     * @var string 
     */
    public static string $property_for_explicit_path = 'name';

    /**
     * The explicit_path column name, if this column is not in the table, it will be computed
     */
    public static ?string $explicit_path_column_name = null;

    /**
     * The table alias name used in relation
     */
    public string $table_alias_name;



    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    |
    | Defines relations for Eloquent ORM can reach each linked records 
    |
    */

    /**
     * Get direct children 
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function direct_children()
    {
        return $this->hasMany(static::class, $this->getParentColumnName());
    }

    /**
     * Get parent 
     * 
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(static::class, $this->getParentColumnName());
    }

    /**
     * Get siblings 
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function siblings()
    {
        return $this->hasMany(static::class, $this->getParentColumnName(), $this->getParentColumnName());
    }

    /**
     * Get all children relation
     * @return \App\Models\Relations\HasTreeRelation
     */
    public function children()
    {
        return new HasManyTreeRelation($this);
    }

    /**
     * Get all parents relation
     * @return \App\Models\Relations\BelongsToTreeRelation
     */
    public function parents()
    {
        return new BelongsToManyTreeRelation($this);
    }


    /*
    |--------------------------------------------------------------------------
    | Methods for updating path 
    |--------------------------------------------------------------------------
    |
    | Methods use to update and refresh path 
    |
    */


    /**
     * Compute the path using the parent path and update himself path and their children path
     * 
     * @return int 
     */
    public function refreshPath()
    {
        if ($this->hasExplicitPathColumnName()) {
            $this->updatePathAndExplicitPath();
        } else {
            $this->updatePath();
        }
    }

    /**
     * Update the path using the parent path and update himself path and their children path
     * 
     * @return int
     */
    public function updatePath()
    {

        // Getting current path
        $current_path = $this->path;
        $current_path_length = strlen($current_path);

        // If parent path is not set, we refresh it
        if (isset($this->parent) && $this->parent->path === null) {
            $this->parent->refreshPath();
        }

        // Compute new path
        $new_path = (isset($this->parent) ? $this->parent->path : '') . $this->getPathSeparator() . $this->getKey();

        // getting table and column names
        $path_column_name = $this->getPathColumnName();
        $table = $this->getTableName();

        // If path is the same, we do nothing
        if ($current_path === $new_path) {
            return 0;
        }

        if ($current_path === null) {
            // Update path
            $keyName = $this->getKeyName();
            $counter = DB::update(
                "update `$table`
                set `$path_column_name` = :new_path
                where `$path_column_name` IS NULL AND `$keyName` = :primary_key_value ;",
                [
                    'primary_key_value' => $this->getKey(),
                    'new_path' => $new_path
                ]
            );
        } else {
            // Update path
            $counter = DB::update(
                "update `$table`
            set `$path_column_name` = concat( :new_path , substr( `$path_column_name`, $current_path_length + 1))
            where `$path_column_name` = :current_path OR `$path_column_name` LIKE :child_path ;",
                [
                    'new_path' => $new_path,
                    'current_path' => $current_path,
                    'child_path' => $current_path . $this->getPathSeparator() . '%'
                ]
            );
        }

        $this->children->each(function ($child) use ($new_path, $path_column_name, &$counter) {
            $counter += $child->updatePath();
        });

        return $counter;
    }

    public function updatePathAndExplicitPath()
    {
        // TO DO
        throw new Error("Not implemented yet");

        // Getting current path & explicit path
        $current_path = $this->path;
        $current_path_length = strlen($current_path);
        $current_explicit_path = $this->explicit_path;
        $current_explicit_path_length = strlen($this->explicit_path);

        // If parent path is not set, we refresh it
        if (isset($this->parent) && ($this->parent->path === null || $this->parent->explicit_path === null)) {
            $this->parent->refreshPath();
        }

        // Compute new path & explicit path
        $new_path = (isset($this->parent) ? $this->parent->path : '') . $this->getPathSeparator() . $this->name;
        $new_explicit_path = (isset($this->parent) ? $this->parent->explicit_path : '') . $this->getPathSeparator() . $this->name;

        // getting table and column names
        $path_column_name = $this->getPathColumnName();
        $explicit_path_column_name = $this->getExplicitPathColumnName();
        $table = $this->getTableName();

        if ($current_path === $new_path  && $current_explicit_path === $new_explicit_path) {
            return 0;
        }

        return DB::update(
            "update `$table`
            set `$path_column_name` = concat( :new_path , substr( `$path_column_name`, $current_path_length + 1)),
            `$explicit_path_column_name` = concat( :explicit_path , substr( `$path_column_name`, $current_explicit_path_lengt + 1))
            where `$path_column_name` = :current_path OR `$path_column_name` LIKE :child_path",
            [
                'new_path' => $new_path,
                'current_path' => $current_path,
                'child_path' => $current_path . $this->getPathSeparator() . '%'
            ]
        );
    }


    /**
     * Compute all path for the table
     * 
     * @return int affected rows;
     */
    public static function computeAllPath()
    {
        $table = self::getTableName();
        $path_column_name = self::$path_column_name;
        $parent_column_name = self::$parent_column_name;
        $path_separator = self::$path_separator;
        $id_column_name = self::keyName();

        // remove all path 
        DB::table($table)->update([$path_column_name => null]);


        // set path for root item
        $affected_rows = 0;
        $affected_rows += DB::table($table)->whereNull($parent_column_name)->update(
            [$path_column_name => DB::raw(" `$id_column_name` ")]
        );

        // Compute path for direct child (that do not has path) of parent that has path
        $iterator = 0;
        $limit_until_infinite_loop = 32;
        while (DB::table($table)->whereNull($path_column_name)->exists() || $iterator > $limit_until_infinite_loop) {
            $affected_rows += DB::table($table . ' as enfant')
                ->leftJoin($table . ' as parent', "parent.$id_column_name", '=', "enfant.$parent_column_name")
                ->whereNotNull("parent.$path_column_name")
                ->whereNull("enfant.$path_column_name")
                ->update(["enfant.$path_column_name" => DB::raw("concat( parent.`$path_column_name`, '$path_separator' ,enfant.`$id_column_name`)")]);

            $iterator++;
        }

        if ($iterator > $limit_until_infinite_loop + 1) {
            $message = "An infinite loop has been detected in a tree structured Model";
            Log::critical($message, [
                "CLASS" => self::class,
                "LINE" => __LINE__,
                "FILE" => __FILE__
            ]);
            throw new Error($message);
        }

        return $affected_rows;
    }

    /*
    |--------------------------------------------------------------------------
    | Methods for read path 
    |--------------------------------------------------------------------------
    |
    | Methods use to read path 
    |
    */

    /**
     * Determine the sql function to extract direct children id from path of any children 
     * This is used for grouping sql request by direct children
     * @param null|string $alias the alias used for the table if there is one 
     * @return string
     */
    public function getExtractingDirectChildIdFromAnyChildrenPathSql(string $alias = null): string
    {
        $path_column_name = $this->getPathColumnName();
        $path = $this->$path_column_name;
        $separator = $this->getPathSeparator();
        $start = strlen($path) + strlen($separator) + 1; // + 1 because MySQL is 1 indented
        $alias = $alias ?? with(new static)->getTable();
        $extracting_child_id_sql = "SUBSTRING( $alias.`$path_column_name`, $start , LOCATE('$separator', CONCAT($alias.`$path_column_name`, '$separator'), $start) - $start)";
        return $extracting_child_id_sql;
    }

    /**
     * Determine the sql function to extract root node id from path of any children 
     * This is used for grouping sql request by root node
     * @param null|string $alias the alias used for the table if there is one 
     * @return string
     */
    public static function extractingRootIdFromAnyChildrenPathSql(string $alias = null): string
    {
        $path_column_name = self::$path_column_name;
        $separator = self::$path_separator;
        $start = 1; // 1 -> because we looking for root node (be aware if seperator is added at the begining of the path we should recalculate the start) and because MySQL is 1 indented
        $alias = $alias ?? with(new static)->getTable();
        $extracting_child_id_sql = "SUBSTRING( $alias.`$path_column_name`, $start , LOCATE('$separator', CONCAT($alias.`$path_column_name`, '$separator'), $start) - $start)";
        return $extracting_child_id_sql;
    }


    /*
    |--------------------------------------------------------------------------
    | Accessors & CRUD methods
    |--------------------------------------------------------------------------
    |
    | An accessor transforms an Eloquent attribute value when it is accessed. 
    | To define an accessor, create a protected method on your model to represent the accessible attribute.
    | This method name should correspond to the "camel case" representation of the true underlying model attribute / database column when applicable.
    |
    */

    /**
     * Get separator 
     * 
     * @return string
     */
    public function getPathSeparator()
    {
        return self::$path_separator;
    }

    /**
     * Get path column name
     * 
     * @return string
     */
    public function getPathColumnName()
    {
        return self::$path_column_name;
    }

    /**
     * Get path value
     * 
     * @return string
     */
    public function getPathValue()
    {
        return $this->{$this->getPathColumnName()};
    }

    /**
     * Get parent column name
     * 
     * @return string
     */
    public function getParentColumnName()
    {
        return self::$parent_column_name;
    }

    /**
     * Get explicit path column name
     * 
     * @return string
     */
    public function getExplicitPathColumnName()
    {
        return self::$explicit_path_column_name;
    }

    public function hasExplicitPathColumnName()
    {
        return $this->getExplicitPathColumnName() !== null;
    }

    /**
     * Get the property name used in explicit path 
     * 
     * @return string
     */
    public function getPropertyForExplicitPath()
    {
        return self::$property_for_explicit_path;
    }


    /*
    |--------------------------------------------------------------------------
    | Attributes
    |--------------------------------------------------------------------------
    |
    | Define some specific attributes for a tree structured model 
    |
    */

    /**
     * Determine the explicite path, with name and not the id 
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function explicitPath(): Attribute
    {
        return new Attribute(
            get: function () {
                if ($this->hasExplicitPathColumnName()) {
                    return $this->{$this->getExplicitPathColumnName()};
                } else {
                    $explicitPath = "";
                    $property_for_explicit_path = $this->getPropertyForExplicitPath();
                    $that = $this;
                    Log::info(
                        "hummm" .
                            $this->parents->reduce(
                                function ($acc, $item, $index) use ($property_for_explicit_path) {
                                    Log::info("pparent  " . $index . " is " . $item->$property_for_explicit_path . "");
                                    $acc .= "parent  " . $index . " is " . $item->$property_for_explicit_path;
                                },
                                " "
                            )
                    );
                    $this->parents->each(function ($item, $index) use (&$explicitPath, $property_for_explicit_path, $that) {

                        Log::info("parent " . $index . " for " . $that->property_for_explicit_path . "is " . $item->$property_for_explicit_path);
                        $explicitPath .= ($index > 0 ? $this->getPathSeparator() : "") . $item->$property_for_explicit_path;
                    });
                    $explicitPath .= ($explicitPath !== "" ? $this->getPathSeparator() : '') . $this->$property_for_explicit_path;
                    return $explicitPath;
                }
            },
        );
    }

    /**
     * Determine the depth of the node (0 indexed)
     *
     * @return \Illuminate\Database\Eloquent\Casts\Attribute
     */
    protected function depth(): Attribute
    {
        return new Attribute(
            get: function () {
                return substr_count($this->path, $this->getPathSeparator());
            },
        );
    }
}
