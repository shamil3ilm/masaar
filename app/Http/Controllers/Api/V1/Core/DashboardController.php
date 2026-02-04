<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Core;

use App\Http\Controllers\Controller;
use App\Models\Core\DashboardLayout;
use App\Models\Core\DashboardWidget;
use App\Models\Core\OrganizationSubscription;
use App\Services\Core\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService
    ) {}

    /**
     * Get dashboard data.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $type = $request->get('type', DashboardLayout::TYPE_MAIN);

        $layout = DashboardLayout::getForUser(
            $user->organization_id,
            $user->id,
            $type
        );

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        $data = $this->dashboardService->getDashboardData($layout);

        return response()->json(['data' => $data]);
    }

    /**
     * Get quick stats overview from all modules.
     */
    public function quickStats(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        return response()->json([
            'data' => $this->dashboardService->getQuickStats(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get single widget data.
     */
    public function widget(Request $request, string $widgetCode): JsonResponse
    {
        $user = $request->user();
        $widget = DashboardWidget::getByCode($widgetCode);

        if (!$widget) {
            return response()->json(['error' => 'Widget not found'], 404);
        }

        // Check premium access
        if ($widget->is_premium) {
            $subscription = OrganizationSubscription::getCurrentForOrganization($user->organization_id);
            if (!$subscription?->hasFeature('dashboard_customization')) {
                return response()->json(['error' => 'Premium feature'], 403);
            }
        }

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);

        $config = array_merge($widget->default_config ?? [], $request->all());
        $data = $this->dashboardService->getWidgetData($widget, $config);

        return response()->json([
            'widget' => $widget->toArray(),
            'data' => $data,
        ]);
    }

    /**
     * Get available widgets.
     */
    public function widgets(Request $request): JsonResponse
    {
        $user = $request->user();
        $module = $request->get('module');
        $category = $request->get('category');

        $subscription = OrganizationSubscription::getCurrentForOrganization($user->organization_id);
        $includePremium = $subscription?->hasFeature('dashboard_customization') ?? false;

        $query = DashboardWidget::active()->orderBy('sort_order');

        if ($module) {
            $query->forModule($module);
        }

        if ($category) {
            $query->forCategory($category);
        }

        if (!$includePremium) {
            $query->free();
        }

        $widgets = $query->get();

        return response()->json([
            'data' => $widgets,
            'categories' => DashboardWidget::getCategories(),
            'types' => DashboardWidget::getTypes(),
            'premium_access' => $includePremium,
        ]);
    }

    /**
     * Get user's dashboard layouts.
     */
    public function layouts(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get user's own layouts
        $userLayouts = DashboardLayout::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->get();

        // Get shared organization layouts
        $sharedLayouts = DashboardLayout::where('organization_id', $user->organization_id)
            ->whereNull('user_id')
            ->where('is_shared', true)
            ->get();

        return response()->json([
            'data' => [
                'user_layouts' => $userLayouts,
                'shared_layouts' => $sharedLayouts,
            ],
            'types' => DashboardLayout::getTypes(),
        ]);
    }

    /**
     * Get a specific layout.
     */
    public function layout(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $layout = DashboardLayout::where('organization_id', $user->organization_id)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere('is_shared', true);
            })
            ->findOrFail($id);

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);
        $data = $this->dashboardService->getDashboardData($layout);

        return response()->json(['data' => $data]);
    }

    /**
     * Create a new layout.
     */
    public function createLayout(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'type' => 'required|string|in:' . implode(',', array_keys(DashboardLayout::getTypes())),
            'widgets' => 'nullable|array',
            'layout' => 'nullable|array',
            'is_default' => 'nullable|boolean',
            'is_shared' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        // Check if user can create shared layouts
        $isShared = $request->boolean('is_shared');
        if ($isShared && !$user->hasPermission('core.settings.edit')) {
            return response()->json(['error' => 'Permission denied for shared layouts'], 403);
        }

        $layout = DashboardLayout::create([
            'organization_id' => $user->organization_id,
            'user_id' => $isShared ? null : $user->id,
            'name' => $request->get('name'),
            'type' => $request->get('type'),
            'widgets' => $request->get('widgets', []),
            'layout' => $request->get('layout', ['columns' => 4, 'row_height' => 150, 'gap' => 16]),
            'is_default' => $request->boolean('is_default'),
            'is_shared' => $isShared,
        ]);

        // If setting as default, unset other defaults
        if ($layout->is_default) {
            DashboardLayout::where('organization_id', $user->organization_id)
                ->where('user_id', $isShared ? null : $user->id)
                ->where('type', $layout->type)
                ->where('id', '!=', $layout->id)
                ->update(['is_default' => false]);
        }

        return response()->json(['data' => $layout], 201);
    }

    /**
     * Update a layout.
     */
    public function updateLayout(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $layout = DashboardLayout::where('organization_id', $user->organization_id)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere(function ($q2) use ($user) {
                        $q2->whereNull('user_id')
                            ->where('is_shared', true);
                    });
            })
            ->findOrFail($id);

        // Check permission for shared layouts
        if ($layout->is_shared && !$user->hasPermission('core.settings.edit')) {
            return response()->json(['error' => 'Permission denied'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'widgets' => 'sometimes|array',
            'layout' => 'sometimes|array',
            'is_default' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $layout->fill($validator->validated());
        $layout->save();

        // If setting as default, unset other defaults
        if ($request->boolean('is_default')) {
            DashboardLayout::where('organization_id', $user->organization_id)
                ->where('user_id', $layout->user_id)
                ->where('type', $layout->type)
                ->where('id', '!=', $layout->id)
                ->update(['is_default' => false]);
        }

        return response()->json(['data' => $layout]);
    }

    /**
     * Delete a layout.
     */
    public function deleteLayout(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $layout = DashboardLayout::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->findOrFail($id);

        $layout->delete();

        return response()->json(['message' => 'Layout deleted']);
    }

    /**
     * Add widget to layout.
     */
    public function addWidget(Request $request, int $layoutId): JsonResponse
    {
        $user = $request->user();

        $layout = DashboardLayout::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->findOrFail($layoutId);

        $validator = Validator::make($request->all(), [
            'widget_code' => 'required|string|exists:dashboard_widgets,code',
            'size' => 'required|string',
            'position' => 'required|array',
            'position.x' => 'required|integer|min:0',
            'position.y' => 'required|integer|min:0',
            'config' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $layout->addWidget(
            $request->get('widget_code'),
            $request->get('size'),
            $request->get('position'),
            $request->get('config', [])
        );

        return response()->json(['data' => $layout]);
    }

    /**
     * Remove widget from layout.
     */
    public function removeWidget(Request $request, int $layoutId, string $widgetCode): JsonResponse
    {
        $user = $request->user();

        $layout = DashboardLayout::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->findOrFail($layoutId);

        $layout->removeWidget($widgetCode);

        return response()->json(['data' => $layout]);
    }

    /**
     * Update widget position.
     */
    public function updateWidgetPosition(Request $request, int $layoutId, string $widgetCode): JsonResponse
    {
        $user = $request->user();

        $layout = DashboardLayout::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->findOrFail($layoutId);

        $validator = Validator::make($request->all(), [
            'position' => 'required|array',
            'position.x' => 'required|integer|min:0',
            'position.y' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $layout->updateWidgetPosition($widgetCode, $request->get('position'));

        return response()->json(['data' => $layout]);
    }

    /**
     * Reset layout to default.
     */
    public function resetLayout(Request $request, string $type): JsonResponse
    {
        $user = $request->user();

        // Delete existing user layout for this type
        DashboardLayout::where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->delete();

        // Create new default layout
        $layout = DashboardLayout::createDefaultLayout(
            $user->organization_id,
            $user->id,
            $type
        );

        $this->dashboardService->setContext($user->organization_id, $user->current_branch_id);
        $data = $this->dashboardService->getDashboardData($layout);

        return response()->json(['data' => $data]);
    }
}
