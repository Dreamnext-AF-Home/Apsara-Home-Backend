<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\GeminiService;

class GeminiController extends Controller
{
    private $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    public function listModels()
    {
        try {
            $models = $this->geminiService->listAvailableModels();
            return response()->json([
                'success' => true,
                'data' => $models,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:5000',
            'context' => 'nullable|array',
            'context.data' => 'nullable|array|max:100',
            'context.instructions' => 'nullable|string|max:2000',
            'history' => 'nullable|array|max:20',
        ]);

        try {
            $response = $this->geminiService->chat(
                $validated['message'],
                $validated['context'] ?? null,
                $validated['history'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => $response['message'],
                    'model' => $response['model'],
                    'usage' => $response['usage'],
                    'from_cache' => $response['from_cache'] ?? false,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
