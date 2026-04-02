<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocsTest extends TestCase
{
    public function test_api_docs_ui_is_available_in_testing(): void
    {
        $this->get('/docs/api')
            ->assertOk()
            ->assertSee('VaultMage API Docs');
    }

    public function test_openapi_document_is_available_in_testing(): void
    {
        $this->getJson('/docs/api.json')
            ->assertOk()
            ->assertJsonPath('openapi', '3.1.0')
            ->assertJsonPath('info.title', 'VaultMage API Docs');
    }
}
