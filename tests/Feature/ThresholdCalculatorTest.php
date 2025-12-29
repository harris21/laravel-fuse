<?php

use Carbon\Carbon;
use Harris21\Fuse\ThresholdCalculator;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow(null);
});

afterEach(function () {
    Carbon::setTestNow(null);
});

it('returns default threshold when no service config exists', function () {
    config(['fuse.default_threshold' => 50]);
    config(['fuse.services' => []]);

    expect(ThresholdCalculator::for('unknown-service'))->toBe(50);
});

it('returns regular threshold during off-peak hours', function () {
    Carbon::setTestNow(Carbon::createFromTime(22, 0, 0)); // 10 PM - off-peak

    config(['fuse.services.stripe' => [
        'threshold' => 40,
        'peak_hours_threshold' => 70,
        'peak_hours_start' => 9,
        'peak_hours_end' => 17,
    ]]);

    expect(ThresholdCalculator::for('stripe'))->toBe(40);
});

it('returns peak hours threshold during peak hours', function () {
    Carbon::setTestNow(Carbon::createFromTime(12, 0, 0)); // 12 PM - peak

    config(['fuse.services.stripe' => [
        'threshold' => 40,
        'peak_hours_threshold' => 70,
        'peak_hours_start' => 9,
        'peak_hours_end' => 17,
    ]]);

    expect(ThresholdCalculator::for('stripe'))->toBe(70);
});

it('returns peak hours threshold at boundary start', function () {
    Carbon::setTestNow(Carbon::createFromTime(9, 0, 0)); // 9 AM - start of peak

    config(['fuse.services.stripe' => [
        'threshold' => 40,
        'peak_hours_threshold' => 70,
        'peak_hours_start' => 9,
        'peak_hours_end' => 17,
    ]]);

    expect(ThresholdCalculator::for('stripe'))->toBe(70);
});

it('returns peak hours threshold at boundary end', function () {
    Carbon::setTestNow(Carbon::createFromTime(17, 0, 0)); // 5 PM - end of peak

    config(['fuse.services.stripe' => [
        'threshold' => 40,
        'peak_hours_threshold' => 70,
        'peak_hours_start' => 9,
        'peak_hours_end' => 17,
    ]]);

    expect(ThresholdCalculator::for('stripe'))->toBe(70);
});

it('returns regular threshold just after peak hours end', function () {
    Carbon::setTestNow(Carbon::createFromTime(18, 0, 0)); // 6 PM - after peak

    config(['fuse.services.stripe' => [
        'threshold' => 40,
        'peak_hours_threshold' => 70,
        'peak_hours_start' => 9,
        'peak_hours_end' => 17,
    ]]);

    expect(ThresholdCalculator::for('stripe'))->toBe(40);
});

it('falls back to threshold when peak_hours_threshold not set during peak', function () {
    Carbon::setTestNow(Carbon::createFromTime(12, 0, 0)); // 12 PM - peak

    config(['fuse.services.stripe' => [
        'threshold' => 45,
        'peak_hours_start' => 9,
        'peak_hours_end' => 17,
    ]]);

    expect(ThresholdCalculator::for('stripe'))->toBe(45);
});

it('uses default peak hours when not configured', function () {
    Carbon::setTestNow(Carbon::createFromTime(10, 0, 0)); // 10 AM - within default 9-17

    config(['fuse.services.stripe' => [
        'threshold' => 40,
        'peak_hours_threshold' => 70,
    ]]);

    expect(ThresholdCalculator::for('stripe'))->toBe(70);
});

it('uses default threshold when only peak_hours_threshold configured', function () {
    Carbon::setTestNow(Carbon::createFromTime(22, 0, 0)); // 10 PM - off-peak

    config(['fuse.services.stripe' => [
        'peak_hours_threshold' => 70,
        'peak_hours_start' => 9,
        'peak_hours_end' => 17,
    ]]);

    expect(ThresholdCalculator::for('stripe'))->toBe(50); // default threshold
});

it('getConfig returns all config values with calculated threshold', function () {
    Carbon::setTestNow(Carbon::createFromTime(12, 0, 0)); // Peak hours

    config(['fuse.services.stripe' => [
        'threshold' => 40,
        'peak_hours_threshold' => 70,
        'timeout' => 120,
        'min_requests' => 15,
        'peak_hours_start' => 9,
        'peak_hours_end' => 17,
    ]]);

    $config = ThresholdCalculator::getConfig('stripe');

    expect($config['threshold'])->toBe(70); // peak hours threshold
    expect($config['timeout'])->toBe(120);
    expect($config['min_requests'])->toBe(15);
    expect($config['is_peak_hours'])->toBeTrue();
});

it('getConfig returns defaults for unconfigured service', function () {
    config(['fuse.default_threshold' => 50]);
    config(['fuse.default_timeout' => 60]);
    config(['fuse.default_min_requests' => 10]);

    $config = ThresholdCalculator::getConfig('unknown-service');

    expect($config['threshold'])->toBe(50);
    expect($config['timeout'])->toBe(60);
    expect($config['min_requests'])->toBe(10);
});

it('getConfig correctly identifies off-peak hours', function () {
    Carbon::setTestNow(Carbon::createFromTime(22, 0, 0)); // 10 PM

    config(['fuse.services.stripe' => [
        'threshold' => 40,
        'peak_hours_start' => 9,
        'peak_hours_end' => 17,
    ]]);

    $config = ThresholdCalculator::getConfig('stripe');

    expect($config['is_peak_hours'])->toBeFalse();
    expect($config['threshold'])->toBe(40);
});

it('handles midnight correctly', function () {
    Carbon::setTestNow(Carbon::createFromTime(0, 0, 0)); // Midnight

    config(['fuse.services.stripe' => [
        'threshold' => 40,
        'peak_hours_threshold' => 70,
        'peak_hours_start' => 9,
        'peak_hours_end' => 17,
    ]]);

    expect(ThresholdCalculator::for('stripe'))->toBe(40); // Off-peak
});

it('handles early morning correctly', function () {
    Carbon::setTestNow(Carbon::createFromTime(6, 0, 0)); // 6 AM

    config(['fuse.services.stripe' => [
        'threshold' => 40,
        'peak_hours_threshold' => 70,
        'peak_hours_start' => 9,
        'peak_hours_end' => 17,
    ]]);

    expect(ThresholdCalculator::for('stripe'))->toBe(40); // Off-peak
});
