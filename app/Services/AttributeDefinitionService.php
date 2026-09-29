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
                'business_id' => 'required|string',
            ]);

            if ($validator->fails()) {
                return ['status' => false, 'message' => $validator->errors()->first(), 'code' => 422];
            }

            $categoryId = $data['category_id'] ?? null;
            $businessId = $data['business_id'];

            if (AttributeDefinition::where('name', trim($data['name']))->where('category_id', $categoryId)->where('business_id', $businessId)->exists()) {
                return ['status' => false, 'message' => 'Attribute already exists in this category for this business.', 'code' => 409];
            }

            $slug = $this->uniqueSlug(Str::slug($data['name']));

            $attribute = AttributeDefinition::create([
                'name'        => trim($data['name']),
                'slug'        => $slug,
                'category_id' => $categoryId,
                'business_id' => $businessId,
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
    // POST: Bulk Create
    // ─────────────────────────────────────────
    public function bulkStore(array $data): array
    {
        try {
            $validator = Validator::make($data, [
                'attributes'  => 'required|string',
                'category_id' => 'required|integer|exists:categories,id',
                'business_id' => 'required|string',
            ]);

            if ($validator->fails()) {
                return ['status' => false, 'message' => $validator->errors()->first(), 'code' => 422];
            }

            $attributeNames = array_map('trim', preg_split('/[\n,]+/', $data['attributes']));
            $attributeNames = array_filter($attributeNames);
            $attributeNames = array_unique($attributeNames);

            if (empty($attributeNames)) {
                return ['status' => false, 'message' => 'No valid attributes provided.', 'code' => 422];
            }

            $createdCount = 0;
            foreach ($attributeNames as $name) {
                // Skip if attribute already exists with same name and category
                if (AttributeDefinition::where('name', $name)->where('category_id', $data['category_id'])->where('business_id', $data['business_id'])->exists()) {
                    continue;
                }

                $slug = $this->uniqueSlug(Str::slug($name));
                AttributeDefinition::create([
                    'name'        => $name,
                    'slug'        => $slug,
                    'category_id' => $data['category_id'],
                    'business_id' => $data['business_id'],
                    'status'      => 'active',
                ]);
                $createdCount++;
            }

            $message = $createdCount > 0 
                ? "Successfully created {$createdCount} attribute(s)." 
                : "No new attributes created (duplicates skipped).";

            return [
                'status'  => true,
                'message' => $message,
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
    // DELETE: Remove multiple attributes
    // ─────────────────────────────────────────
    public function bulkDestroy(array $ids): array
    {
        try {
            if (empty($ids)) {
                return ['status' => false, 'message' => 'No attributes provided for deletion.', 'code' => 422];
            }

            $deletedCount = AttributeDefinition::whereIn('id', $ids)->delete();

            return [
                'status'  => true,
                'message' => "{$deletedCount} attributes deleted successfully.",
                'code'    => 200,
            ];
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
