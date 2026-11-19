<?php

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\TestCase;

class SwaggerDocumentationTest extends TestCase
{
    public function test_swagger_ui_renders_relative_urls_for_proxy_compatibility(): void
    {
        foreach (['/api/documentation', '/api/v1/documentation'] as $endpoint) {
            $response = $this->get($endpoint);

            $response->assertOk();
            $response->assertSee('docs/asset/swagger-ui.css', false);
            $response->assertSee('docs/asset/swagger-ui-bundle.js', false);
            $response->assertSee('docs?api-docs.json', false);
            $response->assertDontSee('/api/docs/asset/', false);
            $response->assertDontSee('url: "/api/docs', false);
        }

        // Simula requisição com barra final preservada pelo Nginx/PHP-FPM
        $kernel = $this->app->make(Kernel::class);
        $rawRequest = Request::create('/api/v1/documentation/', 'GET');
        $rawResponse = $kernel->handle($rawRequest);
        $kernel->terminate($rawRequest, $rawResponse);

        $this->assertSame(200, $rawResponse->getStatusCode());
        $html = (string) $rawResponse->getContent();
        $this->assertStringContainsString('../docs/asset/swagger-ui.css', $html);
        $this->assertStringContainsString('../docs?api-docs.json', $html);
    }

    public function test_swagger_openapi_json_and_assets_are_accessible_in_both_prefixes(): void
    {
        foreach (['/api/docs', '/api/v1/docs'] as $docsEndpoint) {
            $this->getJson($docsEndpoint)
                ->assertOk()
                ->assertJsonPath('openapi', '3.0.0');
        }

        foreach (['/api/docs/asset/swagger-ui.css', '/api/v1/docs/asset/swagger-ui.css'] as $assetEndpoint) {
            $response = $this->get($assetEndpoint);
            $response->assertOk();
            $this->assertStringContainsString('text/css', (string) $response->headers->get('Content-Type'));
        }
    }
}
