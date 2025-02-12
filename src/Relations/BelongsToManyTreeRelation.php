<?php

namespace theoLuirard\TreeStructuredRelation\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

class BelongsToManyTreeRelation extends Relation
{

    /**
     * The parent model instance. It does not mean the parent in the tree but the parent in the relationship. The one who came from the relation, it could be confusing.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $parent;

    /**
     * Create a new belongs to tree relationship instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $parent /!\ $parent here does not mean the parent in the tree but the parent in the relation 
     * @return void
     */
    public function __construct(Model $parent)
    {
        $query = get_class($parent)::query();

        $this->query = $query;
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
            $this->query
                ->whereRaw(" ? LIKE CONCAT($pathColumnName,'$pathSeparator%') ")
                ->setBindings([$pathValue]);
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

        // Map path values and keep only the shortests (from the same parents), 
        // we try to reduce the number of where clause 
        $shortestPaths = $this->mapShorthenedPath($models);

        // Create where clauses 
        $this->query->where(function (Builder $query) use ($shortestPaths, $pathColumnName, $pathSeparator) {
            foreach ($shortestPaths as $index => $path) {
                if ($index == 0) {
                    $query->whereRaw(" `$pathColumnName` LIKE CONCAT( ? ,'$pathSeparator%') ");
                } else {
                    $query->orWhereRaw(" `$pathColumnName` LIKE CONCAT( ? ,'$pathSeparator%') ");
                }

                $query->getQuery()->addBinding($path);
            }
        });
    }

    /**
     * Map the shortened paths of an array of model.
     *
     * @param  array  $models
     * @return array
     */
    public function mapShorthenedPath(array $models)
    {
        $pathColumnName = $this->parent->getPathColumnName();
        $pathSeparator = $this->parent->getPathSeparator();

        $shortenedPaths = [];
        collect($this->getKeys($models, $pathColumnName))->sort()->each(function ($path) use (&$shortenedPaths, $pathSeparator) {

            // isChild -> false until $path is an child of a stored path 
            $isChild = false;

            // Check if the current path is a child of a stored path
            foreach ($shortenedPaths as $shortestPath) {
                $pattern = "/^(?:" . preg_quote($shortestPath, $pathSeparator) . ")/";
                if (preg_match($pattern, $path) === 1) {
                    $isChild = true;
                };
            }

            // If not matching any stored path, we add it to the stored path
            if (!$isChild) {
                array_push($shortenedPaths, $path);
            }
        });

        return $shortenedPaths;
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
     * Match the eagerly loaded results to their parents (in the relationship convention). In tree idea, it's the parents which are linked to their children 
     *
     * @param  array   $models
     * @param  \Illuminate\Database\Eloquent\Collection  $results
     * @param  string  $relation
     * @return array
     */
    public function match(array $models, Collection $results, $relation)
    {
        if ($results->isEmpty()) {
            return $models;
        }
        
        foreach ($models as $model) {
            $model->setRelation(
                $relation,
                $results->filter(function (Model $result) use ($model) {
                    return $result->isChildOf($model);
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
        return $this->getRelationExistenceQueryForSelfRelation($query, $parentQuery, $columns);
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
        $query->from($query->getModel()->getTable() . ' as ' . $hash = $this->getRelationCountHash());

        $query->getModel()->setTable($hash);

        $pathColumnName = $this->parent->getPathColumnName();
        $pathSeparator = $this->parent->getPathSeparator();
        return $query->select($columns)->whereColumn(
            $this->getQualifiedParentPathColumnName(),
            'LIKE',
            DB::raw("CONCAT(`$hash`" . '.`' . $pathColumnName . '`,"' . $pathSeparator . '%")')
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

        return $this->related->newCollection($models)->sortBy($this->parent->getPathColumnName())->values();
    }
}
