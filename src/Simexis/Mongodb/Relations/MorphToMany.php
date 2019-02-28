<?php
/**
 * Created by PhpStorm.
 * User: joro
 * Date: 28.2.2019 г.
 * Time: 15:14 ч.
 */

namespace Simexis\Mongodb\Relations;

use Illuminate\Database\Eloquent\Relations\MorphToMany AS IlluminateMorphToMany;

class MorphToMany extends IlluminateMorphToMany
{

    /**
     * Add the constraints for a relationship count query.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Illuminate\Database\Eloquent\Builder  $parentQuery
     * @param  array|mixed  $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        if ($parentQuery->getQuery()->from == $query->getQuery()->from) {
            return $this->getRelationExistenceQueryForSelfJoin($query, $parentQuery, $columns);
        }

        $this->performJoin($query);

        return $query->select($columns)->whereColumn(
            $this->getQualifiedParentKeyName(), '=', $this->getExistenceCompareKey()
        );
    }

    /**
     * Set the where clause for the relation query.
     *
     * @return $this
     */
    protected function addWhereConstraints()
    {
        $this->query->where(
            $this->getQualifiedForeignPivotKeyName(), '=', $this->parent->{$this->parentKey}
        );

        return $this;
    }

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param  array  $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        $whereIn = $this->whereInMethod($this->parent, $this->parentKey);

        $this->query->{$whereIn}(
            $this->getQualifiedForeignPivotKeyName(),
            $this->getKeys($models, $this->parentKey)
        );
    }

}