<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AddsContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AddsContentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => 'nullable|image|max:5120',
            'video' => 'nullable|mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv,video/webm|max:51200',
            'date_created' => 'nullable|date',
        ]);

        $imagePath = null;
        $videoPath = null;

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('adds-content/images', 'public');
        }

        if ($request->hasFile('video')) {
            $videoPath = $request->file('video')->store('adds-content/videos', 'public');
        }

        $row = AddsContent::create([
            'ac_image_path' => $imagePath,
            'ac_video_path' => $videoPath,
            'ac_date_created' => $validated['date_created'] ?? null,
        ]);

        return response()->json([
            'message' => 'Content saved successfully.',
            'item' => [
                'id' => (int) $row->ac_id,
                'image_url' => $imagePath ? Storage::disk('public')->url($imagePath) : null,
                'video_url' => $videoPath ? Storage::disk('public')->url($videoPath) : null,
                'date_created' => optional($row->ac_date_created)->toDateString(),
                'created_at' => optional($row->created_at)->toDateTimeString(),
            ],
        ], 201);
    }
}
