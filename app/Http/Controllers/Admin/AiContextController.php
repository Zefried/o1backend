<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiContext;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AiContextController extends Controller
{
    /** GET /api/admin/ai-contexts */
    public function index(Request $request)
    {
        $query = AiContext::with('images')->orderBy('service_name')->orderBy('attribute_definition');

        if ($request->filled('service_name')) {
            $query->where('service_name', $request->service_name);
        }
        if ($request->filled('attribute_definition')) {
            $query->where('attribute_definition', $request->attribute_definition);
        }

        return response()->json(['status' => true, 'data' => $query->get(), 'code' => 200], 200);
    }

    /** POST /api/admin/ai-contexts */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id'          => 'required|string|max:255',
            'service_name'         => 'required|string|max:255',
            'attribute_definition' => 'required|string|max:255',
            'context'              => 'required|string',
            'prompt'               => 'required|string',
            'images'               => 'nullable|array',
            'images.*'             => 'image|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first(), 'code' => 422], 422);
        }

        // Global duplicate image check
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $imageHash = hash_file('sha256', $image->getRealPath());
                $exists = Image::where('image_hash', $imageHash)->exists();
                if ($exists) {
                    return response()->json([
                        'status' => false, 
                        'message' => "Hey, you are using old images (" . $image->getClientOriginalName() . "). This system only accepts unique images. Please upload new images.", 
                        'code' => 422
                    ], 422);
                }
            }
        }

        try {
            DB::beginTransaction();

            $record = AiContext::create([
                'business_id'          => trim($request->business_id),
                'service_name'         => trim($request->service_name),
                'attribute_definition' => trim($request->attribute_definition),
                'context'              => $request->context,
                'prompt'               => $request->prompt,
                'media_resources'      => null,
            ]);

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $imageHash = hash_file('sha256', $image->getRealPath());

                    $extension = $image->getClientOriginalExtension();
                    $filename = Str::uuid() . '.' . $extension;
                    $path = $image->storeAs('images', $filename, 'public');

                    Image::create([
                        'imageable_id'   => $record->id,
                        'imageable_type' => 'App\Models\AiContext',
                        'image_url'      => $path,
                        'image_name'     => $image->getClientOriginalName(),
                        'image_hash'     => $imageHash,
                        'sort_order'     => Image::where('imageable_id', $record->id)->where('imageable_type', 'App\Models\AiContext')->count(),
                        'is_primary'     => Image::where('imageable_id', $record->id)->where('imageable_type', 'App\Models\AiContext')->count() === 0,
                    ]);
                }
            }

            DB::commit();

            return response()->json(['status' => true, 'message' => 'Context created successfully', 'data' => $record, 'code' => 201], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['status' => false, 'message' => 'Failed to create context: ' . $e->getMessage(), 'code' => 500], 500);
        }
    }

    /** PUT /api/admin/ai-contexts/{id} */
    public function update(Request $request, int $id)
    {
        $record = AiContext::find($id);

        if (!$record) {
            return response()->json(['status' => false, 'message' => 'Context not found', 'code' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'business_id'          => 'required|string|max:255',
            'service_name'         => 'required|string|max:255',
            'attribute_definition' => 'required|string|max:255',
            'context'              => 'required|string',
            'prompt'               => 'required|string',
            'media_resources'      => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first(), 'code' => 422], 422);
        }

        $record->business_id          = trim($request->business_id);
        $record->service_name         = trim($request->service_name);
        $record->attribute_definition = trim($request->attribute_definition);
        $record->context              = $request->context;
        $record->prompt               = $request->prompt;
        $record->media_resources      = $request->media_resources;
        $record->save();

        return response()->json(['status' => true, 'message' => 'Context updated successfully', 'data' => $record, 'code' => 200], 200);
    }

    /** DELETE /api/admin/ai-contexts/{id} */
    public function destroy(int $id)
    {
        $record = AiContext::find($id);

        if (!$record) {
            return response()->json(['status' => false, 'message' => 'Context not found', 'code' => 404], 404);
        }

        $record->delete();

        return response()->json(['status' => true, 'message' => 'Context deleted successfully', 'code' => 200], 200);
    }

    /** POST /api/admin/ai-contexts/bulk-delete */
    public function bulkDestroy(Request $request)
    {
        $ids = $request->input('ids', []);
        
        if (empty($ids) || !is_array($ids)) {
            return response()->json(['status' => false, 'message' => 'No contexts selected for deletion', 'code' => 400], 400);
        }

        AiContext::whereIn('id', $ids)->delete();

        return response()->json(['status' => true, 'message' => 'Selected contexts deleted successfully', 'code' => 200], 200);
    }
}
