<?php

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->previousSchemaApplication = Facade::getFacadeApplication();
    $this->migrationContainer = new Container();
    $this->migrationDatabase = new Capsule($this->migrationContainer);
    $this->migrationDatabase->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $this->migrationContainer->instance('db', $this->migrationDatabase->getDatabaseManager());
    $this->migrationContainer->instance('db.schema', $this->migrationDatabase->getConnection()->getSchemaBuilder());
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->migrationContainer);
});

afterEach(function () {
    $this->migrationDatabase->getConnection()->disconnect();
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->previousSchemaApplication);
});

it('installs and rolls back marketing settings without optional host tables', function () {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_05_010000_create_marketing_platform_settings.php';
    $migration->up();

    expect(Schema::hasColumns('marketing_platform_settings', ['provider', 'credentials', 'options']))->toBeTrue()
        ->and(Schema::hasTable('teams'))->toBeFalse()
        ->and(Schema::hasTable('social_media_posts'))->toBeFalse();

    $migration->down();

    expect(Schema::hasTable('marketing_platform_settings'))->toBeFalse();
});

it('adds and reverses marketing columns on installed host tables', function () {
    foreach (['teams', 'social_media_posts'] as $name) {
        Schema::create($name, function (Blueprint $table): void {
            $table->id();
        });
        DB::table($name)->insert(['id' => 1]);
    }
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_05_010000_create_marketing_platform_settings.php';
    $migration->up();

    expect(Schema::hasColumn('teams', 'marketing_preferences'))->toBeTrue()
        ->and(Schema::hasColumns('social_media_posts', ['publishing_provider', 'publishing_started_at', 'last_error', 'approved_at', 'approved_by']))->toBeTrue();

    $migration->down();

    expect(Schema::getColumnListing('teams'))->toBe(['id'])
        ->and(Schema::getColumnListing('social_media_posts'))->toBe(['id'])
        ->and(DB::table('teams')->value('id'))->toBe(1)
        ->and(DB::table('social_media_posts')->value('id'))->toBe(1);
});
