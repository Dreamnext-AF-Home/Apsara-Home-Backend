<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\CloudinaryUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ExpenseController extends Controller
{
    private static array $columnCache = [];

    private function hasExpenseColumn(string $column): bool
    {
        if (array_key_exists($column, self::$columnCache)) {
            return self::$columnCache[$column];
        }

        self::$columnCache[$column] = Schema::hasColumn('tbl_expenses', $column);
        return self::$columnCache[$column];
    }

    private function buildExpenseWriteData(array $validated, ?int $createdBy = null): array
    {
        $data = [
            'category_id' => (int) $validated['category_id'],
            'amount' => (float) $validated['amount'],
            'intent' => trim((string) $validated['intent']),
            'transaction_date' => $validated['transaction_date'],
            'status' => (int) ($validated['status'] ?? 1),
        ];

        if ($this->hasExpenseColumn('sub_category_name')) {
            $data['sub_category_name'] = substr(trim((string) ($validated['sub_category_name'] ?? '')), 0, 180);
        }
        if ($this->hasExpenseColumn('invoice_url') && array_key_exists('invoice_url', $validated)) {
            $url = trim((string) ($validated['invoice_url'] ?? ''));
            $data['invoice_url'] = $url !== '' ? substr($url, 0, 2000) : null;
        }

        if ($createdBy !== null && $this->hasExpenseColumn('created_by_admin_id')) {
            $data['created_by_admin_id'] = $createdBy;
        }

        // Legacy schema compatibility: some deployments require a non-null title.
        if ($this->hasExpenseColumn('e_title')) {
            $title = $data['intent'] ?: 'Expense';
            $data['e_title'] = substr($title, 0, 180);
        }

        return $data;
    }

    private function resolveExpenseDateColumn(): ?string
    {
        foreach (['transaction_date', 'e_date', 'expense_date', 'date'] as $column) {
            if ($this->hasExpenseColumn($column)) {
                return $column;
            }
        }
        return null;
    }

    private function resolveExpenseAmountColumn(): ?string
    {
        foreach (['amount', 'e_amount', 'expense_amount'] as $column) {
            if ($this->hasExpenseColumn($column)) {
                return $column;
            }
        }
        return null;
    }

    private function resolveExpenseStatusColumn(): ?string
    {
        foreach (['status', 'e_status'] as $column) {
            if ($this->hasExpenseColumn($column)) {
                return $column;
            }
        }
        return null;
    }

    private function getPublicDiskPathFromUrl(?string $url): ?string
    {
        $value = trim((string) $url);
        if ($value === '') {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        $path = is_string($path) ? trim($path) : $value;
        if ($path === '') {
            return null;
        }

        $marker = '/storage/';
        $position = strpos($path, $marker);
        if ($position === false) {
            if (str_starts_with($path, 'storage/')) {
                return ltrim(substr($path, strlen('storage/')), '/');
            }
            return null;
        }

        $relative = substr($path, $position + strlen($marker));
        return ltrim((string) $relative, '/');
    }

    private function deletePublicInvoiceFile(?string $url): void
    {
        $relativePath = $this->getPublicDiskPathFromUrl($url);
        if (! $relativePath) {
            return;
        }

        if (Storage::disk('public')->exists($relativePath)) {
            Storage::disk('public')->delete($relativePath);
        }
    }

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'status' => 'nullable|integer|in:0,1',
        ]);

        $dateColumn = $this->resolveExpenseDateColumn();
        $amountColumn = $this->resolveExpenseAmountColumn();
        if (! $dateColumn || ! $amountColumn) {
            return response()->json([
                'message' => 'Expense summary is unavailable until the expenses table has transaction date and amount columns.',
            ], 400);
        }

        $statusColumn = $this->resolveExpenseStatusColumn();

        $query = DB::table('tbl_expenses')
            ->join('tbl_expense_categories', 'tbl_expense_categories.id', '=', 'tbl_expenses.category_id');
        $qualifiedDateColumn = 'tbl_expenses.' . $dateColumn;
        $qualifiedAmountColumn = 'tbl_expenses.' . $amountColumn;
        $qualifiedStatusColumn = $statusColumn ? 'tbl_expenses.' . $statusColumn : null;

        if (!empty($validated['from'])) {
            $query->whereDate($qualifiedDateColumn, '>=', $validated['from']);
        }
        if (!empty($validated['to'])) {
            $query->whereDate($qualifiedDateColumn, '<=', $validated['to']);
        }
        if ($qualifiedStatusColumn) {
            $status = array_key_exists('status', $validated) ? (int) $validated['status'] : 1;
            $query->where($qualifiedStatusColumn, '=', $status);
        }

        $count = (clone $query)->count();
        $total = (float) ((clone $query)->sum($qualifiedAmountColumn) ?? 0);

        return response()->json([
            'count' => (int) $count,
            'total_amount' => $total,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));
        $categoryId = (int) $request->query('category_id', 0);
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(10, (int) $request->query('per_page', 10));
        $perPage = min($perPage, 100);

        $query = Expense::query()
            ->select([
                'tbl_expenses.*',
                'tbl_expense_categories.name as category_name',
                'tbl_expense_categories.status as category_status',
            ])
            ->join('tbl_expense_categories', 'tbl_expense_categories.id', '=', 'tbl_expenses.category_id')
            ->orderByDesc('tbl_expenses.transaction_date');

        if ($search !== '') {
            $hasSubCategoryName = $this->hasExpenseColumn('sub_category_name');
            $query->where(function ($builder) use ($search, $hasSubCategoryName) {
                $builder
                    ->where('tbl_expenses.intent', 'like', '%' . $search . '%')
                    ->orWhere('tbl_expense_categories.name', 'like', '%' . $search . '%');

                if ($hasSubCategoryName) {
                    $builder->orWhere('tbl_expenses.sub_category_name', 'like', '%' . $search . '%');
                }
            });
        }

        if ($categoryId > 0) {
            $query->where('tbl_expenses.category_id', '=', $categoryId);
        }

        if ($dateFrom !== '') {
            $query->whereDate('tbl_expenses.transaction_date', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereDate('tbl_expenses.transaction_date', '<=', $dateTo);
        }

        $total = (clone $query)->count();
        $filteredTotalAmount = (float) ((clone $query)->sum('tbl_expenses.amount') ?? 0);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $rows = (clone $query)
            ->forPage($page, $perPage)
            ->get();

        return response()->json([
            'expenses' => $rows->map(function ($row) {
                return $this->formatExpenseRow($row);
            })->values(),
            'total' => $total,
            'filtered_total_amount' => $filteredTotalAmount,
            'current_page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => 'required|integer|exists:tbl_expense_categories,id',
            'sub_category_name' => 'nullable|string|max:180',
            'invoice_url' => 'nullable|string|max:2000',
            'invoice_file' => 'nullable|file|image|max:5120',
            'remove_invoice' => 'nullable|boolean',
            'amount' => 'required|numeric|min:0|max:999999999.99',
            'intent' => 'required|string|max:500',
            'transaction_date' => 'required|date_format:Y-m-d',
            'status' => 'nullable|integer|in:0,1',
        ]);
        if ($request->hasFile('invoice_file') && $this->hasExpenseColumn('invoice_url')) {
            try {
                $upload = app(CloudinaryUploadService::class)->uploadImage(
                    $request->file('invoice_file'),
                    'afhome/expenses/invoices'
                );
                $validated['invoice_url'] = (string) ($upload['secure_url'] ?? '');
            } catch (RuntimeException $exception) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }
        }

        $actor = $request->user();
        $createdBy = $actor && isset($actor->id) ? (int) $actor->id : null;

        $expense = null;

        DB::transaction(function () use ($validated, $createdBy, &$expense) {
            $expense = Expense::query()->create($this->buildExpenseWriteData($validated, $createdBy));
        });

        $category = ExpenseCategory::query()->find((int) $validated['category_id']);

        return response()->json([
            'message' => 'Expense created successfully.',
            'expense' => $this->formatExpense($expense, $category),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $expense = Expense::query()->find($id);
        if (! $expense) {
            return response()->json(['message' => 'Expense not found.'], 404);
        }

        $validated = $request->validate([
            'category_id' => 'required|integer|exists:tbl_expense_categories,id',
            'sub_category_name' => 'nullable|string|max:180',
            'invoice_url' => 'nullable|string|max:2000',
            'invoice_file' => 'nullable|file|image|max:5120',
            'remove_invoice' => 'nullable|boolean',
            'amount' => 'required|numeric|min:0|max:999999999.99',
            'intent' => 'required|string|max:500',
            'transaction_date' => 'required|date_format:Y-m-d',
            'status' => 'nullable|integer|in:0,1',
        ]);
        $oldInvoiceUrl = $expense->invoice_url ? (string) $expense->invoice_url : null;
        $removeInvoice = (bool) ($validated['remove_invoice'] ?? false);
        unset($validated['remove_invoice']);

        if ($removeInvoice && $this->hasExpenseColumn('invoice_url')) {
            $validated['invoice_url'] = null;
        }

        if ($request->hasFile('invoice_file') && $this->hasExpenseColumn('invoice_url')) {
            try {
                $upload = app(CloudinaryUploadService::class)->uploadImage(
                    $request->file('invoice_file'),
                    'afhome/expenses/invoices'
                );
                $validated['invoice_url'] = (string) ($upload['secure_url'] ?? '');
            } catch (RuntimeException $exception) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 422);
            }
        }

        $writeData = $this->buildExpenseWriteData($validated, null);
        $newInvoiceUrl = array_key_exists('invoice_url', $writeData)
            ? ($writeData['invoice_url'] ?: null)
            : $oldInvoiceUrl;

        foreach ($writeData as $key => $value) {
            $expense->{$key} = $value;
        }
        $expense->save();

        if (
            $this->hasExpenseColumn('invoice_url')
            && $oldInvoiceUrl
            && $newInvoiceUrl !== $oldInvoiceUrl
        ) {
            $this->deletePublicInvoiceFile($oldInvoiceUrl);
        }

        $category = ExpenseCategory::query()->find((int) $validated['category_id']);

        return response()->json([
            'message' => 'Expense updated successfully.',
            'expense' => $this->formatExpense($expense, $category),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $expense = Expense::query()->find($id);
        if (! $expense) {
            return response()->json(['message' => 'Expense not found.'], 404);
        }

        $expense->delete();

        return response()->json([
            'message' => 'Expense deleted successfully.',
        ]);
    }

    private function formatExpense(Expense $expense, ?ExpenseCategory $category): array
    {
        return [
            'id' => (int) $expense->id,
            'category' => [
                'id' => (int) ($category?->id ?? $expense->category_id),
                'name' => (string) ($category?->name ?? ''),
                'status' => (int) ($category?->status ?? 1),
            ],
            'category_id' => (int) $expense->category_id,
            'sub_category_name' => (string) ($expense->sub_category_name ?? ''),
            'invoice_url' => $expense->invoice_url ? (string) $expense->invoice_url : null,
            'amount' => (float) $expense->amount,
            'intent' => (string) ($expense->intent ?? ''),
            'transaction_date' => (string) ($expense->transaction_date?->format('Y-m-d') ?? ''),
            'status' => (int) ($expense->status ?? 1),
            'created_by_admin_id' => $expense->created_by_admin_id ? (int) $expense->created_by_admin_id : null,
            'created_at' => optional($expense->created_at)->toDateTimeString(),
            'updated_at' => optional($expense->updated_at)->toDateTimeString(),
        ];
    }

    private function formatExpenseRow($row): array
    {
        $resolvedId = $row->id
            ?? $row->expense_id
            ?? $row->exp_id
            ?? $row->expenses_id
            ?? null;

        return [
            'id' => (int) ($resolvedId ?? 0),
            'category' => [
                'id' => (int) ($row->category_id ?? 0),
                'name' => (string) ($row->category_name ?? ''),
                'status' => (int) ($row->category_status ?? 1),
            ],
            'category_id' => (int) ($row->category_id ?? 0),
            'sub_category_name' => (string) ($row->sub_category_name ?? ''),
            'invoice_url' => !empty($row->invoice_url) ? (string) $row->invoice_url : null,
            'amount' => (float) ($row->amount ?? 0),
            'intent' => (string) ($row->intent ?? ''),
            'transaction_date' => (string) ($row->transaction_date ?? ''),
            'status' => (int) ($row->status ?? 1),
            'created_by_admin_id' => $row->created_by_admin_id ? (int) $row->created_by_admin_id : null,
            'created_at' => optional($row->created_at)->toDateTimeString(),
            'updated_at' => optional($row->updated_at)->toDateTimeString(),
        ];
    }
}
