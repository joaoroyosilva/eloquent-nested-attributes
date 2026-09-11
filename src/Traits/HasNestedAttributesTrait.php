<?php

namespace Eloquent\NestedAttributes\Traits;

use Exception;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Relations;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Throwable;

trait HasNestedAttributesTrait
{
    /**
     * Defined nested attributes.
     *
     * @var array
     */
    protected $acceptNestedAttributesFor = [];

    /**
     * Defined "destroy" key name.
     *
     * @var string
     */
    protected $destroyNestedKey = '_destroy';

    /**
     * Get accept nested attributes.
     *
     * @return array
     */
    public function getAcceptNestedAttributesFor(): array
    {
        return $this->acceptNestedAttributesFor;
    }

    /**
     * Fill the model with an array of attributes.
     *
     * @param  array  $attributes
     * @return $this
     *
     * @throws \Illuminate\Database\Eloquent\MassAssignmentException
     */
    public function fill(array $attributes): self
    {
        if (! empty($this->nested)) {
            $this->acceptNestedAttributesFor = [];

            foreach ($this->nested as $attr) {
                if (isset($attributes[$attr])) {
                    $this->acceptNestedAttributesFor[$attr] = $attributes[$attr];
                    unset($attributes[$attr]);
                }
            }
        }

        return parent::fill($attributes);
    }

    /**
     * Save the model to the database.
     *
     * The model and its nested relations are saved inside one transaction, and the method always
     * hands control back at the transaction level it was called at: on success, when a save
     * answers `false`, and when anything throws. Leaving a level open would turn the caller's
     * `commit()` into a mere savepoint release (nothing really committed) and make the caller's
     * `rollBack()` undo this orphan level instead of its own work.
     *
     * @param  array  $options
     * @return bool
     */
    public function save(array $options = []): bool
    {
        $connection = $this->getConnection();
        $entryLevel = $connection->transactionLevel();

        $connection->beginTransaction();

        try {
            if (! parent::save($options)) {
                $this->rollbackNestedSaveTo($connection, $entryLevel);

                return false;
            }

            foreach ($this->getAcceptNestedAttributesFor() as $attribute => $stack) {
                $methodName = lcfirst(implode(array_map('ucfirst', explode('_', $attribute))));

                if (! method_exists($this, $methodName)) {
                    throw new Exception('The nested atribute relation "' . $methodName . '" does not exists.');
                }

                $relation = $this->$methodName();

                if ($relation instanceof HasOne || $relation instanceof MorphOne) {
                    if (! $this->saveNestedAttributes($relation, $stack)) {
                        $this->rollbackNestedSaveTo($connection, $entryLevel);

                        return false;
                    }
                } elseif ($relation instanceof HasMany || $relation instanceof MorphMany) {
                    foreach ($stack as $params) {
                        if (! $this->saveManyNestedAttributes($this->$methodName(), $params)) {
                            $this->rollbackNestedSaveTo($connection, $entryLevel);

                            return false;
                        }
                    }
                } else {
                    throw new Exception('The nested atribute relation is not supported for "' . $methodName . '".');
                }
            }

            $connection->commit();
        } catch (Throwable $e) {
            $this->rollbackNestedSaveTo($connection, $entryLevel);

            throw $e;
        }

        return true;
    }

    /**
     * Roll the save back to the level it was called at — never just "one level".
     *
     * `rollBack($level)` is a no-op when the target is out of range, so this is safe when someone
     * below already rolled further back.
     *
     * If the rollback itself fails (a savepoint destroyed by an implicit commit, or a deadlock that
     * ended the transaction on the server), the failure is reported and swallowed: the exception
     * that caused the rollback is the one the caller must see. And when the server no longer has a
     * real transaction, the connection's level counter is realigned to zero — `rollBack(0)` issues
     * no SQL in that case — so the next rollback up the stack does not hit the missing savepoint.
     */
    protected function rollbackNestedSaveTo(Connection $connection, int $level): void
    {
        try {
            $this->emitNestedSaveRollback($connection, $level);
        } catch (Throwable $rollbackFailure) {
            $this->reportNestedSaveRollbackFailure($rollbackFailure);

            if (! $this->nestedSaveHasRealTransaction($connection)) {
                try {
                    $this->emitNestedSaveRollback($connection, 0);
                } catch (Throwable $realignFailure) {
                    $this->reportNestedSaveRollbackFailure($realignFailure);
                }
            }
        }
    }

    /**
     * Issue the rollback. Kept apart from `rollbackNestedSaveTo()` so tests can inject a failing
     * rollback without destroying a real savepoint.
     */
    protected function emitNestedSaveRollback(Connection $connection, int $level): void
    {
        $connection->rollBack($level);
    }

    /**
     * Whether the server still holds a real transaction on this connection. Test seam.
     */
    protected function nestedSaveHasRealTransaction(Connection $connection): bool
    {
        return $connection->getPdo()->inTransaction();
    }

    /**
     * Report through the application's handler when there is one; the library itself does not
     * depend on illuminate/foundation.
     */
    private function reportNestedSaveRollbackFailure(Throwable $failure): void
    {
        if (function_exists('report')) {
            report($failure);
        }
    }

    /**
     * Save the hasOne nested relation attributes to the database.
     *
     * @param  Illuminate\Database\Eloquent\Relations  $relation
     * @param  array                                   $params
     * @return bool
     */
    protected function saveNestedAttributes(Relations $relation, array $params): bool
    {
        if ($this->exists && $model = $relation->first()) {
            if ($this->allowDestroyNestedAttributes($params)) {
                return $model->delete();
            }

            return $model->update($stack);
        } elseif ($relation->create($stack)) {
            return true;
        }

        return false;
    }

    /**
     * Save the hasMany nested relation attributes to the database.
     *
     * @param  Illuminate\Database\Eloquent\Relations  $relation
     * @param  array                                   $params
     * @return bool
     */
    protected function saveManyNestedAttributes($relation, array $params): bool
    {
        if (isset($params['id']) && $this->exists) {
            $model = $relation->findOrFail($params['id']);

            if ($this->allowDestroyNestedAttributes($params)) {
                return $model->delete();
            }

            return $model->update($params);
        } elseif ($relation->create($params)) {
            return true;
        }

        return false;
    }

    /**
     * Check can we delete nested data.
     *
     * @param  array $params
     * @return bool
     */
    protected function allowDestroyNestedAttributes(array $params): bool
    {
        return isset($params[$this->destroyNestedKey]) && (bool) $params[$this->destroyNestedKey] == true;
    }
}
