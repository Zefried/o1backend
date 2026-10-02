<?php

namespace App\Http\Controllers\Images;

use App\Http\Controllers\Controller;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImageController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'imageable_id' => 'required|integer',
            'imageable_type' => 'required|string',
        ]);

        $images = Image::where(
            'imageable_id',
            $validated['imageable_id']
        )
            ->where(
                'imageable_type',
                $validated['imageable_type']
            )
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $images,
        ]);
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'imageable_id' => 'required|integer',
                'imageable_type' => 'required|string',
                'images' => 'required|array|min:1',
                'images.*' => 'required|image|max:5120',
            ]);

            $imageableType = $validated['imageable_type'];
            $imageableId = $validated['imageable_id'];

            $uploaded = [];
            $duplicates = [];

            foreach ($validated['images'] as $image) {

                $imageHash = hash_file(
                    'sha256',
                    $image->getRealPath()
                );

                $existingImage = Image::where(
                    'imageable_id',
                    $imageableId
                )
                    ->where('imageable_type', $imageableType)
                    ->where('image_hash', $imageHash)
                    ->first();

                if ($existingImage) {
                    $duplicates[] =
                        $image->getClientOriginalName();

                    continue;
                }

                $extension =
                    $image->getClientOriginalExtension();

                $filename =
                    Str::uuid() . '.' . $extension;

                $path = $image->storeAs(
                    'images',
                    $filename,
                    'public'
                );

                $imageRecord = Image::create([
                    'imageable_id' => $imageableId,
                    'imageable_type' => $imageableType,
                    'image_url' => $path,
                    'image_name' =>
                    $image->getClientOriginalName(),
                    'image_hash' => $imageHash,
                    'sort_order' =>
                    Image::where('imageable_id', $imageableId)
                        ->where(
                            'imageable_type',
                            $imageableType
                        )
                        ->count(),
                    'is_primary' =>
                    Image::where('imageable_id', $imageableId)
                        ->where(
                            'imageable_type',
                            $imageableType
                        )
                        ->count() === 0,
                ]);

                $uploaded[] = $imageRecord;
            }

            return response()->json([
                'status' => true,
                'message' => 'Images processed successfully.',
                'uploaded' => $uploaded,
                'duplicates' => $duplicates,
            ], 201);
        } catch (ValidationException $e) {

            $imageErrors = [];

            foreach ($e->errors() as $field => $messages) {

                if (preg_match('/^images\.(\d+)$/', $field, $matches)) {

                    $index = (int) $matches[1];
                    $image = $request->file("images.$index");

                    $friendlyMessages = [];

                    foreach ($messages as $message) {

                        if (str_contains($message, 'must be an image')) {
                            $friendlyMessages[] =
                                'This image appears to be corrupted or is not a valid image. Please choose another image.';
                        } else {
                            $friendlyMessages[] = $message;
                        }
                    }

                    $imageErrors[] = [
                        'index' => $index,
                        'name' => $image
                            ? $image->getClientOriginalName()
                            : null,
                        'errors' => $friendlyMessages,
                    ];
                }
            }

            return response()->json([
                'status' => false,
                'message' => 'Some images could not be uploaded.',
                'errors' => $e->errors(),
                'image_errors' => $imageErrors,
            ], 422);
        } catch (Throwable $e) {

            Log::error(
                'Failed to upload images',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return response()->json([
                'status' => false,
                'message' => 'Failed to upload images.',
            ], 500);
        }
    }
}
