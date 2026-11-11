<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckAppVersion;
use Tests\TestCase;

class AppVersionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app_version.platforms.android.minimum' => '1.0.0',
            'app_version.platforms.android.latest' => '1.0.1',
            'app_version.block_missing_version' => false,
        ]);
    }

    public function test_equivalent_versions_are_not_blocked(): void
    {
        foreach (['1.0.0', '1.0', '1', 'v1.0.0', '1.0.0+5', '1.0.0 (5)', ' 1.0.0 ', '1.0.0-beta'] as $version) {
            $this->assertFalse(CheckAppVersion::updateRequired($version, 'android'), $version);
            $this->assertFalse(CheckAppVersion::updateRequired($version, 'Android'), $version);
        }
    }

    public function test_older_versions_are_blocked(): void
    {
        config(['app_version.platforms.android.minimum' => '1.0.2']);

        foreach (['1.0.1', '1.0', '0.9.9+20'] as $version) {
            $this->assertTrue(CheckAppVersion::updateRequired($version, 'android'), $version);
        }

        $this->assertFalse(CheckAppVersion::updateRequired('1.0.2+7', 'android'));
        $this->assertFalse(CheckAppVersion::updateRequired('1.1', 'android'));
    }

    public function test_missing_version_follows_config(): void
    {
        $this->assertFalse(CheckAppVersion::updateRequired('', 'android'));
        $this->assertFalse(CheckAppVersion::updateRequired('abc', 'android'));

        config(['app_version.block_missing_version' => true]);
        $this->assertTrue(CheckAppVersion::updateRequired('', 'android'));
    }

    public function test_version_endpoint_accepts_uppercase_platform(): void
    {
        $this->getJson('/api/v1/app/version?platform=Android&version=1.0')
            ->assertOk()
            ->assertJsonPath('platform', 'android')
            ->assertJsonPath('update_required', false)
            ->assertJsonPath('update_available', true);
    }

    public function test_header_check_returns_426_only_for_old_versions(): void
    {
        config(['app_version.platforms.android.minimum' => '1.0.2']);

        $this->getJson('/api/v1/categories', ['X-App-Version' => '1.0.1', 'X-App-Platform' => 'android'])
            ->assertStatus(426);

        $this->getJson('/api/v1/delivery-zones', ['X-App-Version' => '1.0.2+3', 'X-App-Platform' => 'ANDROID'])
            ->assertStatus(200);
    }
}
