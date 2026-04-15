<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $query = ExpenseCategory::query();

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('name', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%');
            });
        }

        $categories = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'categories' => $categories->map(fn (ExpenseCategory $item) => $this->formatCategory($item))->values(),
            'total' => $categories->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('tbl_expense_categories', 'name')],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'integer', 'in:0,1'],
        ]);

        $category = ExpenseCategory::query()->create([
            'name' => trim((string) $validated['name']),
            'description' => trim((string) ($validated['description'] ?? '')),
            'status' => (int) ($validated['status'] ?? 1),
        ]);

        return response()->json([
            'message' => 'Expense category created successfully.',
            'category' => $this->formatCategory($category),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = ExpenseCategory::query()->find($id);
        if (! $category) {
            return response()->json(['message' => 'Expense category not found.'], 404);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('tbl_expense_categories', 'name')->ignore($category->id),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'integer', 'in:0,1'],
        ]);

        $category->name = trim((string) $validated['name']);
        $category->description = trim((string) ($validated['description'] ?? ''));
        $category->status = (int) ($validated['status'] ?? $category->status ?? 1);
        $category->save();

        return response()->json([
            'message' => 'Expense category updated successfully.',
            'category' => $this->formatCategory($category),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $category = ExpenseCategory::query()->find($id);
        if (! $category) {
            return response()->json(['message' => 'Expense category not found.'], 404);
        }

        $category->delete();

        return response()->json([
            'message' => 'Expense category deleted successfully.',
        ]);
    }

    private function formatCategory(ExpenseCategory $category): array
    {
        return [
            'id' => (int) $category->id,
            'name' => (string) ($category->name ?? ''),
            'description' => (string) ($category->description ?? ''),
            'status' => (int) ($category->status ?? 1),
            'created_at' => optional($category->created_at)->toDateTimeString(),
            'updated_at' => optional($category->updated_at)->toDateTimeString(),
        ];
    }
}

