<?php

namespace theoLuirard\TreeStructuredRelation\Traits;

use Error;
use theoLuirard\TreeStructuredRelation\Relations\BelongsToManyTreeRelation;
use theoLuirard\TreeStructuredRelation\Relations\HasManyTreeRelation;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use theoLuirard\getTableName\Traits\GetTableName;
use theoLuirard\TreeStructuredRelation\Exceptions\CircularTreeRelationException;

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

    use GetTableName;
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
    private string $default_parent_column_name = 'parent_id';

    /**
     * The path column name 
     * 
     * @var string
     */
    private string $default_path_column_name = 'path';

    /**
     * The separator used in path 
     * 
     * @var string 
     */
    private string $default_path_separator = '/';

    /**
     * The property name used in explicit path 
     * 
     * @var string 
     */
    private string $default_property_for_explicit_path = 'name';

    /**
     * The explicit_path column name, if this column is not in the table, it will be computed
     */
    private ?string $default_explicit_path_column_name = null;

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
     * Alias of children()
     * @return \App\Models\Relations\HasTreeRelation
     */
    public function descendants()
    {
        return $this->children();
    }

    /**
     * Get all parents relation
     * @return \App\Models\Relations\BelongsToTreeRelation
     */
    public function parents()
    {
        return new BelongsToManyTreeRelation($this);
    }

    /**
     * Alias of parents()
     * @return \App\Models\Relations\BelongsToTreeRelation
     */
    public function ancestors() {
        return $this->parents();
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
        $current_path = $this->getPathValue();
        $current_path_length = strlen($current_path);

        // If parent path is not set, we refresh it
        if (isset($this->parent) && $this->parent->getPathValue() === null) {
            $this->parent->refreshPath();
        }

        // Compute new path
        $new_path = (isset($this->parent) ? $this->parent->getPathValue() : '') . $this->getPathSeparator() . $this->getKey();

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

        // Do we need to update children path ?
        // If the path has been updated, we need to update children path
        $this->children->each(function ($child) use ($new_path, $path_column_name, &$counter) {
            $counter += $child->updatePath();
        });

        return $counter;
    }

    public function updatePathAndExplicitPath()
    {

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
            `$explicit_path_column_name` = concat( :explicit_path , substr( `$path_column_name`, $current_explicit_path_length + 1))
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

        $t = new static;
        $table = $t->getTableName();
        $path_column_name = $t->getPathColumnName();
        $parent_id_column_name = $t->getParentColumnName();
        $path_separator = $t->getPathSeparator();
        $id_column_name = $t->getKeyName();
        $hasExplicitPathColumnName = $t->hasExplicitPathColumnName();
        $explicit_path_column_name = $t->getExplicitPathColumnName();
        $property_for_explicit_path = $t->getPropertyForExplicitPath();


        // remove all path 
        $pathToRemove = [
            $path_column_name => null
        ];
        if ($hasExplicitPathColumnName) {
            $pathToRemove[$explicit_path_column_name] = null;
        }
        DB::table($table)->update($pathToRemove);


        // set path for root item
        $affected_rows = 0;
        $pathRoot = [
            $path_column_name => DB::raw(" CONCAT( '$path_separator' ,`$id_column_name`)")
        ];
        if ($t->hasExplicitPathColumnName()) {
            $pathRoot[$explicit_path_column_name] = DB::raw(" CONCAT( '$path_separator'  , `$property_for_explicit_path`) ");
        }
        $affected_rows += DB::table($table)->whereNull($parent_id_column_name)->update($pathRoot);

        // Compute path for direct child (that do not has path) of parent that has path
        $iterator = 0;
        $limit_until_infinite_loop = 32;

        // Prepare the update path
        $update_path = [
            "enfant." . $path_column_name => DB::raw("concat( parent.`$path_column_name`, '$path_separator' ,enfant.`$id_column_name`) ")
        ];
        if ($hasExplicitPathColumnName) {
            $update_path["enfant." . $explicit_path_column_name] = DB::raw("concat( parent.`$explicit_path_column_name`, '$path_separator' ,enfant.`$property_for_explicit_path`) ");
        }

        // While there is still child that do not have path
        while (DB::table($table)->whereNull($path_column_name)->exists() || $iterator > $limit_until_infinite_loop) {

            // Update path for direct child (that do not has path) of parent that has path
            $affected_rows += DB::table($table . ' as enfant')
                ->leftJoin($table . ' as parent', "parent.$id_column_name", '=', "enfant.$parent_id_column_name")
                ->whereNotNull("parent.$path_column_name")
                ->whereNull("enfant.$path_column_name")
                ->update($update_path);

            $iterator++;
        }

        if ($iterator > $limit_until_infinite_loop + 1) {
            throw new CircularTreeRelationException(new static);
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
        $path_column_name = with(new static)->getPathColumnName();
        $separator =  with(new static)->getPathSeparator();
        $start = 1; // 1 -> because we looking for root node (be aware if seperator is added at the begining of the path we should recalculate the start) and because MySQL is 1 indented
        $alias = $alias ?? with(new static)->getTable();
        $extracting_child_id_sql = "SUBSTRING( $alias.`$path_column_name`, $start , LOCATE('$separator', CONCAT($alias.`$path_column_name`, '$separator'), $start) - $start)";
        return $extracting_child_id_sql;
    }


    /*
    |--------------------------------------------------------------------------
    | Hierchical Methods
    |--------------------------------------------------------------------------
    |
    | Methods used to get and set hierchical informations
    |
    */

    /**
     * Determine if the model is a root node
     * 
     * @return bool
     */
    public function isRoot()
    {
        return $this->{$this->getParentColumnName()} === null;
    }

    /**
     * Determine if the model is a leaf node (has no children)
     * 
     * @return bool
     */
    public function isLeaf()
    {
        return $this->children->isEmpty();
    }


    /**
     * Determine if the model is a child of the current model based on the parent_id
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return bool
     */
    public function isSiblingOf(Model $model)
    {
        return $this->{$this->getParentColumnName()} === $model->{$this->getParentColumnName()};
    }

    /**
     * Determine if the model provided is a parent of the current model based on the path
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return bool
     */
    public function isAncestorOf(Model $model)
    {
        $pathColumnName = $this->getPathColumnName();
        $pathSeparator = $this->getPathSeparator();

        $pattern = '/^(?:' . preg_quote($this->$pathColumnName . $pathSeparator, $pathSeparator) . ')/';

        return preg_match($pattern, $model->$pathColumnName);
    }

    /**
     * Determine if the model provided is the direct parent of the current model
     * 
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return bool
     */
    public function isParentOf(Model $model)
    {
        return $model->{$this->getParentColumnName()} === $this->getKey();
    }

    /**
     * Determine if the model provided is a child of the current model based on the path
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return bool
     */
    public function isDescendantOf(Model $model)
    {
        $pathColumnName = $this->getPathColumnName();
        $pathSeparator = $this->getPathSeparator();

        $pattern = '/^(?:' . preg_quote($model->$pathColumnName . $pathSeparator, $pathSeparator) . ')/';

        return preg_match($pattern, $this->$pathColumnName);
    }

    /**
     * Determine if the model provided is the direct child of the current model
     * 
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return bool
     */
    public function isChildOf(Model $model)
    {
        return $this->{$this->getParentColumnName()} === $model->getKey();
    }

    /**
     * Set the current model as the child of the provided model (or root if nothing provided)
     * 
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function setAsChildOf(?Model $model)
    {
        if (!isset($model)) {
            return $this->setAsRoot();
        }

        if ($this->isAncestorOf($model)) {
            throw new CircularTreeRelationException($this);
        }
        $this->{$this->getParentColumnName()} = $model->getKey();
        $model->save();
        $this->refreshPath();
        return $this;
    }

    /**
     * Set the current model as the parent of the provided model
     * 
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function setAsParentOf(Model $model)
    {
        if ($model->isAncestorOf($this)) {
            throw new CircularTreeRelationException($this);
        }
        $model->{$this->getParentColumnName()} = $this->getKey();
        $model->save();
        $model->refreshPath();
        return $this;
    }

    /**
     * Set the current model as a root node 
     * 
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function setAsRoot()
    {
        $this->{$this->getParentColumnName()} = null;
        $this->refreshPath();
        return $this;
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
        return property_exists($this, 'path_separator') ? $this->path_separator : $this->default_path_separator;
    }

    /**
     * Get path column name
     * 
     * @return string
     */
    public function getPathColumnName()
    {
        return property_exists($this, 'path_column_name') ? $this->path_column_name : $this->default_path_column_name;
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
        return property_exists($this, 'parent_column_name') ? $this->parent_column_name : $this->default_parent_column_name;
    }

    /**
     * Get explicit path column name
     * 
     * @return string
     */
    public function getExplicitPathColumnName(): string
    {
        return property_exists($this, 'explicit_path_column_name') ? $this->explicit_path_column_name : $this->default_explicit_path_column_name;
    }

    /**
     * Determine if the model has an explicit path column name
     * 
     * @return bool
     */
    public function hasExplicitPathColumnName(): bool
    {
        return $this->getExplicitPathColumnName() !== null;
    }

    /**
     * Get explicit path value
     * 
     * @return string
     */
    public function getExplicitPathValue(): string
    {
        return $this->{$this->getExplicitPathColumnName()};
    }

    /**
     * Get the property name used in explicit path 
     * 
     * @return string
     */
    public function getPropertyForExplicitPath(): string
    {
        return property_exists($this, 'property_for_explicit_path') ? $this->property_for_explicit_path : $this->default_property_for_explicit_path;
    }

    /**
     * 
     */
    public static function getDeepestDepth(): int
    {
        $t = with(new static);
        $pathSeparator = $t->getPathSeparator();
        $pathColumnName = $t->getPathColumnName();
        $occurenceSQL = "(LENGTH($pathColumnName) - LENGTH(REPLACE($pathColumnName, '$pathSeparator', ''))) / LENGTH('$pathSeparator')";
        return intval(static::selectRaw($occurenceSQL . ' as occurences')->orderBy('occurences', 'desc')->first()->occurences ?? 0);
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
                    return $this->getExplicitPathValue();
                } else {
                    $explicitPath = "";
                    $property_for_explicit_path = $this->getPropertyForExplicitPath();
                    $this->parents->each(function ($item, $index) use (&$explicitPath, $property_for_explicit_path) {
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
                return substr_count($this->getPathValue(), $this->getPathSeparator()) - 1;
            },
        );
    }
}
