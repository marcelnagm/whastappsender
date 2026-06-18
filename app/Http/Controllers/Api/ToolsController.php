<?php

namespace App\Http\Controllers\Api;

use App\Services\Api\ToolDispatcher;
use App\Services\Api\ToolRegistry;
use Illuminate\Http\Request;

class ToolsController extends ApiController
{
    public function index(Request $request)
    {
        $isAdmin = $request->user()->isAdmin();

        return $this->success([
            'tools' => ToolRegistry::definitions($isAdmin),
            'format' => 'openai_functions',
            'usage' => [
                'single' => 'POST /api/v1/tools/call with {"name": "tool_name", "arguments": {...}}',
                'chain' => 'POST /api/v1/tools/chain with {"calls": [{"name": "...", "arguments": {...}}, ...]}',
            ],
        ]);
    }

    public function call(Request $request, ToolDispatcher $dispatcher)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'arguments' => 'nullable|array',
        ]);

        $response = $dispatcher->dispatch(
            $data['name'],
            $data['arguments'] ?? [],
            $request
        );

        return $this->wrapToolResponse($data['name'], $response);
    }

    /**
     * Execute a chain of tool calls sequentially.
     * Stops on first failure unless continue_on_error is true.
     */
    public function chain(Request $request, ToolDispatcher $dispatcher)
    {
        $data = $request->validate([
            'calls' => 'required|array|min:1|max:20',
            'calls.*.name' => 'required|string',
            'calls.*.arguments' => 'nullable|array',
            'continue_on_error' => 'nullable|boolean',
        ]);

        $continueOnError = $request->boolean('continue_on_error');
        $results = [];
        $allSuccess = true;

        foreach ($data['calls'] as $index => $call) {
            $response = $dispatcher->dispatch(
                $call['name'],
                $call['arguments'] ?? [],
                $request
            );

            $result = [
                'index' => $index,
                'tool' => $call['name'],
                'success' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 300,
                'status' => $response->getStatusCode(),
                'result' => json_decode($response->getContent(), true),
            ];

            $results[] = $result;

            if (!$result['success']) {
                $allSuccess = false;
                if (!$continueOnError) {
                    break;
                }
            }
        }

        return response()->json([
            'success' => $allSuccess,
            'message' => $allSuccess ? 'All tool calls completed.' : 'One or more tool calls failed.',
            'data' => [
                'results' => $results,
                'executed' => count($results),
                'total' => count($data['calls']),
            ],
        ], $allSuccess ? 200 : 207);
    }

    private function wrapToolResponse(string $toolName, $response)
    {
        $content = json_decode($response->getContent(), true);

        return response()->json([
            'success' => $response->getStatusCode() >= 200 && $response->getStatusCode() < 300,
            'tool' => $toolName,
            'result' => $content,
        ], $response->getStatusCode());
    }
}
