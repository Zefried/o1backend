<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AttributeDefinitionService;
use Illuminate\Http\Request;

class AttributeController extends Controller
{
    private AttributeDefinitionService $service;

    public function __construct(AttributeDefinitionService $service)
    {
        $this->service = $service;
    }

    /** GET /api/admin/attributes */
    public function index()
    {
        $result = $this->service->index();
        return response()->json($result, $result['code']);
    }

    /** POST /api/admin/attributes */
    public function store(Request $request)
    {
        $result = $this->service->store($request->all());
        return response()->json($result, $result['code']);
    }

    /** POST /api/admin/attributes/bulk */
    public function bulkStore(Request $request)
    {
        $result = $this->service->bulkStore($request->all());
        return response()->json($result, $result['code']);
    }

    /** PUT /api/admin/attributes/{id} */
    public function update(Request $request, int $id)
    {
        $result = $this->service->update($id, $request->all());
        return response()->json($result, $result['code']);
    }

    /** PATCH /api/admin/attributes/{id}/toggle */
    public function toggleStatus(int $id)
    {
        $result = $this->service->toggleStatus($id);
        return response()->json($result, $result['code']);
    }

    /** DELETE /api/admin/attributes/{id} */
    public function destroy(int $id)
    {
        $result = $this->service->destroy($id);
        return response()->json($result, $result['code']);
    }
}
