<?php

declare(strict_types=1);

namespace Liberu\Foundation\Integrations\Tests\Unit;

use App\Services\MailChimpService;
use Liberu\PackageTestbench\PackageTestCase;
use MailchimpMarketing\Api\CampaignsApi;
use MailchimpMarketing\Api\ListsApi;
use MailchimpMarketing\Api\ReportsApi;
use MailchimpMarketing\ApiClient;
use Mockery;
use UnexpectedValueException;

require_once dirname(__DIR__, 2).'/src/Legacy/Services/MailChimpService.php';

final class MailChimpContractsTest extends PackageTestCase
{
    private MailChimpService $service;

    private ApiClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.mailchimp.api_key' => 'fixture-key', 'services.mailchimp.server_prefix' => 'us1']);
        $this->client = Mockery::mock(ApiClient::class);
        $this->client->lists = Mockery::mock(ListsApi::class);
        $this->client->campaigns = Mockery::mock(CampaignsApi::class);
        $this->client->reports = Mockery::mock(ReportsApi::class);
        $this->service = new MailChimpService();
        $this->service->setClient($this->client);
    }

    public function test_creates_a_campaign_using_the_sdk_object_response(): void
    {
        $campaign = (object) ['id' => 'campaign-1', 'status' => 'save'];
        $this->client->campaigns->shouldReceive('create')->once()->with(Mockery::on(function (array $body): bool {
            $this->assertSame('regular', $body['type']);
            $this->assertSame('audience-1', $body['recipients']['list_id']);
            $this->assertSame('Newsletter', $body['settings']['subject_line']);
            $this->assertSame('sender@example.test', $body['settings']['reply_to']);

            return true;
        }))->andReturn($campaign);
        $this->client->campaigns->shouldReceive('setContent')->once()->with('campaign-1', ['html' => '<p>Newsletter</p>']);

        $this->assertSame($campaign, $this->service->createCampaign('audience-1', 'Newsletter', 'Sender', 'sender@example.test', '<p>Newsletter</p>'));
    }

    public function test_preserves_array_responses_from_existing_client_adapters(): void
    {
        $campaign = ['id' => 'adapter-1'];
        $this->client->campaigns->shouldReceive('create')->once()->andReturn($campaign);
        $this->client->campaigns->shouldReceive('setContent')->once()->with('adapter-1', ['html' => '<p>Content</p>']);

        $this->assertSame($campaign, $this->service->createCampaign('audience-1', 'Subject', 'Sender', 'sender@example.test', '<p>Content</p>'));
    }

    public function test_rejects_missing_campaign_identifiers_before_uploading_content(): void
    {
        $this->client->campaigns->shouldReceive('create')->once()->andReturn((object) ['status' => 'save']);
        $this->client->campaigns->shouldNotReceive('setContent');

        $this->expectException(UnexpectedValueException::class);
        $this->service->createCampaign('audience-1', 'Subject', 'Sender', 'sender@example.test', '<p>Content</p>');
    }

    public function test_creates_an_audience_and_adds_members_with_the_requested_subscription_state(): void
    {
        $audience = (object) ['id' => 'audience-1'];
        $this->client->lists->shouldReceive('createList')->once()->with(Mockery::on(function (array $body): bool {
            $this->assertSame('Customers', $body['name']);
            $this->assertSame('Company', $body['contact']['company']);
            $this->assertSame('Requested updates', $body['permission_reminder']);
            $this->assertSame('Sender', $body['campaign_defaults']['from_name']);
            $this->assertSame('sender@example.test', $body['campaign_defaults']['from_email']);

            return true;
        }))->andReturn($audience);
        $this->assertSame($audience, $this->service->createList('Customers', 'Company', 'Requested updates', 'Sender', 'sender@example.test'));
        $member = (object) ['id' => 'member-1'];
        $this->client->lists->shouldReceive('addListMember')->once()->with('audience-1', [
            'email_address' => 'member@example.test', 'status' => 'pending', 'merge_fields' => ['FNAME' => 'Ada'],
        ])->andReturn($member);
        $this->assertSame($member, $this->service->addMember('audience-1', 'member@example.test', 'pending', ['FNAME' => 'Ada']));
    }

    public function test_lists_audiences_and_campaigns_from_real_sdk_response_shapes(): void
    {
        $this->client->lists->shouldReceive('getAllLists')->once()->andReturn((object) ['lists' => [(object) ['id' => 'list-1', 'name' => 'Customers']]]);
        $this->assertSame(['list-1' => 'Customers'], $this->service->getLists());
        $this->client->campaigns->shouldReceive('list')->once()->andReturn((object) ['campaigns' => [(object) [
            'id' => 'campaign-1', 'web_id' => 7, 'settings' => (object) ['title' => 'Internal title', 'subject_line' => 'Newsletter'], 'status' => 'save', 'type' => 'regular',
        ]]]);
        $this->assertSame([['id' => 'campaign-1', 'web_id' => 7, 'name' => 'Internal title', 'subject_line' => 'Newsletter', 'status' => 'save', 'type' => 'regular']], $this->service->getCampaigns());
    }

    public function test_reports_engagement_and_sends_only_the_requested_campaign(): void
    {
        $this->client->reports->shouldReceive('getCampaignReport')->once()->with('campaign-1')->andReturn((object) [
            'emails_sent' => 100, 'opens' => (object) ['unique_opens' => 20, 'open_rate' => 0.2],
            'clicks' => (object) ['clicks_total' => 5, 'click_rate' => 0.05], 'unsubscribed' => 1,
            'bounces' => (object) ['hard_bounces' => 2, 'soft_bounces' => 3],
        ]);
        $this->assertSame(['campaign_id' => 'campaign-1', 'emails_sent' => 100, 'unique_opens' => 20, 'open_rate' => 0.2, 'clicks' => 5, 'click_rate' => 0.05, 'unsubscribes' => 1, 'bounce_rate' => 5], $this->service->getCampaignReport('campaign-1'));
        $this->client->campaigns->shouldReceive('send')->once()->with('campaign-1')->andReturn((object) ['accepted' => true]);
        $this->assertTrue($this->service->sendCampaign('campaign-1')->accepted);
        $this->assertTrue($this->service->trackEmailOpen('campaign-1', 'member-1'));
        $this->assertTrue($this->service->trackEmailClick('campaign-1', 'member-1', 'https://example.test/link'));
    }

    public function test_unconfigured_integrations_do_not_call_the_sdk(): void
    {
        config(['services.mailchimp.api_key' => '']);
        $this->assertFalse($this->service->isConfigured());
        $this->assertSame([], $this->service->getLists());
        $this->assertSame([], $this->service->getCampaigns());
        $this->assertSame([], $this->service->getCampaignReport('campaign-1'));
        $this->assertSame([], $this->service->getABTestResults('campaign-1'));
        $this->assertNull($this->service->createList('List', 'Company', 'Permission', 'Sender', 'sender@example.test'));
        $this->assertNull($this->service->addMember('list-1', 'member@example.test'));
        $this->assertNull($this->service->createCampaign('list-1', 'Subject', 'Sender', 'sender@example.test', '<p>Content</p>'));
        $this->assertNull($this->service->createABTestCampaign('list-1', 'A', 'B', 'Sender', 'sender@example.test', 'A', 'B'));
        $this->assertNull($this->service->sendCampaign('campaign-1'));
        config(['services.mailchimp.api_key' => 'fixture-key', 'services.mailchimp.server_prefix' => '']);
        $this->assertFalse($this->service->isConfigured());
    }
}
