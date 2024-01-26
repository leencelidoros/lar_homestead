<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_route()
    {
        $response = $this->get('/users');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id', 'name','email','password','created_at','updated_at'
                    ],
                ],
            ]);
    }
}
