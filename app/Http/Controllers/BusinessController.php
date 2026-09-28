<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class BusinessController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('category')->where('role', 'business');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $businesses = $query->latest()->get();

        return response()->json(['status' => true, 'data' => $businesses]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|unique:users,phone',
            'bio' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'password' => 'required|string|min:6',
        ]);

        // Generate a unique business ID
        $businessId = 'BUS-' . strtoupper(Str::random(8));
        
        // Ensure uniqueness just in case (though highly unlikely to collide)
        while (User::where('business_id', $businessId)->exists()) {
            $businessId = 'BUS-' . strtoupper(Str::random(8));
        }

        $business = User::create([
            'business_id' => $businessId,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'bio' => $validated['bio'],
            'category_id' => $validated['category_id'] ?? null,
            'password' => Hash::make($validated['password']),
            'role' => 'business',
        ]);

        return response()->json(['status' => true, 'data' => $business]);
    }

    public function update(Request $request, $id)
    {
        $business = User::where('role', 'business')->findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$id,
            'phone' => 'required|string|unique:users,phone,'.$id,
            'bio' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
        ]);

        $business->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'bio' => $validated['bio'] ?? null,
            'category_id' => $validated['category_id'] ?? $business->category_id,
        ]);

        return response()->json(['status' => true, 'data' => $business]);
    }

    public function destroy($id)
    {
        $business = User::where('role', 'business')->findOrFail($id);
        $business->delete();

        return response()->json(['status' => true, 'message' => 'Business deleted successfully']);
    }
}
