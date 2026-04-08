<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AddsContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AddsContentController extends Controller
{
    public function index(): JsonResponse
    {
        $items = AddsContent::query()
            ->orderByDesc('ac_id')
            ->limit(200)
            ->get()
            ->map(fn (AddsContent $row) => [
                'id' => (int) $row->ac_id,
                'image_url' => $row->ac_image_path ? Storage::disk('public')->url($row->ac_image_path) : null,
                'video_url' => $row->ac_video_path ? Storage::disk('public')->url($row->ac_video_path) : null,
                'date_created' => optional($row->ac_date_created)->toDateString(),
                'status' => (int) ($row->ac_status ?? 0),
                'created_at' => optional($row->created_at)->toDateTimeString(),
            ])
            ->values();

        return response()->json([
            'items' => $items,
        ]);
    }

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
            'ac_status' => 0,
        ]);

        return response()->json([
            'message' => 'Content saved successfully.',
            'item' => [
                'id' => (int) $row->ac_id,
                'image_url' => $imagePath ? Storage::disk('public')->url($imagePath) : null,
                'video_url' => $videoPath ? Storage::disk('public')->url($videoPath) : null,
                'date_created' => optional($row->ac_date_created)->toDateString(),
                'status' => (int) ($row->ac_status ?? 0),
                'created_at' => optional($row->created_at)->toDateTimeString(),
            ],
        ], 201);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|integer|in:0,1',
        ]);

        $row = AddsContent::query()->where('ac_id', $id)->firstOrFail();
        $row->ac_status = (int) $validated['status'];
        $row->save();

        return response()->json([
            'message' => 'Status updated.',
            'item' => [
                'id' => (int) $row->ac_id,
                'status' => (int) $row->ac_status,
            ],
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'image' => 'nullable|image|max:5120',
            'video' => 'nullable|mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/x-ms-wmv,video/webm|max:51200',
            'date_created' => 'nullable|date',
        ]);

        $row = AddsContent::query()->where('ac_id', $id)->firstOrFail();

        if ($request->hasFile('image')) {
            if ($row->ac_image_path) {
                Storage::disk('public')->delete($row->ac_image_path);
            }
            $row->ac_image_path = $request->file('image')->store('adds-content/images', 'public');
        }

        if ($request->hasFile('video')) {
            if ($row->ac_video_path) {
                Storage::disk('public')->delete($row->ac_video_path);
            }
            $row->ac_video_path = $request->file('video')->store('adds-content/videos', 'public');
        }

        if (array_key_exists('date_created', $validated)) {
            $row->ac_date_created = $validated['date_created'];
        }

        $row->save();

        return response()->json([
            'message' => 'Content updated.',
            'item' => [
                'id' => (int) $row->ac_id,
                'image_url' => $row->ac_image_path ? Storage::disk('public')->url($row->ac_image_path) : null,
                'video_url' => $row->ac_video_path ? Storage::disk('public')->url($row->ac_video_path) : null,
                'date_created' => optional($row->ac_date_created)->toDateString(),
                'status' => (int) ($row->ac_status ?? 0),
                'created_at' => optional($row->created_at)->toDateTimeString(),
            ],
        ]);
    }
}
