<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CategoryService
{
    // ─────────────────────────────────────────
    // GET: Full nested tree (for list view)
    // ─────────────────────────────────────────
    public function tree(): array
    {
        try {
            $categories = Category::roots()
                ->with('allChildren')
                ->orderBy('name')
                ->get();

            return [
                'status' => true,
                'data'   => $categories,
                'code'   => 200,
            ];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    // ─────────────────────────────────────────
    // GET: Flat list with depth for dropdowns
    // ─────────────────────────────────────────
    public function flatList(): array
    {
        try {
            $roots = Category::roots()
                ->with('allChildren')
                ->orderBy('name')
                ->get();

            $flat = [];
            $this->flatten($roots, $flat, 0);

            return [
                'status' => true,
                'data'   => $flat,
                'code'   => 200,
            ];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    // ─────────────────────────────────────────
    // POST: Create a new category
    // ─────────────────────────────────────────
    public function store(array $data): array
    {
        try {
            $validator = Validator::make($data, [
                'name'      => 'required|string|max:255',
                'parent_id' => 'nullable|integer|exists:categories,id',
            ]);

            if ($validator->fails()) {
                return ['status' => false, 'message' => $validator->errors()->first(), 'code' => 422];
            }

            $slug = $this->uniqueSlug(Str::slug($data['name']));

            $category = Category::create([
                'name'      => trim($data['name']),
                'slug'      => $slug,
                'parent_id' => $data['parent_id'] ?? null,
                'status'    => 'active',
            ]);

            return [
                'status'  => true,
                'message' => 'Category created successfully',
                'data'    => $category,
                'code'    => 201,
            ];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    // ─────────────────────────────────────────
    // PUT: Update a category
    // ─────────────────────────────────────────
    public function update(int $id, array $data): array
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return ['status' => false, 'message' => 'Category not found', 'code' => 404];
            }

            $validator = Validator::make($data, [
                'name'      => 'required|string|max:255',
                'parent_id' => 'nullable|integer|exists:categories,id',
            ]);

            if ($validator->fails()) {
                return ['status' => false, 'message' => $validator->errors()->first(), 'code' => 422];
            }

            // Guard: cannot assign itself as parent
            if (!empty($data['parent_id']) && (int) $data['parent_id'] === $id) {
                return ['status' => false, 'message' => 'A category cannot be its own parent', 'code' => 422];
            }

            // Guard: cannot assign a descendant as parent (prevents circular reference)
            if (!empty($data['parent_id']) && $this->isDescendant($id, (int) $data['parent_id'])) {
                return ['status' => false, 'message' => 'Cannot assign a child category as a parent (circular reference)', 'code' => 422];
            }

            $category->name      = trim($data['name']);
            $category->slug      = $this->uniqueSlug(Str::slug($data['name']), $id);
            $category->parent_id = $data['parent_id'] ?? null;
            $category->save();

            return [
                'status'  => true,
                'message' => 'Category updated successfully',
                'data'    => $category,
                'code'    => 200,
            ];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    // ─────────────────────────────────────────
    // PATCH: Toggle active <-> inactive
    // ─────────────────────────────────────────
    public function toggleStatus(int $id): array
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return ['status' => false, 'message' => 'Category not found', 'code' => 404];
            }

            $category->status = $category->status === 'active' ? 'inactive' : 'active';
            $category->save();

            return [
                'status'  => true,
                'message' => 'Status updated to ' . $category->status,
                'data'    => $category,
                'code'    => 200,
            ];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    // ─────────────────────────────────────────
    // DELETE: Remove a category (cascade handles children)
    // ─────────────────────────────────────────
    public function destroy(int $id): array
    {
        try {
            $category = Category::find($id);

            if (!$category) {
                return ['status' => false, 'message' => 'Category not found', 'code' => 404];
            }

            $category->delete(); // cascade deletes children via FK

            return [
                'status'  => true,
                'message' => 'Category deleted successfully',
                'code'    => 200,
            ];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    // ═════════════════════════════════════════
    // PRIVATE HELPERS
    // ═════════════════════════════════════════

    /**
     * Recursively flatten a nested category tree into a flat array.
     * Adds a 'depth' key and a prefixed label for dropdowns.
     */
    private function flatten($categories, array &$flat, int $depth): void
    {
        foreach ($categories as $category) {
            $flat[] = [
                'id'     => $category->id,
                'name'   => $category->name,
                'label'  => str_repeat('— ', $depth) . $category->name, // e.g. "— Indian"
                'depth'  => $depth,
                'status' => $category->status,
            ];

            if ($category->allChildren && $category->allChildren->isNotEmpty()) {
                $this->flatten($category->allChildren, $flat, $depth + 1);
            }
        }
    }

    /**
     * Check if $potentialDescendantId is a descendant of $ancestorId.
     * Prevents circular parent assignments.
     */
    private function isDescendant(int $ancestorId, int $potentialDescendantId): bool
    {
        $children = Category::where('parent_id', $ancestorId)->pluck('id');

        if ($children->contains($potentialDescendantId)) {
            return true;
        }

        foreach ($children as $childId) {
            if ($this->isDescendant($childId, $potentialDescendantId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate a unique slug, ignoring the current category's own slug on update.
     */
    private function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $original = $slug;
        $counter  = 1;

        while (
            Category::where('slug', $slug)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $original . '-' . $counter++;
        }

        return $slug;
    }
}
