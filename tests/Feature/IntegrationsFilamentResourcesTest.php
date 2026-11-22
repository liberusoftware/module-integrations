<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Tests\Feature;

use App\Models\User;
use Filament\Schemas\Schema;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource;
use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages\EditOAuthConfiguration;
use Liberu\Foundation\Integrations\Filament\App\Resources\OAuthConfigurationResource\Pages\ListOAuthConfigurations;
use Liberu\Foundation\Integrations\Filament\App\Resources\WebhookDeliveryResource;
use Liberu\Foundation\Integrations\Models\OAuthConfiguration;
use Liberu\Foundation\Integrations\Models\WebhookDelivery;
use Liberu\Foundation\Organizations\Models\Team;
use Mockery;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

require_once dirname(__DIR__).'/Stubs.php';

class IntegrationsFilamentResourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_configuration_resource_authorization_schema_table_and_pages(): void
    {
        $regular = User::factory()->create();
        $superAdmin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin->assignRole($role);

        $record = new OAuthConfiguration();

        // Denied for regular user
        $this->actingAs($regular);
        $this->assertFalse(OAuthConfigurationResource::canViewAny());
        $this->assertFalse(OAuthConfigurationResource::getViewAnyAuthorizationResponse()->allowed());
        $this->assertFalse(OAuthConfigurationResource::canCreate());
        $this->assertFalse(OAuthConfigurationResource::getCreateAuthorizationResponse()->allowed());
        $this->assertFalse(OAuthConfigurationResource::canEdit($record));
        $this->assertFalse(OAuthConfigurationResource::getEditAuthorizationResponse($record)->allowed());
        $this->assertFalse(OAuthConfigurationResource::canView($record));
        $this->assertFalse(OAuthConfigurationResource::getViewAuthorizationResponse($record)->allowed());
        $this->assertFalse(OAuthConfigurationResource::canDelete($record));
        $this->assertFalse(OAuthConfigurationResource::getDeleteAuthorizationResponse($record)->allowed());
        $this->assertFalse(OAuthConfigurationResource::canDeleteAny());
        $this->assertFalse(OAuthConfigurationResource::getDeleteAnyAuthorizationResponse()->allowed());

        // Allowed for super_admin
        $this->actingAs($superAdmin);
        $this->assertTrue(OAuthConfigurationResource::canViewAny());
        $this->assertTrue(OAuthConfigurationResource::getViewAnyAuthorizationResponse()->allowed());
        $this->assertTrue(OAuthConfigurationResource::canCreate());
        $this->assertTrue(OAuthConfigurationResource::getCreateAuthorizationResponse()->allowed());
        $this->assertTrue(OAuthConfigurationResource::canEdit($record));
        $this->assertTrue(OAuthConfigurationResource::getEditAuthorizationResponse($record)->allowed());
        $this->assertTrue(OAuthConfigurationResource::canView($record));
        $this->assertTrue(OAuthConfigurationResource::getViewAuthorizationResponse($record)->allowed());
        $this->assertTrue(OAuthConfigurationResource::canDelete($record));
        $this->assertTrue(OAuthConfigurationResource::getDeleteAuthorizationResponse($record)->allowed());
        $this->assertTrue(OAuthConfigurationResource::canDeleteAny());
        $this->assertTrue(OAuthConfigurationResource::getDeleteAnyAuthorizationResponse()->allowed());

        $this->assertSame('oauth_configuration', OAuthConfigurationResource::permissionResource());
        $this->assertSame([], OAuthConfigurationResource::getRelations());
        $this->assertArrayHasKey('index', OAuthConfigurationResource::getPages());

        // Form schema & closures
        $editPage = new EditOAuthConfiguration();
        $schema = OAuthConfigurationResource::form(Schema::make($editPage));
        $components = $schema->getComponents();
        $this->assertNotEmpty($components);
        foreach ($components as $component) {
            if (method_exists($component, 'getName') && $component->getName() === 'client_secret') {
                $reqProp = new \ReflectionProperty($component, 'isRequired');
                $reqClosure = $reqProp->getValue($component);
                $this->assertTrue($reqClosure('create'));
                $this->assertFalse($reqClosure('edit'));

                $dehydProp = new \ReflectionProperty($component, 'isDehydrated');
                $dehydClosure = $dehydProp->getValue($component);
                $this->assertTrue($dehydClosure('new-secret'));
                $this->assertFalse($dehydClosure(''));
            }
        }

        // Table
        $livewire = Mockery::mock(HasTable::class);
        $table = OAuthConfigurationResource::table(Table::make($livewire));
        $this->assertNotEmpty($table->getColumns());

        // Page methods
        $mutate = new ReflectionMethod($editPage, 'mutateFormDataBeforeFill');
        $mutated = $mutate->invoke($editPage, ['client_secret' => 'secret']);
        $this->assertSame('', $mutated['client_secret']);

        $editHeader = new ReflectionMethod($editPage, 'getHeaderActions');
        $this->assertCount(1, $editHeader->invoke($editPage));

        $listPage = new ListOAuthConfigurations();
        $listHeader = new ReflectionMethod($listPage, 'getHeaderActions');
        $this->assertCount(1, $listHeader->invoke($listPage));
    }

    public function test_webhook_delivery_resource_authorization_query_table_and_pages(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $record = new WebhookDelivery();

        $this->assertFalse(WebhookDeliveryResource::canAccess());
        $this->assertFalse(WebhookDeliveryResource::canCreate());
        $this->assertFalse(WebhookDeliveryResource::getCreateAuthorizationResponse()->allowed());
        $this->assertFalse(WebhookDeliveryResource::canEdit($record));
        $this->assertFalse(WebhookDeliveryResource::getEditAuthorizationResponse($record)->allowed());
        $this->assertFalse(WebhookDeliveryResource::canDelete($record));
        $this->assertFalse(WebhookDeliveryResource::getDeleteAuthorizationResponse($record)->allowed());

        $query = WebhookDeliveryResource::getEloquentQuery();
        $this->assertStringContainsString('team_id', $query->toSql());

        $livewire = Mockery::mock(HasTable::class);
        $table = WebhookDeliveryResource::table(Table::make($livewire));
        $this->assertNotEmpty($table->getColumns());
        $this->assertArrayHasKey('index', WebhookDeliveryResource::getPages());
    }
}
