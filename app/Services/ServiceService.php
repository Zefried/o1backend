<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ServiceService
{
    // ─────────────────────────────────────────
    // GET: All services (with category)
    // ─────────────────────────────────────────
    public function index(): array
    {
        try {
            $services = Service::with('category:id,name')
                ->orderBy('name')
                ->get();

            return [
                'status' => true,
                'data'   => $services,
                'code'   => 200,
            ];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    // ─────────────────────────────────────────
    // POST: Create a new service
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

            $categoryId = $data['category_id'] ?? null;

            if (Service::where('name', trim($data['name']))->where('category_id', $categoryId)->exists()) {
                return ['status' => false, 'message' => 'Service already exists in this category.', 'code' => 409];
            }

            $slug = $this->uniqueSlug(Str::slug($data['name']));

            $service = Service::create([
                'name'        => trim($data['name']),
                'slug'        => $slug,
                'category_id' => $data['category_id'] ?? null,
                'description' => $data['description'] ?? null,
                'status'      => 'active',
            ]);

            return [
                'status'  => true,
                'message' => 'Service created successfully',
                'data'    => $service->load('category:id,name'),
                'code'    => 201,
            ];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    // ─────────────────────────────────────────
    // POST: Create multiple services in bulk
    // ─────────────────────────────────────────
    public function bulkStore(array $data): array
    {
        try {
            $validator = Validator::make($data, [
                'services'    => 'required|string',
                'category_id' => 'required|integer|exists:categories,id',
            ]);

            if ($validator->fails()) {
                return ['status' => false, 'message' => $validator->errors()->first(), 'code' => 422];
            }

            $serviceNames = preg_split('/[\n,]+/', $data['services']);
            $serviceNames = array_map('trim', $serviceNames);
            $serviceNames = array_filter($serviceNames);
            $serviceNames = array_unique($serviceNames);

            if (empty($serviceNames)) {
                return ['status' => false, 'message' => 'No valid services provided.', 'code' => 422];
            }

            $createdCount = 0;

            foreach ($serviceNames as $name) {
                // Skip if service already exists with same name and category
                if (Service::where('name', $name)->where('category_id', $data['category_id'])->exists()) {
                    continue;
                }

                $slug = $this->uniqueSlug(Str::slug($name));
                Service::create([
                    'name'        => $name,
                    'slug'        => $slug,
                    'category_id' => $data['category_id'],
                    'status'      => 'active',
                ]);
                $createdCount++;
            }

            return [
                'status'  => true,
                'message' => $createdCount . ' services created successfully',
                'code'    => 201,
            ];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    // ─────────────────────────────────────────
    // PUT: Update a service
    // ─────────────────────────────────────────
    public function update(int $id, array $data): array
    {
        try {
            $service = Service::find($id);

            if (!$service) {
                return ['status' => false, 'message' => 'Service not found', 'code' => 404];
            }

            $validator = Validator::make($data, [
                'name'        => 'required|string|max:255',
                'category_id' => 'nullable|integer|exists:categories,id',
                'description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return ['status' => false, 'message' => $validator->errors()->first(), 'code' => 422];
            }

            $service->name        = trim($data['name']);
            $service->slug        = $this->uniqueSlug(Str::slug($data['name']), $id);
            $service->category_id = $data['category_id'] ?? null;
            $service->description = $data['description'] ?? null;
            $service->save();

            return [
                'status'  => true,
                'message' => 'Service updated successfully',
                'data'    => $service->load('category:id,name'),
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
            $service = Service::find($id);

            if (!$service) {
                return ['status' => false, 'message' => 'Service not found', 'code' => 404];
            }

            $service->status = $service->status === 'active' ? 'inactive' : 'active';
            $service->save();

            return [
                'status'  => true,
                'message' => 'Status updated to ' . $service->status,
                'data'    => $service,
                'code'    => 200,
            ];
        } catch (\Exception $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    // ─────────────────────────────────────────
    // DELETE: Remove a service
    // ─────────────────────────────────────────
    public function destroy(int $id): array
    {
        try {
            $service = Service::find($id);

            if (!$service) {
                return ['status' => false, 'message' => 'Service not found', 'code' => 404];
            }

            $service->delete();

            return [
                'status'  => true,
                'message' => 'Service deleted successfully',
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
     * Generate a unique slug, ignoring the current service's own slug on update.
     */
    private function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $original = $slug;
        $counter  = 1;

        while (
            Service::where('slug', $slug)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $original . '-' . $counter++;
        }

        return $slug;
    }
}
