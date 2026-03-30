<?php

namespace Tests\Feature;

use App\Models\User;
use App\Http\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnsureEmailIsVerifiedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', EnsureEmailIsVerified::class])->get('/_test_verified', function () {
            return 'OK';
        });
    }

    public function test_it_allows_verified_users()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/_test_verified');

        $response->assertStatus(200);
        $this->assertEquals('OK', $response->getContent());
    }

    public function test_it_blocks_unverified_users()
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->getJson('/_test_verified');

        $response->assertStatus(409);
        $response->assertJson(['message' => 'Your email address is not verified.']);
    }

    public function test_it_blocks_guests()
    {
        $response = $this->getJson('/_test_verified');

        $response->assertStatus(409);
        $response->assertJson(['message' => 'Your email address is not verified.']);
    }
}
