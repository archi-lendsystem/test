<?php

namespace Tests\Feature;

use Tests\TestCase;

class UserManagementApiTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_root_route_is_not_available(): void
    {
        $response = $this->get('/');

        $response->assertNotFound();
    }
}
