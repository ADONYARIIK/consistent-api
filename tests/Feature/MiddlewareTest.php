<?php

declare(strict_types=1);

namespace Tests\Feature;

use Adonyarik\ConsistentApi\Middleware\ApiJsonMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $echoAccept = fn () => ['accept' => request()->header('Accept'), 'json' => request()->expectsJson()];

        $router->get('api/accept', $echoAccept)->middleware('consistent.api-json');
        $router->get('web/accept', $echoAccept)->middleware('consistent.api-json');

        $router->match(['get', 'post', 'put', 'patch', 'delete'], 'json-only', fn () => 'ok')->middleware('consistent.ensure-json');
        $router->match(['get', 'post', 'put'], 'upload', fn () => 'ok')->middleware('consistent.ensure-multipart');
    }

    public function test_api_json_asks_for_json_under_the_api_prefix_only(): void
    {
        $this->get('/api/accept')->assertOk()->assertExactJson(['accept' => 'application/json', 'json' => true]);
        $this->get('/web/accept')->assertOk()->assertJsonPath('json', false);
    }

    public function test_api_json_keeps_a_specific_type_the_client_asks_for(): void
    {
        foreach (['text/csv', 'application/pdf', 'application/vnd.api+json', 'application/json'] as $type) {
            $this->get('/api/accept', ['Accept' => $type])->assertOk()->assertJsonPath('accept', $type);
        }

        // Browsers put HTML first, curl sends */*: both get JSON errors
        foreach (['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', '*/*', ''] as $type) {
            $this->get('/api/accept', ['Accept' => $type])->assertOk()->assertJsonPath('accept', 'application/json');
        }
    }

    public function test_api_json_runs_globally_so_unknown_api_urls_get_json_errors(): void
    {
        $this->get('/api/missing')->assertNotFound()->assertHeader('Content-Type', 'application/json');
        $this->post('/api/accept')->assertMethodNotAllowed()->assertHeader('Content-Type', 'application/json');

        $this->assertStringNotContainsString('json', (string) $this->get('/web/missing')->assertNotFound()->headers->get('Content-Type'));
    }

    public function test_api_json_follows_the_configured_prefix(): void
    {
        config(['consistentapi.api_url_prefix' => 'web']);

        $this->get('/web/accept')->assertOk()->assertJsonPath('json', true);
        $this->get('/api/accept')->assertOk()->assertJsonPath('json', false);
    }

    /**
     * @return array<string, array{string, list<string>, list<string>}> Prefix, paths asking for JSON, paths that do not
     */
    public static function prefixes(): array
    {
        return [
            'plain' => ['api', ['/api/v1/accept', '/api'], ['/web/v1/accept', '/apis/v1/accept']],
            'with slashes' => ['/api/', ['/api/v1/accept', '/api'], ['/web/v1/accept']],
            'trailing slash' => ['api/', ['/api/v1/accept', '/api'], ['/web/v1/accept']],
            'nested' => ['api/v1', ['/api/v1/accept', '/api/v1'], ['/api', '/web/v1/accept']],
            'empty' => ['', ['/api/v1/accept', '/web/v1/accept', '/'], []],
        ];
    }

    /**
     * @param  list<string>  $json
     * @param  list<string>  $html
     */
    #[DataProvider('prefixes')]
    public function test_api_json_matches_the_prefix_whatever_its_slashes(string $prefix, array $json, array $html): void
    {
        config(['consistentapi.api_url_prefix' => $prefix]);

        foreach ($json as $path) {
            $this->assertTrue($this->acceptsJson($path), $path);
        }

        foreach ($html as $path) {
            $this->assertFalse($this->acceptsJson($path), $path);
        }
    }

    /**
     * Runs the middleware alone, so any path can be checked without a route.
     */
    private function acceptsJson(string $path): bool
    {
        $request = Request::create($path);

        (new ApiJsonMiddleware)->handle($request, fn () => new Response);

        return $request->headers->get('Accept') === 'application/json';
    }

    public function test_ensure_json_rejects_writes_without_a_json_body(): void
    {
        foreach (['post', 'put', 'patch'] as $method) {
            $this->{$method}('/json-only', ['title' => 'x'])
                ->assertStatus(415)
                ->assertExactJson(['message' => 'The request must have Content-Type: application/json header.']);

            $this->{$method.'Json'}('/json-only', ['title' => 'x'])->assertOk();
        }
    }

    public function test_ensure_json_lets_through_writes_without_a_body(): void
    {
        $this->call('POST', '/json-only')->assertOk();
        $this->call('PATCH', '/json-only', server: ['CONTENT_TYPE' => 'text/plain'])->assertOk();
        $this->call('POST', '/json-only', server: ['CONTENT_TYPE' => 'text/plain'], content: 'x')->assertStatus(415);
    }

    public function test_ensure_json_accepts_json_media_types_and_other_methods(): void
    {
        $this->call('POST', '/json-only', server: ['CONTENT_TYPE' => 'application/vnd.api+json'], content: '{}')->assertOk();
        $this->get('/json-only')->assertOk();
        $this->delete('/json-only')->assertOk();
    }

    public function test_ensure_multipart_lets_through_multipart_posts_only(): void
    {
        $multipart = ['CONTENT_TYPE' => 'multipart/form-data; boundary=x'];

        $this->call('POST', '/upload', server: $multipart)->assertOk();
        $this->call('POST', '/upload', server: ['CONTENT_TYPE' => 'Multipart/Form-Data; boundary=x'])->assertOk();

        $this->postJson('/upload')
            ->assertStatus(415)
            ->assertExactJson(['message' => 'The request must have Content-Type: multipart/form-data header.']);
        $this->call('PUT', '/upload', server: $multipart)->assertStatus(415);
        $this->get('/upload')->assertStatus(415);
    }

    public function test_ensure_multipart_accepts_a_post_that_overrides_its_method(): void
    {
        // PHP fills $_FILES for real POST requests only, so file updates are sent as POST with _method
        $this->call('POST', '/upload', ['_method' => 'PUT'], server: ['CONTENT_TYPE' => 'multipart/form-data; boundary=x'])->assertOk();
    }
}
