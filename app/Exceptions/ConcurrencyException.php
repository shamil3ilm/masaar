<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class ConcurrencyException extends RuntimeException
{
    protected Model $model;

    public function __construct(string $message, Model $model)
    {
        parent::__construct($message);
        $this->model = $model;
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    public function getCurrentVersion(): ?int
    {
        return $this->model->version ?? null;
    }
}
