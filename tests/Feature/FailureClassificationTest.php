<?php

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Harris21\Fuse\CircuitBreaker;
use Harris21\Fuse\Classifiers\DefaultFailureClassifier;
use Harris21\Fuse\Contracts\FailureClassifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

beforeEach(function () {
    Cache::flush();
    config(['fuse.services.test-service' => [
        'threshold' => 50,
        'timeout' => 60,
        'min_requests' => 5,
    ]]);
});

it('does not count 429 TooManyRequestsHttpException as failure', function () {
    $breaker = new CircuitBreaker('test-service');

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure(new TooManyRequestsHttpException);
    }

    expect($breaker->isClosed())->toBeTrue();
    expect($breaker->getStats()['failures'])->toBe(0);
    expect($breaker->getStats()['attempts'])->toBe(5);
});

it('does not count 429 Guzzle ClientException as failure', function () {
    $breaker = new CircuitBreaker('test-service');

    $request = new Request('GET', 'https://api.stripe.com');
    $response = new Response(429);
    $exception = new ClientException('Rate limited', $request, $response);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure($exception);
    }

    expect($breaker->isClosed())->toBeTrue();
    expect($breaker->getStats()['failures'])->toBe(0);
});

it('does not count 401 auth errors as failure', function () {
    $breaker = new CircuitBreaker('test-service');

    $request = new Request('GET', 'https://api.stripe.com');
    $response = new Response(401);
    $exception = new ClientException('Unauthorized', $request, $response);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure($exception);
    }

    expect($breaker->isClosed())->toBeTrue();
    expect($breaker->getStats()['failures'])->toBe(0);
});

it('does not count 403 auth errors as failure', function () {
    $breaker = new CircuitBreaker('test-service');

    $request = new Request('GET', 'https://api.stripe.com');
    $response = new Response(403);
    $exception = new ClientException('Forbidden', $request, $response);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure($exception);
    }

    expect($breaker->isClosed())->toBeTrue();
    expect($breaker->getStats()['failures'])->toBe(0);
});

it('counts 500 server errors as failures', function () {
    $breaker = new CircuitBreaker('test-service');

    $request = new Request('GET', 'https://api.stripe.com');
    $response = new Response(500);
    $exception = new ServerException('Server error', $request, $response);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure($exception);
    }

    expect($breaker->isOpen())->toBeTrue();
    expect($breaker->getStats()['failures'])->toBe(5);
});

it('counts 503 server errors as failures', function () {
    $breaker = new CircuitBreaker('test-service');

    $request = new Request('GET', 'https://api.stripe.com');
    $response = new Response(503);
    $exception = new ServerException('Service unavailable', $request, $response);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure($exception);
    }

    expect($breaker->isOpen())->toBeTrue();
});

it('counts Guzzle ConnectException as failure', function () {
    $breaker = new CircuitBreaker('test-service');

    $request = new Request('GET', 'https://api.stripe.com');
    $exception = new ConnectException('Connection refused', $request);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure($exception);
    }

    expect($breaker->isOpen())->toBeTrue();
});

it('counts Laravel ConnectionException as failure', function () {
    $breaker = new CircuitBreaker('test-service');

    $exception = new ConnectionException('Connection timed out');

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure($exception);
    }

    expect($breaker->isOpen())->toBeTrue();
});

it('counts generic exceptions as failures', function () {
    $breaker = new CircuitBreaker('test-service');

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure(new Exception('Something went wrong'));
    }

    expect($breaker->isOpen())->toBeTrue();
});

it('counts failures without exception parameter', function () {
    $breaker = new CircuitBreaker('test-service');

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isOpen())->toBeTrue();
    expect($breaker->getStats()['failures'])->toBe(5);
});

it('counts 404 not found errors as failures', function () {
    $breaker = new CircuitBreaker('test-service');

    $request = new Request('GET', 'https://api.example.com/missing');
    $response = new Response(404);
    $exception = new ClientException('Not Found', $request, $response);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure($exception);
    }

    expect($breaker->isOpen())->toBeTrue();
    expect($breaker->getStats()['failures'])->toBe(5);
});

it('counts 400 bad request errors as failures', function () {
    $breaker = new CircuitBreaker('test-service');

    $request = new Request('POST', 'https://api.example.com/endpoint');
    $response = new Response(400);
    $exception = new ClientException('Bad Request', $request, $response);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure($exception);
    }

    expect($breaker->isOpen())->toBeTrue();
    expect($breaker->getStats()['failures'])->toBe(5);
});

it('uses custom failure classifier from config', function () {
    // Classifier that counts everything, overriding default exclusions
    $classifierClass = new class implements FailureClassifier
    {
        public function shouldCount(Throwable $e): bool
        {
            return true;
        }
    };

    app()->bind('test-custom-classifier', fn () => $classifierClass);

    config(['fuse.services.test-service' => [
        'threshold' => 50,
        'timeout' => 60,
        'min_requests' => 5,
        'failure_classifier' => 'test-custom-classifier',
    ]]);

    $breaker = new CircuitBreaker('test-service');

    // Use a status code the DEFAULT would exclude — proving the override works
    $excludedCode = DefaultFailureClassifier::EXCLUDED_STATUS_CODES[0];
    $request = new Request('GET', 'https://api.example.com');
    $response = new Response($excludedCode);
    $exception = new ClientException('Rate limited', $request, $response);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure($exception);
    }

    expect($breaker->isOpen())->toBeTrue();
    expect($breaker->getStats()['failures'])->toBe(5);
});

it('supports custom classifier that extends DefaultFailureClassifier', function () {
    $classifierClass = new class extends DefaultFailureClassifier
    {
        public function shouldCount(Throwable $e): bool
        {
            // Also exclude 404 errors, unlike the default
            if ($e instanceof ClientException && $e->getResponse()?->getStatusCode() === 404) {
                return false;
            }

            return parent::shouldCount($e);
        }
    };

    app()->bind('test-extended-classifier', fn () => $classifierClass);

    config(['fuse.services.test-service' => [
        'threshold' => 50,
        'timeout' => 60,
        'min_requests' => 5,
        'failure_classifier' => 'test-extended-classifier',
    ]]);

    $breaker = new CircuitBreaker('test-service');

    // 404 should not count with this custom classifier
    $request = new Request('GET', 'https://api.example.com/missing');
    $response = new Response(404);
    $exception = new ClientException('Not Found', $request, $response);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure($exception);
    }

    expect($breaker->isClosed())->toBeTrue();
    expect($breaker->getStats()['failures'])->toBe(0);

    // 500 should still count
    $breaker->reset();
    $response500 = new Response(500);
    $serverException = new ServerException('Server error', $request, $response500);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure($serverException);
    }

    expect($breaker->isOpen())->toBeTrue();
});

it('falls back to DefaultFailureClassifier when no classifier configured', function () {
    config(['fuse.services.test-service' => [
        'threshold' => 50,
        'timeout' => 60,
        'min_requests' => 5,
    ]]);

    $breaker = new CircuitBreaker('test-service');

    // Default behavior: 429 should not count
    $request = new Request('GET', 'https://api.example.com');
    $response = new Response(429);
    $exception = new ClientException('Rate limited', $request, $response);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure($exception);
    }

    expect($breaker->isClosed())->toBeTrue();
    expect($breaker->getStats()['failures'])->toBe(0);
});

it('throws InvalidArgumentException when classifier does not implement the interface', function () {
    app()->bind('bad-classifier', fn () => new stdClass);

    config(['fuse.services.test-service' => [
        'threshold' => 50,
        'timeout' => 60,
        'min_requests' => 5,
        'failure_classifier' => 'bad-classifier',
    ]]);

    new CircuitBreaker('test-service');
})->throws(InvalidArgumentException::class);
