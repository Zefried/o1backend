<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttributeDefinition;
use App\Models\AttributeField;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class AttributeFieldController extends Controller
{
    /**
     * GET /api/admin/attribute-fields
     * Returns all fields, optionally filtered by ?attribute_id=
     */
    public function index(Request $request)
    {
        $query = AttributeField::orderBy('attribute_definition_id')
                               ->orderBy('sort_order')
                               ->orderBy('name');

        if ($request->filled('attribute_id')) {
            $query->where('attribute_definition_id', $request->attribute_id);
        }

        return response()->json([
            'status' => true,
            'data'   => $query->get(),
            'code'   => 200,
        ], 200);
    }

    /**
     * POST /api/admin/attribute-fields
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'attribute_definition_id' => 'required|integer|exists:attribute_definitions,id',
            'name'                    => 'required|string|max:255',
            'data_type'               => 'required|string|in:string,number,boolean',
            'sort_order'              => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first(), 'code' => 422], 422);
        }

        // Fetch parent attribute for denormalized name
        $attribute = AttributeDefinition::find($request->attribute_definition_id);

        // Build slug and ensure it's unique within this attribute_definition
        $baseSlug = Str::slug($request->name);
        $slug     = $this->scopedUniqueSlug($baseSlug, $request->attribute_definition_id);

        $field = AttributeField::create([
            'attribute_definition_id'   => $attribute->id,
            'attribute_definition_name' => $attribute->name,
            'name'                      => trim($request->name),
            'slug'                      => $slug,
            'data_type'                 => $request->data_type,
            'sort_order'                => $request->sort_order ?? 0,
            'status'                    => 'active',
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Field created successfully',
            'data'    => $field,
            'code'    => 201,
        ], 201);
    }

    /**
     * PUT /api/admin/attribute-fields/{id}
     */
    public function update(Request $request, int $id)
    {
        $field = AttributeField::find($id);

        if (!$field) {
            return response()->json(['status' => false, 'message' => 'Field not found', 'code' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'       => 'required|string|max:255',
            'data_type'  => 'required|string|in:string,number,boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first(), 'code' => 422], 422);
        }

        $baseSlug = Str::slug($request->name);
        $slug     = $this->scopedUniqueSlug($baseSlug, $field->attribute_definition_id, $id);

        $field->name       = trim($request->name);
        $field->slug       = $slug;
        $field->data_type  = $request->data_type;
        $field->sort_order = $request->sort_order ?? $field->sort_order;
        $field->save();

        return response()->json([
            'status'  => true,
            'message' => 'Field updated successfully',
            'data'    => $field,
            'code'    => 200,
        ], 200);
    }

    /**
     * PATCH /api/admin/attribute-fields/{id}/toggle
     */
    public function toggleStatus(int $id)
    {
        $field = AttributeField::find($id);

        if (!$field) {
            return response()->json(['status' => false, 'message' => 'Field not found', 'code' => 404], 404);
        }

        $field->status = $field->status === 'active' ? 'inactive' : 'active';
        $field->save();

        return response()->json([
            'status'  => true,
            'message' => 'Status updated to ' . $field->status,
            'data'    => $field,
            'code'    => 200,
        ], 200);
    }

    /**
     * DELETE /api/admin/attribute-fields/{id}
     */
    public function destroy(int $id)
    {
        $field = AttributeField::find($id);

        if (!$field) {
            return response()->json(['status' => false, 'message' => 'Field not found', 'code' => 404], 404);
        }

        $field->delete();

        return response()->json([
            'status'  => true,
            'message' => 'Field deleted successfully',
            'code'    => 200,
        ], 200);
    }

    // ─────────────────────────────────────────
    // PRIVATE: slug unique within attribute_definition scope
    // ─────────────────────────────────────────
    private function scopedUniqueSlug(string $slug, int $attributeId, ?int $ignoreId = null): string
    {
        $original = $slug;
        $counter  = 1;

        while (
            AttributeField::where('attribute_definition_id', $attributeId)
                ->where('slug', $slug)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $original . '-' . $counter++;
        }

        return $slug;
    }
}
