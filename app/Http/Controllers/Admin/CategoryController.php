<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CategoryService;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    private CategoryService $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    /**
     * GET /api/admin/categories
     * Returns the full nested tree.
     */
    public function index()
    {
        $result = $this->categoryService->tree();
        return response()->json($result, $result['code']);
    }

    /**
     * GET /api/admin/categories/flat
     * Returns a flat list with depth labels — used for parent dropdowns.
     */
    public function flatList()
    {
        $result = $this->categoryService->flatList();
        return response()->json($result, $result['code']);
    }

    /**
     * POST /api/admin/categories
     * Create a new category.
     */
    public function store(Request $request)
    {
        $result = $this->categoryService->store($request->all());
        return response()->json($result, $result['code']);
    }

    /**
     * PUT /api/admin/categories/{id}
     * Update an existing category.
     */
    public function update(Request $request, int $id)
    {
        $result = $this->categoryService->update($id, $request->all());
        return response()->json($result, $result['code']);
    }

    /**
     * PATCH /api/admin/categories/{id}/toggle
     * Toggle status between active and inactive.
     */
    public function toggleStatus(int $id)
    {
        $result = $this->categoryService->toggleStatus($id);
        return response()->json($result, $result['code']);
    }

    /**
     * DELETE /api/admin/categories/{id}
     * Delete a category (children are cascade-deleted).
     */
    public function destroy(int $id)
    {
        $result = $this->categoryService->destroy($id);
        return response()->json($result, $result['code']);
    }
}
