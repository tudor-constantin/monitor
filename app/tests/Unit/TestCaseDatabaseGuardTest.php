<?php

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\TestCase;

test('the testing-database guard hooks into refreshApplication, not setUp', function () {
    // RefreshDatabase is processed by setUpTraits(), which parent::setUp()
    // calls only *after* the application already exists via
    // refreshApplication(). A guard added to TestCase::setUp() (even before
    // calling parent::setUp()) cannot check the database at all, because the
    // container isn't bootstrapped yet; a guard added *after* parent::setUp()
    // runs once RefreshDatabase has already dropped every table. The only
    // point where the app exists but traits have not yet run is inside
    // refreshApplication() itself, right after parent::refreshApplication().
    $refreshApplication = new ReflectionMethod(TestCase::class, 'refreshApplication');

    expect($refreshApplication->getDeclaringClass()->getName())->toBe(TestCase::class);

    $file = new SplFileObject($refreshApplication->getFileName());
    $file->seek($refreshApplication->getStartLine() - 1);
    $body = '';

    while ($file->key() < $refreshApplication->getEndLine()) {
        $body .= $file->current();
        $file->next();
    }

    $parentCallPosition = strpos($body, 'parent::refreshApplication()');
    $guardPosition = strpos($body, 'ensureTestingDatabase()');

    expect($parentCallPosition)->not->toBeFalse()
        ->and($guardPosition)->not->toBeFalse()
        ->and($parentCallPosition)->toBeLessThan($guardPosition);

    // setUp() must not reintroduce a late, post-refresh guard: only the base
    // Laravel implementation should be in play there.
    $setUp = new ReflectionMethod(TestCase::class, 'setUp');
    expect($setUp->getDeclaringClass()->getName())->toBe(BaseTestCase::class);
});
