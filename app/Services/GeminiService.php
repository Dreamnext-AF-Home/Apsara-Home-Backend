<?php

namespace App\Services;

use Illuminate\Http\Client\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class GeminiService
{
    private $apiKey;
    private $baseUrl = 'https://generativelanguage.googleapis.com/v1/models';
    private $model = 'gemini-2.0-flash-lite';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key') ?? env('GEMINI_API_KEY');
    }

    public function listAvailableModels(): array
    {
        $response = Http::get(
            "{$this->baseUrl}?key={$this->apiKey}"
        );

        if (!$response->successful()) {
            throw new \Exception('Failed to list models: ' . $response->body());
        }

        return $response->json();
    }

    public function chat(string $message, ?array $context = null, ?array $history = null): array
    {
        $cacheKey = 'gemini_response_' . md5($message . json_encode($context));

        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            $cached['from_cache'] = true;
            return $cached;
        }

        $systemPrompt = $this->buildSystemPrompt($context);

        $messages = $this->buildMessages($message, $systemPrompt, $history);

        $payload = [
            'contents' => $messages,
            'generationConfig' => [
                'temperature' => 0.5,
                'topP' => 0.8,
                'topK' => 40,
                'maxOutputTokens' => 256,
            ],
            'safetySettings' => [
                [
                    'category' => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
                ],
                [
                    'category' => 'HARM_CATEGORY_HATE_SPEECH',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
                ],
                [
                    'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
                ],
                [
                    'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE',
                ],
            ],
        ];

        $response = Http::post(
            "{$this->baseUrl}/{$this->model}:generateContent?key={$this->apiKey}",
            $payload
        );

        if (!$response->successful()) {
            throw new \Exception('Gemini API Error: ' . $response->body());
        }

        $data = $response->json();

        $result = [
            'message' => $this->extractMessage($data),
            'model' => $this->model,
            'usage' => [
                'input_tokens' => $data['usageMetadata']['promptTokenCount'] ?? 0,
                'output_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
            ],
            'raw_response' => $data,
            'from_cache' => false,
        ];

        Cache::put($cacheKey, $result, 3600);

        return $result;
    }

    private function buildSystemPrompt(?array $context = null): string
    {
        $prompt = "You are an e-commerce assistant for an online store. IMPORTANT RULES:\n1. ONLY answer questions about products, orders, pricing, shipping, and customer service\n2. If a question is NOT related to e-commerce or the store, respond with: 'I can only help with questions about our products and orders. How can I assist you with your shopping?'\n3. Keep responses SHORT and concise (under 100 words)\n4. Base answers ONLY on the provided product information\n5. Do NOT answer general knowledge questions, jokes, or off-topic requests";

        if ($context && isset($context['data'])) {
            $prompt .= "\n\nUse the following information to answer questions:\n";
            if (is_array($context['data'])) {
                foreach ($context['data'] as $item) {
                    if (is_array($item)) {
                        $prompt .= "- " . json_encode($item) . "\n";
                    } else {
                        $prompt .= "- " . $item . "\n";
                    }
                }
            } else {
                $prompt .= $context['data'];
            }
        }

        if (isset($context['instructions'])) {
            $prompt .= "\n\nAdditional Instructions:\n" . $context['instructions'];
        }

        return $prompt;
    }

    private function buildMessages(string $message, string $systemPrompt, ?array $history = null): array
    {
        $messages = [];

        // Add system prompt as the first message if needed
        $messages[] = [
            'role' => 'user',
            'parts' => [
                ['text' => "System: " . $systemPrompt]
            ],
        ];

        // Add historical messages
        if ($history && is_array($history)) {
            foreach ($history as $msg) {
                $messages[] = [
                    'role' => $msg['role'] ?? 'user',
                    'parts' => [
                        ['text' => $msg['message'] ?? $msg['content'] ?? '']
                    ],
                ];
            }
        }

        // Add current message
        $messages[] = [
            'role' => 'user',
            'parts' => [
                ['text' => $message]
            ],
        ];

        return $messages;
    }

    private function extractMessage(array $response): string
    {
        if (!isset($response['candidates']) || empty($response['candidates'])) {
            return 'No response from AI';
        }

        $candidate = $response['candidates'][0];

        if (isset($candidate['content']['parts'][0]['text'])) {
            return $candidate['content']['parts'][0]['text'];
        }

        if (isset($candidate['finishReason']) && $candidate['finishReason'] === 'SAFETY') {
            return 'I cannot provide a response to this question. Please ask about our products or orders.';
        }

        return 'Unable to generate response. Please try again.';
    }
}
