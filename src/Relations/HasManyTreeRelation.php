<?php

namespace theoLuirard\TreeStructuredRelation\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

class HasManyTreeRelation extends Relation
{

    /**
     * The parent model instance. It does mean the parent in the tree and the parent in the relationship. (quite nice no ? be careful on BelongsToTreeRelation it's not the case)
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $parent;

    /**
     * Create a new belongs to relationship instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $parent /!\ The $parent means in this relation the parent element of the relation but also the parent in the tree 
     * @return void
     */
    public function __construct(Model $parent)
    {

        // Create the query
        $query = get_class($parent)::query();
        $this->query = $query;

        // Set parent & related
        $this->parent = $parent;
        $this->related = $query->getModel();

        $this->addConstraints();
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    public function addConstraints()
    {

        $pathValue = $this->parent->getPathValue();

        if ($pathValue) {
            $pathColumnName = $this->parent->getPathColumnName();
            $pathSeparator = $this->parent->getPathSeparator();
            $this->query->where($pathColumnName, 'LIKE', $pathValue . $pathSeparator . '%');
        }
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param  array  $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {

        $pathColumnName = $this->parent->getPathColumnName();
        $pathSeparator = $this->parent->getPathSeparator();

        /* Map path values and keep only the longest (from the same parents), we try to reduce the number of where clause */
        $longestsPaths = $this->mapLongestPaths($models);

        // Create where clauses 
        $this->query->where(function (Builder $query) use ($longestsPaths, $pathColumnName, $pathSeparator) {
            foreach ($longestsPaths as $index => $path) {
                if ($index == 0) {
                    $query->whereRaw(" ? LIKE CONCAT(`$pathColumnName`,'$pathSeparator%') ");
                } else {
                    $query->orWhereRaw(" ? LIKE CONCAT(`$pathColumnName`,'$pathSeparator%') ");
                }

                $query->getQuery()->addBinding($path);
            }
        });
    }

    /**
     * Map the longest paths of an array of model.
     *
     * @param  array  $models
     * @return array
     */
    public function mapLongestPaths(array $models)
    {

        $pathColumnName = $this->parent->getPathColumnName();

        $longestsPaths = [];
        collect($this->getKeys($models, $pathColumnName))->sortDesc()->each(function ($path) use (&$longestsPaths) {

            // isChild -> false until $path is an child of a stored path 
            $isChild = false;

            $pattern = "/^(?:" . preg_quote($path, "/") . ")/";
            foreach ($longestsPaths as $longestsPath) {
                if (preg_match($pattern, $longestsPath) === 1) {
                    $isChild = true;
                };
            }

            // If not matching any stored path, we add it to the stored path
            if (!$isChild) {
                array_push($longestsPaths, $path);
            }
        });
        return $longestsPaths;
    }

    /**
     * Initialize the relation on a set of models.
     *
     * @param  array   $models
     * @param  string  $relation
     * @return array
     */
    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  array   $models
     * @param  \Illuminate\Database\Eloquent\Collection  $results
     * @param  string  $relation
     * @return array
     */
    public function match(array $models, Collection $results, $relation)
    {

        // If not results, we can directly return our children wihtout adding parent
        if ($results->isEmpty()) {
            return $models;
        }

        // We should say for every $models (repesenting a child) if a result is a parent or not
        foreach ($models as $key => $model) {
            $model->setRelation(
                $relation,
                $results->filter(function (Model $result) use ($model) {
                    return $result->isAncestorOf($model);
                })->sortBy($this->parent->getPathColumnName())->values()
            );
        }

        return $models;
    }

    /**
     * Add the constraints for a relationship query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Builder  $parentQuery
     * @param  array|mixed  $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {

        if ($query->getQuery()->from == $parentQuery->getQuery()->from) {
            return $this->getRelationExistenceQueryForSelfRelation($query, $parentQuery, $columns);
        }

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }

    /**
     * Add the constraints for a relationship query on the same table.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Builder  $parentQuery
     * @param  array|mixed  $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationExistenceQueryForSelfRelation(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        $query->from($query->getModel()->getTable().' as '.$hash = $this->getRelationCountHash());

        $query->getModel()->setTable($hash);

        $pathColumnName = $this->parent->getPathColumnName();
        $pathSeparator = $this->parent->getPathSeparator();
        return $query->select($columns)->whereColumn(
            $hash.'.'.$pathColumnName, 'LIKE', DB::raw('CONCAT('.$this->getQualifiedParentPathColumnName() . ',"' . $pathSeparator . '%")')
        );
    }

    /**
     * Get the fully qualified parent key name.
     *
     * @return string
     */
    public function getQualifiedParentPathColumnName()
    {
        $pathColumnName = $this->parent->getPathColumnName();
        return $this->parent->qualifyColumn($pathColumnName);
    }

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    public function getResults()
    {
        return $this->get();
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function get($columns = ['*'])
    {
        // First we'll add the proper select columns onto the query so it is run with
        // the proper columns. Then, we will get the results and hydrate out pivot
        // models with the result of those columns as a separate model relation.
        $columns = $this->query->getQuery()->columns ? [] : $columns;

        if ($columns == ['*']) {
            $columns = [$this->related->getTable() . '.*'];
        }

        $builder = $this->query->applyScopes();

        $models = $builder->addSelect($columns)->getModels();

        // If we actually found models we will also eager load any relationships that
        // have been specified as needing to be eager loaded. This will solve the
        // n + 1 query problem for the developer and also increase performance.
        if (count($models) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $this->related->newCollection($models);
    }


}
