<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ServiceService;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    private ServiceService $serviceService;

    public function __construct(ServiceService $serviceService)
    {
        $this->serviceService = $serviceService;
    }

    /**
     * GET /api/admin/services
     * Returns all services with their category.
     */
    public function index()
    {
        $result = $this->serviceService->index();
        return response()->json($result, $result['code']);
    }

    /**
     * POST /api/admin/services
     * Create a new service.
     */
    public function store(Request $request)
    {
        $result = $this->serviceService->store($request->all());
        return response()->json($result, $result['code']);
    }

    /**
     * POST /api/admin/services/bulk
     * Create multiple services.
     */
    public function bulkStore(Request $request)
    {
        $result = $this->serviceService->bulkStore($request->all());
        return response()->json($result, $result['code']);
    }

    /**
     * PUT /api/admin/services/{id}
     * Update an existing service.
     */
    public function update(Request $request, int $id)
    {
        $result = $this->serviceService->update($id, $request->all());
        return response()->json($result, $result['code']);
    }

    /**
     * PATCH /api/admin/services/{id}/toggle
     * Toggle status between active and inactive.
     */
    public function toggleStatus(int $id)
    {
        $result = $this->serviceService->toggleStatus($id);
        return response()->json($result, $result['code']);
    }

    /**
     * DELETE /api/admin/services/{id}
     * Delete a service.
     */
    public function destroy(int $id)
    {
        $result = $this->serviceService->destroy($id);
        return response()->json($result, $result['code']);
    }
}
