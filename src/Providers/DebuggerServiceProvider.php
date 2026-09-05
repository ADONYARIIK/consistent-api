<?php

declare(strict_types=1);

namespace Adonyarik\ConsistentApi\Providers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

class DebuggerServiceProvider
{
    private string $traceId = '';
    private bool $active = false;
    private float $beginTime = 0.0;
    private array $sqlLogs = [];
    private int $sqlCount = 0;

    public function __construct()
    {
        $this->active = (bool) config('consistentapi.debugger_enabled', false);

        if (! $this->active) {
            return;
        }

        $this->traceId = uniqid('dbg_', true);
        $this->beginTime = microtime(true);
    }

    public function boot(): bool
    {
        if (! $this->active) {
            return false;
        }

        DB::listen(function ($query) {
            $this->sqlLogs[] = [
                'sql' => $query->sql,
                'bindings' => $query->bindings,
                'time' => $query->time,
            ];
            $this->sqlCount++;
        });

        return true;
    }

    public function modifyResponse(Request $request, Response $response): ?Response
    {
        if (! $this->active) {
            return $response;
        }

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            if (! is_array($data)) {
                $data = [];
            }

            $data['debugger'] = $this->collectData($request);
            $response->setData($data);
        }

        return $response;
    }

    private function collectData(Request $request): array
    {
        return [
            'id' => $this->traceId,
            'datetime' => date('Y-m-d H:i:s'),
            'executionTime' => microtime(true) - $this->beginTime,
            'method' => $request->getMethod(),
            'uri' => $request->getRequestUri(),
            'clientIP' => $request->getClientIp(),
            'memoryUsage' => $this->formatBytes(memory_get_peak_usage()),
            'router' => Route::currentRouteAction(),
            'inputs' => $request->all(),
            'db' => [
                'queryCount' => $this->sqlCount,
                'list' => $this->sqlLogs,
            ],
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }
}
