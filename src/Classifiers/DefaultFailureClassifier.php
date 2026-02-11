<?php

namespace Harris21\Fuse\Classifiers;

use GuzzleHttp\Exception\ClientException;
use Harris21\Fuse\Contracts\FailureClassifier;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class DefaultFailureClassifier implements FailureClassifier
{
    /** @var int[] */
    public const EXCLUDED_STATUS_CODES = [429, 401, 403];

    public function shouldCount(Throwable $e): bool
    {
        if ($e instanceof TooManyRequestsHttpException) {
            return false;
        }

        if ($e instanceof ClientException) {
            return match (true) {
                in_array($e->getResponse()?->getStatusCode(), self::EXCLUDED_STATUS_CODES, true) => false,
                default => true,
            };
        }

        return true;
    }
}
