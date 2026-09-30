<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Service;
use App\Models\LeadQualification;
use Illuminate\Http\Request;

class LeadQualificationController extends Controller
{
    public function getServices(Request $request)
    {
        $request->validate(['business_id' => 'required|string']);
        
        $business = User::where('role', 'business')->where('business_id', $request->business_id)->first();
        
        if (!$business) {
            return response()->json(['status' => false, 'message' => 'Business not found.']);
        }
        
        $services = Service::where('category_id', $business->category_id)->where('status', 'active')->get();
        
        $qualifications = LeadQualification::where('business_id', $business->business_id)->get();
        
        // Map qualifications by service_id for easier frontend handling
        $qualificationsMap = [];
        foreach ($qualifications as $qual) {
            $qualificationsMap[$qual->service_id] = $qual->questions;
        }
        
        return response()->json([
            'status' => true, 
            'data' => [
                'services' => $services,
                'qualifications' => $qualificationsMap,
                'qualifications_list' => $qualifications
            ]
        ]);
    }
    
    public function saveQualifications(Request $request)
    {
        $request->validate([
            'business_id' => 'required|string',
            'qualifications' => 'present|array',
            'qualifications.*.service_id' => 'required|exists:services,id',
            'qualifications.*.questions' => 'nullable|string'
        ]);
        
        $businessId = $request->business_id;
        
        foreach ($request->qualifications as $qual) {
            LeadQualification::updateOrCreate(
                [
                    'business_id' => $businessId,
                    'service_id' => $qual['service_id']
                ],
                [
                    'questions' => $qual['questions'] ?? ''
                ]
            );
        }
        
        return response()->json(['status' => true, 'message' => 'Qualifications saved successfully.']);
    }

    public function destroy($id)
    {
        $qualification = LeadQualification::find($id);
        if (!$qualification) {
            return response()->json(['status' => false, 'message' => 'Not found']);
        }

        $qualification->delete();

        return response()->json(['status' => true, 'message' => 'Deleted successfully.']);
    }
}
