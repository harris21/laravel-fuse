<?php

namespace Harris21\Fuse\Contracts;

use Throwable;

interface FailureClassifier
{
    public function shouldCount(Throwable $e): bool;
}
