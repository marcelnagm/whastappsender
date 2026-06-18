<?php

namespace App\Services\Api;

use App\Models\Campaign;
use App\Models\CampaignItem;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ToolDispatcher
{
    private const ADMIN_TOOLS = [
        'admin_list_users', 'admin_get_user', 'admin_update_user', 'admin_delete_user',
        'admin_toggle_user_active', 'admin_toggle_user_admin',
        'admin_panic_status', 'admin_toggle_panic', 'admin_clear_send_queue', 'admin_clear_warmup_queue',
    ];

    public function dispatch(string $toolName, array $arguments, Request $request): JsonResponse
    {
        $handlers = ToolRegistry::handlers();

        if (!isset($handlers[$toolName])) {
            return response()->json([
                'success' => false,
                'message' => "Unknown tool: {$toolName}",
            ], 404);
        }

        if (in_array($toolName, self::ADMIN_TOOLS) && !Auth::user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Admin access required for this tool.',
            ], 403);
        }

        $handler = $handlers[$toolName];
        $controllerClass = $handler[0];
        $method = $handler[1];

        $routeParams = $this->resolveRouteParams($handler, $arguments);
        $request->merge($arguments);

        try {
            $controller = app($controllerClass);
            $params = $this->buildMethodParams($controllerClass, $method, $request, $routeParams);

            /** @var JsonResponse $response */
            $response = app()->call([$controller, $method], $params);

            return $response;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found.',
            ], 404);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Request failed.',
            ], $e->getStatusCode());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    private function resolveRouteParams(array $handler, array $arguments): array
    {
        $params = [];

        foreach ($handler as $key => $value) {
            if (is_int($key)) {
                continue;
            }

            if (!isset($arguments[$key])) {
                continue;
            }

            $model = $this->resolveModel($value, $arguments[$key]);
            if ($model !== null) {
                $params[$value] = $model;
            } else {
                $params[$value] = $arguments[$key];
            }
        }

        return $params;
    }

    private function resolveModel(string $paramName, mixed $id): mixed
    {
        $modelMap = [
            'contact' => Contact::class,
            'campaign' => Campaign::class,
            'campaignItem' => CampaignItem::class,
            'instance' => Instance::class,
            'user' => User::class,
        ];

        if (!isset($modelMap[$paramName])) {
            if ($paramName === 'campaignItemId' || $paramName === 'id') {
                return (int) $id;
            }
            return null;
        }

        $query = $modelMap[$paramName]::query();

        if (!Auth::user()->isAdmin() && in_array($paramName, ['contact', 'campaign', 'campaignItem', 'instance'])) {
            $query->where('user_id', Auth::id());
        }

        return $query->findOrFail($id);
    }

    private function buildMethodParams(string $controllerClass, string $method, Request $request, array $routeParams): array
    {
        $params = ['request' => $request];

        foreach ($routeParams as $name => $value) {
            $params[$name] = $value;
        }

        return $params;
    }
}
