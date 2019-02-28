<?php

namespace Simexis\Mongodb\Eloquent;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use Simexis\Mongodb\Helpers\QueriesRelationships;
use MongoDB\Driver\Cursor;
use MongoDB\Model\BSONDocument;

class Builder extends EloquentBuilder
{
    use QueriesRelationships;

    /**
     * The methods that should be returned from query builder.
     *
     * @var array
     */
    protected $passthru = [
        'toSql',
        'insert',
        'insertGetId',
        'pluck',
        'count',
        'min',
        'max',
        'avg',
        'sum',
        'exists',
        'push',
        'pull',
    ];

    /**
     * @inheritdoc
     */
    public function update(array $values, array $options = [])
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        if ($relation = $this->model->getParentRelation()) {
            $relation->performUpdate($this->model, $values);

            return 1;
        }

        return $this->query->update($this->addUpdatedAtColumn($values), $options);
    }

    /**
     * @inheritdoc
     */
    public function insert(array $values)
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        if ($relation = $this->model->getParentRelation()) {
            $relation->performInsert($this->model, $values);

            return true;
        }

        return parent::insert($values);
    }

    /**
     * @inheritdoc
     */
    public function insertGetId(array $values, $sequence = null)
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        if ($relation = $this->model->getParentRelation()) {
            $relation->performInsert($this->model, $values);

            return $this->model->getKey();
        }

        return parent::insertGetId($values, $sequence);
    }

    /**
     * @inheritdoc
     */
    public function delete()
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        if ($relation = $this->model->getParentRelation()) {
            $relation->performDelete($this->model);

            return $this->model->getKey();
        }

        return parent::delete();
    }

    /**
     * @inheritdoc
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        if ($relation = $this->model->getParentRelation()) {
            $value = $this->model->{$column};

            // When doing increment and decrements, Eloquent will automatically
            // sync the original attributes. We need to change the attribute
            // temporary in order to trigger an update query.
            $this->model->{$column} = null;

            $this->model->syncOriginalAttribute($column);

            $result = $this->model->update([$column => $value]);

            return $result;
        }

        return parent::increment($column, $amount, $extra);
    }

    /**
     * @inheritdoc
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        // Intercept operations on embedded models and delegate logic
        // to the parent relation instance.
        if ($relation = $this->model->getParentRelation()) {
            $value = $this->model->{$column};

            // When doing increment and decrements, Eloquent will automatically
            // sync the original attributes. We need to change the attribute
            // temporary in order to trigger an update query.
            $this->model->{$column} = null;

            $this->model->syncOriginalAttribute($column);

            return $this->model->update([$column => $value]);
        }

        return parent::decrement($column, $amount, $extra);
    }

    /**
     * @inheritdoc
     */
    public function chunkById($count, callable $callback, $column = '_id', $alias = null)
    {
        return parent::chunkById($count, $callback, $column, $alias);
    }

    /**
     * @inheritdoc
     */
    public function raw($expression = null)
    {
        // Get raw results from the query builder.
        $results = $this->query->raw($expression);

        // Convert MongoCursor results to a collection of models.
        if ($results instanceof Cursor) {
            $results = iterator_to_array($results, false);

            return $this->model->hydrate($results);
        } // Convert Mongo BSONDocument to a single object.
        elseif ($results instanceof BSONDocument) {
            $results = $results->getArrayCopy();

            return $this->model->newFromBuilder((array) $results);
        } // The result is a single object.
        elseif (is_array($results) && array_key_exists('_id', $results)) {
            return $this->model->newFromBuilder((array) $results);
        }

        return $results;
    }

    /**
     * @return \Illuminate\Database\ConnectionInterface
     */
    public function getConnection()
    {
        return $this->query->getConnection();
    }

    /**
     * @param  $value
     * @return mixed
     */
    public function objectIdToString($value)
    {
        return $value instanceof ObjectId ? (string)$value : $value;
    }

    /**
     * Add subselect queries to count the relations.
     *
     * @param  mixed  $relations
     * @return $this
     */
    public function withCount($relations)
    {
        if (empty($relations)) {
            return $this;
        }

        $relations = is_array($relations) ? $relations : func_get_args();

        foreach ($this->parseWithRelations($relations) as $name => $constraints) {
            // First we will determine if the name has been aliased using an "as" clause on the name
            // and if it has we will extract the actual relationship name and the desired name of
            // the resulting column. This allows multiple counts on the same relationship name.
            $segments = explode(' ', $name);

            unset($alias);

            if (count($segments) === 3 && Str::lower($segments[1]) === 'as') {
                [$name, $alias] = [$segments[0], $segments[2]];
            }

            $relation = $this->getRelationWithoutConstraints($name);

            // Here we will get the relationship count query and prepare to add it to the main query
            // as a sub-select. First, we'll get the "has" query and use that to get the relation
            // count query. We will normalize the relation name then append _count as the name.
            $query = $relation->getRelationExistenceCountQuery(
                $relation->getRelated()->newQuery(), $this
            );

            $query->callScope($constraints);

            $query = $query->mergeConstraintsFrom($relation->getQuery())->toBase();

            // Finally we will add the proper result column alias to the query and run the subselect
            // statement against the query builder. Then we will return the builder instance back
            // to the developer for further constraint chaining that needs to take place on it.
            $column = $alias ?? Str::snake($name.'_count');

            $this->getQuery()->columns['with_count'][$column] = [
                'query' => $query,
                'localKey' => $relation->getQualifiedParentKeyName(),
                'foreignKey' => $relation->getExistenceCompareKey(),
            ];
        }

        return $this;
    }
}
