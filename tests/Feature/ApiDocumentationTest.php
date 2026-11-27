<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class ApiDocumentationTest extends TestCase
{
    public function test_swagger_ui_page_is_available_and_uses_same_origin_cookie_bootstrap(): void
    {
        $this->get('/docs/api')
            ->assertOk()
            ->assertSee('swagger-ui-dist@5.36.0/swagger-ui.css')
            ->assertSee('swagger-ui-dist@5.36.0/swagger-ui-bundle.js')
            ->assertSee('/api/v1/auth/csrf-cookie')
            ->assertSee('requestInterceptor');
    }

    public function test_openapi_spec_is_served_and_documents_every_registered_auth_route(): void
    {
        $spec = File::get(base_path('docs/api/openapi.yaml'));

        $response = $this->get('/docs/api/openapi.yaml');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/yaml; charset=utf-8')
            ->assertHeader('Content-Disposition', 'inline; filename="openapi.yaml"');

        $this->assertStringContainsString('openapi: 3.1.0', $spec);

        foreach (app('router')->getRoutes() as $route) {
            if (
                ! str_starts_with($route->uri(), 'api/v1/auth/')
                && ! str_starts_with($route->uri(), 'api/v1/admin/')
            ) {
                continue;
            }

            $openApiPath = '/'.$route->uri().':';

            $this->assertStringContainsString(
                $openApiPath,
                $spec,
                "The OpenAPI document is missing {$route->uri()}.",
            );
        }
    }
}
