<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource;
use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages\CreateOAuthConfiguration;
use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages\EditOAuthConfiguration;
use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages\ListOAuthConfigurations;
use Liberu\Foundation\Integrations\Filament\App\Resources\WebhookDeliveryResource;
use Liberu\Foundation\Integrations\Filament\App\Resources\WebhookDeliveryResource\Pages\ListWebhookDeliveries;
use Liberu\Foundation\Integrations\Models\OAuthConfiguration;
use Liberu\Foundation\Integrations\Models\WebhookDelivery;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\Foundation\RolesPermissions\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once dirname(__DIR__).'/Stubs.php';

it('covers OAuthConfigurationResource authorization, schema, table, and pages', function (): void {
    $regular = User::factory()->create();
    $superAdmin = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $superAdmin->id]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);
    $role = Role::firstOrCreate([
        'name' => 'super_admin',
        'guard_name' => 'web',
        'team_id' => $team->id,
    ]);
    $superAdmin->assignRole($role);

    $record = new OAuthConfiguration();

    // Denied for regular user
    $this->actingAs($regular);
    expect(OAuthConfigurationResource::canViewAny())->toBeFalse()
        ->and(OAuthConfigurationResource::getViewAnyAuthorizationResponse()->allowed())->toBeFalse()
        ->and(OAuthConfigurationResource::canCreate())->toBeFalse()
        ->and(OAuthConfigurationResource::getCreateAuthorizationResponse()->allowed())->toBeFalse()
        ->and(OAuthConfigurationResource::canEdit($record))->toBeFalse()
        ->and(OAuthConfigurationResource::getEditAuthorizationResponse($record)->allowed())->toBeFalse()
        ->and(OAuthConfigurationResource::canView($record))->toBeFalse()
        ->and(OAuthConfigurationResource::getViewAuthorizationResponse($record)->allowed())->toBeFalse()
        ->and(OAuthConfigurationResource::canDelete($record))->toBeFalse()
        ->and(OAuthConfigurationResource::getDeleteAuthorizationResponse($record)->allowed())->toBeFalse()
        ->and(OAuthConfigurationResource::canDeleteAny())->toBeFalse()
        ->and(OAuthConfigurationResource::getDeleteAnyAuthorizationResponse()->allowed())->toBeFalse();

    // Allowed for super_admin
    $this->actingAs($superAdmin);
    expect(OAuthConfigurationResource::canViewAny())->toBeTrue()
        ->and(OAuthConfigurationResource::getViewAnyAuthorizationResponse()->allowed())->toBeTrue()
        ->and(OAuthConfigurationResource::canCreate())->toBeTrue()
        ->and(OAuthConfigurationResource::getCreateAuthorizationResponse()->allowed())->toBeTrue()
        ->and(OAuthConfigurationResource::canEdit($record))->toBeTrue()
        ->and(OAuthConfigurationResource::getEditAuthorizationResponse($record)->allowed())->toBeTrue()
        ->and(OAuthConfigurationResource::canView($record))->toBeTrue()
        ->and(OAuthConfigurationResource::getViewAuthorizationResponse($record)->allowed())->toBeTrue()
        ->and(OAuthConfigurationResource::canDelete($record))->toBeTrue()
        ->and(OAuthConfigurationResource::getDeleteAuthorizationResponse($record)->allowed())->toBeTrue()
        ->and(OAuthConfigurationResource::canDeleteAny())->toBeTrue()
        ->and(OAuthConfigurationResource::getDeleteAnyAuthorizationResponse()->allowed())->toBeTrue()
        ->and(OAuthConfigurationResource::permissionResource())->toBe('oauth_configuration')
        ->and(OAuthConfigurationResource::getRelations())->toBe([])
        ->and(OAuthConfigurationResource::getPages())->toHaveKeys(['index', 'create', 'edit']);

    // Form schema & client_secret closures
    $editPage = new EditOAuthConfiguration();
    $schema = OAuthConfigurationResource::form(Schema::make($editPage));
    $components = $schema->getComponents();
    expect($components)->not->toBeEmpty();

    foreach ($components as $component) {
        if (method_exists($component, 'getName') && $component->getName() === 'client_secret') {
            $reqClosure = (new ReflectionProperty($component, 'isRequired'))->getValue($component);
            expect($reqClosure('create'))->toBeTrue()
                ->and($reqClosure('edit'))->toBeFalse();

            $dehydClosure = (new ReflectionProperty($component, 'isDehydrated'))->getValue($component);
            expect($dehydClosure('new-secret'))->toBeTrue()
                ->and($dehydClosure(''))->toBeFalse();
        }
    }

    // Table
    $livewire = Mockery::mock(HasTable::class);
    $table = OAuthConfigurationResource::table(Table::make($livewire));
    expect($table->getColumns())->not->toBeEmpty();

    // Resource Pages
    $createPage = new CreateOAuthConfiguration();
    expect($createPage::getResource())->toBe(OAuthConfigurationResource::class);

    $mutate = new ReflectionMethod($editPage, 'mutateFormDataBeforeFill');
    $mutated = $mutate->invoke($editPage, ['client_secret' => 'secret']);
    expect($mutated['client_secret'])->toBe('');

    $editHeader = new ReflectionMethod($editPage, 'getHeaderActions');
    expect($editHeader->invoke($editPage))->toHaveCount(1);

    $listPage = new ListOAuthConfigurations();
    $listHeader = new ReflectionMethod($listPage, 'getHeaderActions');
    expect($listHeader->invoke($listPage))->toHaveCount(1);
});

it('covers WebhookDeliveryResource authorization, query scoping, table, and pages', function (): void {
    // Guest cannot access
    expect(WebhookDeliveryResource::canAccess())->toBeFalse();

    $user = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();
    $this->actingAs($user);

    $record = new WebhookDelivery();

    expect(WebhookDeliveryResource::canAccess())->toBeFalse()
        ->and(WebhookDeliveryResource::canCreate())->toBeFalse()
        ->and(WebhookDeliveryResource::getCreateAuthorizationResponse()->allowed())->toBeFalse()
        ->and(WebhookDeliveryResource::canEdit($record))->toBeFalse()
        ->and(WebhookDeliveryResource::getEditAuthorizationResponse($record)->allowed())->toBeFalse()
        ->and(WebhookDeliveryResource::canDelete($record))->toBeFalse()
        ->and(WebhookDeliveryResource::getDeleteAuthorizationResponse($record)->allowed())->toBeFalse();

    // Admin can access
    app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);
    $adminRole = Role::firstOrCreate([
        'name' => 'admin',
        'guard_name' => 'web',
        'team_id' => $team->id,
    ]);
    $user->assignRole($adminRole);
    expect(WebhookDeliveryResource::canAccess())->toBeTrue();

    // Query scoping, table, and pages
    $query = WebhookDeliveryResource::getEloquentQuery();
    expect($query->toSql())->toContain('team_id');

    $livewire = Mockery::mock(HasTable::class);
    $table = WebhookDeliveryResource::table(Table::make($livewire));
    expect($table->getColumns())->not->toBeEmpty()
        ->and(WebhookDeliveryResource::getPages())->toHaveKey('index')
        ->and(ListWebhookDeliveries::getResource())->toBe(WebhookDeliveryResource::class);
});
