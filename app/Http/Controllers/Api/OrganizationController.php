<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    use ApiResponse;

    // GET /api/organizations
    public function index(Request $request): JsonResponse
    {
        $this->authorize('browse', Organization::class);

        $query = Organization::with('parent')->latest();

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($request->boolean('active_only', false)) {
            $query->active();
        }

        $paginated = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => OrganizationResource::collection($paginated)->resolve(),
            'meta'    => [
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
                'per_page'     => $paginated->perPage(),
                'total'        => $paginated->total(),
            ],
        ]);
    }

    // GET /api/organizations/{id}
    public function show(Organization $organization): JsonResponse
    {
        $this->authorize('browse', Organization::class);

        $organization->load(['parent', 'children']);
        return $this->success(OrganizationResource::make($organization));
    }
}
