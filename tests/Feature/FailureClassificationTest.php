<?php

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Harris21\Fuse\CircuitBreaker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

beforeEach(function () {
    Cache::flush();
});

it('does not count 429 TooManyRequestsHttpException as failure', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 5);

    // Record 5 rate limit exceptions
    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure(new TooManyRequestsHttpException());
    }

    // Circuit should still be closed - rate limits don't count
    expect($breaker->isClosed())->toBeTrue();
    expect($breaker->getStats()['failures'])->toBe(0);
    expect($breaker->getStats()['attempts'])->toBe(5);
});

it('does not count 429 Guzzle ClientException as failure', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 5);

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
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 5);

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
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 5);

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
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 5);

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
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 5);

    $request = new Request('GET', 'https://api.stripe.com');
    $response = new Response(503);
    $exception = new ServerException('Service unavailable', $request, $response);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure($exception);
    }

    expect($breaker->isOpen())->toBeTrue();
});

it('counts Guzzle ConnectException as failure', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 5);

    $request = new Request('GET', 'https://api.stripe.com');
    $exception = new ConnectException('Connection refused', $request);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure($exception);
    }

    expect($breaker->isOpen())->toBeTrue();
});

it('counts Laravel ConnectionException as failure', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 5);

    $exception = new ConnectionException('Connection timed out');

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure($exception);
    }

    expect($breaker->isOpen())->toBeTrue();
});

it('counts generic exceptions as failures', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 5);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure(new Exception('Something went wrong'));
    }

    expect($breaker->isOpen())->toBeTrue();
});

it('counts failures without exception parameter', function () {
    $breaker = new CircuitBreaker('test-service', failureThreshold: 50, minRequests: 5);

    for ($i = 0; $i < 5; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->isOpen())->toBeTrue();
    expect($breaker->getStats()['failures'])->toBe(5);
});
