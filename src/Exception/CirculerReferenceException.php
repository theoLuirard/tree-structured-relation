<?php

namespace theoLuirard\TreeStructuredRelation\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CircularTreeRelationException extends RuntimeException
{

    // Custom exception for handling circular tree relations
    public function __construct(Model $model, $code = 0, Exception $previous = null)
    {
        $message = "Circular tree relation detected in model: " . get_class($model);
        parent::__construct($message, $code, $previous);
    }
}