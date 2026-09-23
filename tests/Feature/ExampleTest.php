<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_api_returns_json_without_redirecting_an_unauthenticated_client(): void
    {
        $this->get('/api/me')->assertUnauthorized()->assertJsonStructure(['message']);
    }
}
