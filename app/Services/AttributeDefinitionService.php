<?php

namespace App\Services;

use App\Models\AttributeDefinition;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AttributeDefinitionService
{
    // ─────────────────────────────────────────
    // GET: All attributes (with category)
    // ─────────────────────────────────────────
    public function index(): array
    {
        try {
            $attributes = AttributeDefinition::with('category:id,name')
                ->orderBy('name')
                ->get();

            return ['status' => true, 'data' => $attributes, 'code' => 200];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    // ─────────────────────────────────────────
    // POST: Create
    // ─────────────────────────────────────────
    public function store(array $data): array
    {
        try {
            $validator = Validator::make($data, [
                'name'        => 'required|string|max:255',
                'category_id' => 'nullable|integer|exists:categories,id',
                'description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return ['status' => false, 'message' => $validator->errors()->first(), 'code' => 422];
            }

            $slug = $this->uniqueSlug(Str::slug($data['name']));

            $attribute = AttributeDefinition::create([
                'name'        => trim($data['name']),
                'slug'        => $slug,
                'category_id' => $data['category_id'] ?? null,
                'description' => $data['description'] ?? null,
                'status'      => 'active',
            ]);

            return [
                'status'  => true,
                'message' => 'Attribute created successfully',
                'data'    => $attribute->load('category:id,name'),
                'code'    => 201,
            ];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    // ─────────────────────────────────────────
    // PUT: Update
    // ─────────────────────────────────────────
    public function update(int $id, array $data): array
    {
        try {
            $attribute = AttributeDefinition::find($id);

            if (!$attribute) {
                return ['status' => false, 'message' => 'Attribute not found', 'code' => 404];
            }

            $validator = Validator::make($data, [
                'name'        => 'required|string|max:255',
                'category_id' => 'nullable|integer|exists:categories,id',
                'description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return ['status' => false, 'message' => $validator->errors()->first(), 'code' => 422];
            }

            $attribute->name        = trim($data['name']);
            $attribute->slug        = $this->uniqueSlug(Str::slug($data['name']), $id);
            $attribute->category_id = $data['category_id'] ?? null;
            $attribute->description = $data['description'] ?? null;
            $attribute->save();

            return [
                'status'  => true,
                'message' => 'Attribute updated successfully',
                'data'    => $attribute->load('category:id,name'),
                'code'    => 200,
            ];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    // ─────────────────────────────────────────
    // PATCH: Toggle status
    // ─────────────────────────────────────────
    public function toggleStatus(int $id): array
    {
        try {
            $attribute = AttributeDefinition::find($id);

            if (!$attribute) {
                return ['status' => false, 'message' => 'Attribute not found', 'code' => 404];
            }

            $attribute->status = $attribute->status === 'active' ? 'inactive' : 'active';
            $attribute->save();

            return [
                'status'  => true,
                'message' => 'Status updated to ' . $attribute->status,
                'data'    => $attribute,
                'code'    => 200,
            ];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    // ─────────────────────────────────────────
    // DELETE: Remove attribute
    // ─────────────────────────────────────────
    public function destroy(int $id): array
    {
        try {
            $attribute = AttributeDefinition::find($id);

            if (!$attribute) {
                return ['status' => false, 'message' => 'Attribute not found', 'code' => 404];
            }

            $attribute->delete();

            return ['status' => true, 'message' => 'Attribute deleted successfully', 'code' => 200];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    // ─────────────────────────────────────────
    // PRIVATE: Unique slug
    // ─────────────────────────────────────────
    private function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $original = $slug;
        $counter  = 1;

        while (
            AttributeDefinition::where('slug', $slug)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $original . '-' . $counter++;
        }

        return $slug;
    }
}
