<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CampaignInformation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CampaignInformationController extends Controller
{
    /** GET /api/admin/campaigns?business_id=XYZ */
    public function index(Request $request)
    {
        $query = CampaignInformation::query();

        if ($request->filled('business_id')) {
            $query->where('business_id', trim($request->business_id));
        }

        $campaigns = $query->with('service')->latest()->get();

        return response()->json(['status' => true, 'message' => 'Campaigns fetched', 'data' => $campaigns, 'code' => 200], 200);
    }

    /** POST /api/admin/campaigns */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'business_id' => 'required|string',
            'campaign_name' => 'required|string|max:255',
            'gender' => 'required|string|in:both,male,female',
            'locations' => 'required|string',
            'campaign_link' => 'required|string|unique:campaign_information,campaign_link',
            'service_id' => 'nullable|integer|exists:services,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first(), 'code' => 422], 422);
        }

        $record = CampaignInformation::create([
            'business_id' => trim($request->business_id),
            'service_id' => $request->service_id ? (int) $request->service_id : null,
            'campaign_name' => trim($request->campaign_name),
            'gender' => trim($request->gender),
            'locations' => trim($request->locations),
            'campaign_link' => trim($request->campaign_link),
        ]);

        return response()->json(['status' => true, 'message' => 'Campaign saved successfully', 'data' => $record, 'code' => 201], 201);
    }

    /** PUT /api/admin/campaigns/{id} */
    public function update(Request $request, $id)
    {
        $record = CampaignInformation::find($id);

        if (!$record) {
            return response()->json(['status' => false, 'message' => 'Campaign not found', 'code' => 404], 404);
        }

        $validator = Validator::make($request->all(), [
            'campaign_name' => 'required|string|max:255',
            'gender' => 'required|string|in:both,male,female',
            'locations' => 'required|string',
            'service_id' => 'nullable|integer|exists:services,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first(), 'code' => 422], 422);
        }

        $record->update([
            'campaign_name' => trim($request->campaign_name),
            'gender' => trim($request->gender),
            'locations' => trim($request->locations),
            'service_id' => $request->service_id ? (int) $request->service_id : null,
        ]);

        return response()->json(['status' => true, 'message' => 'Campaign updated successfully', 'data' => $record, 'code' => 200], 200);
    }

    /** DELETE /api/admin/campaigns/{id} */
    public function destroy($id)
    {
        $record = CampaignInformation::find($id);

        if (!$record) {
            return response()->json(['status' => false, 'message' => 'Campaign not found', 'code' => 404], 404);
        }

        $record->delete();

        return response()->json(['status' => true, 'message' => 'Campaign deleted successfully', 'code' => 200], 200);
    }
}
