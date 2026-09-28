<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AiContextController extends Controller
{
    /** GET /api/admin/ai-contexts */
    public function index(Request $request)
    {
        $query = AiContext::orderBy('service_name')->orderBy('attribute_definition');

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
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first(), 'code' => 422], 422);
        }

            $record = AiContext::create([
                'business_id'          => trim($request->business_id),
                'service_name'         => trim($request->service_name),
                'attribute_definition' => trim($request->attribute_definition),
                'context'              => $request->context,
                'prompt'               => $request->prompt,
            ]);

        return response()->json(['status' => true, 'message' => 'Context created successfully', 'data' => $record, 'code' => 201], 201);
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
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first(), 'code' => 422], 422);
        }

        $record->business_id          = trim($request->business_id);
        $record->service_name         = trim($request->service_name);
        $record->attribute_definition = trim($request->attribute_definition);
        $record->context              = $request->context;
        $record->prompt               = $request->prompt;
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
}
