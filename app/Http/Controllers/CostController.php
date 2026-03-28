<?php

namespace App\Http\Controllers;

use App\Models\Cost;
use App\Models\User;
use App\Models\Income;
use App\Models\OpeningDay;
use App\Models\CostCategory;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use App\Services\GoogleVisionService;
use App\Services\CostInvoiceParserService;

class CostController extends Controller
{
    /**
     * Show form to create a new cost.
     */
    public function create()
    {
        $user = Auth::user();

        if (is_null($user->created_by)) {
            $children = User::where('created_by', $user->id)->pluck('id');
            $visibleUserIds = $children->isEmpty()
                ? collect([$user->id])
                : $children->push($user->id);
        } else {
            $visibleUserIds = collect([$user->id, $user->created_by]);
        }

        $categories = CostCategory::with('user')
            ->where(function ($q) use ($visibleUserIds) {
                $q->whereIn('user_id', $visibleUserIds)
                  ->orWhereNull('user_id');
            })
            ->orderBy('name')
            ->get();

        return view('frontend.costs.create', compact('categories'));
    }

    /**
     * Display a single cost.
     */
    public function show(Cost $cost)
    {
        if ($cost->user_id !== Auth::id()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return view('frontend.costs.show', compact('cost'));
    }

    /**
     * Persist a newly created cost.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier'        => ['required', 'string', 'max:255'],
            'cost_identifier' => ['nullable', 'string', 'max:255'],
            'amount'          => ['required', 'numeric', 'min:0'],
            'due_date'        => ['required', 'date'],
            'category_id'     => ['required', 'exists:cost_categories,id'],
            'other_category'  => ['nullable', 'string', 'max:255'],
        ]);

        $data['amount']   = $this->sanitizeAmount($data['amount']);
        $data['due_date'] = $request->date('due_date');
        $data['user_id']  = Auth::id();

        Cost::create($data);

        return redirect()
            ->route('costs.index')
            ->with('success', 'Costo aggiunto!');
    }

    /**
     * Display a listing of costs.
     */
    public function index()
    {
        $user = Auth::user();

        if (is_null($user->created_by)) {
            $children = User::where('created_by', $user->id)->pluck('id');
            $visibleUserIds = $children->isEmpty()
                ? collect([$user->id])
                : $children->push($user->id);
        } else {
            $visibleUserIds = collect([$user->id, $user->created_by]);
        }

        $categories = CostCategory::with('user')
            ->where(function ($q) use ($visibleUserIds) {
                $q->whereIn('user_id', $visibleUserIds)
                  ->orWhereNull('user_id');
            })
            ->orderBy('name')
            ->get();

        $costs = Cost::with(['category', 'user'])
            ->whereIn('user_id', $visibleUserIds)
            ->orderBy('due_date', 'desc')
            ->get();

        return view('frontend.costs.index', compact('categories', 'costs'));
    }

    /**
     * Show the form for editing the specified cost.
     */
    public function edit(Cost $cost)
    {
        if ($cost->user_id !== Auth::id()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $user = Auth::user();

        if (is_null($user->created_by)) {
            $visibleUserIds = User::where('created_by', $user->id)
                ->pluck('id')
                ->push($user->id)
                ->unique();
        } else {
            $visibleUserIds = collect([$user->id, $user->created_by])->unique();
        }

        $categories = CostCategory::with('user')
            ->where(function ($q) use ($visibleUserIds) {
                $q->whereIn('user_id', $visibleUserIds)
                  ->orWhereNull('user_id');
            })
            ->orderBy('name')
            ->get();

        return view('frontend.costs.create', compact('cost', 'categories'));
    }

    /**
     * Update the specified cost in storage.
     */
    public function update(Request $request, Cost $cost)
    {
        if ($cost->user_id !== Auth::id()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $data = $request->validate([
            'supplier'        => ['required', 'string', 'max:255'],
            'cost_identifier' => ['nullable', 'string', 'max:255'],
            'amount'          => ['required', 'numeric', 'min:0'],
            'due_date'        => ['required', 'date'],
            'category_id'     => ['required', 'exists:cost_categories,id'],
            'other_category'  => ['nullable', 'string', 'max:255'],
        ]);

        $data['amount']   = $this->sanitizeAmount($data['amount']);
        $data['due_date'] = $request->date('due_date');

        $cost->update($data);

        return redirect()
            ->route('costs.index')
            ->with('success', 'Costo aggiornato con successo!');
    }

    /**
     * Remove the specified cost from storage.
     */
    public function destroy(Cost $cost)
    {
        if ($cost->user_id !== Auth::id()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $cost->delete();

        return redirect()
            ->route('costs.index')
            ->with('success', 'Costo eliminato con successo!');
    }

    /**
     * Extract cost invoice via Google Vision + AI parser.
     */
    public function extractInvoice(Request $request)
    {
        $request->validate([
            'invoice' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:20480',
        ]);

        $file     = $request->file('invoice');
        $mimeType = $file->getMimeType();
        $path     = $file->store('temp/cost-invoices', 'local');
        $fullPath = Storage::disk('local')->path($path);

        try {
            $rawText = app(GoogleVisionService::class)->extractText($fullPath, $mimeType);

            if (empty(trim($rawText))) {
                return response()->json([
                    'error' => 'Nessun testo rilevato. Assicurati che il documento sia leggibile e non ruotato.',
                ], 422);
            }

            $extracted = app(CostInvoiceParserService::class)->parse($rawText);
            $extracted['raw_text'] = $rawText;

            $user = Auth::user();

            $categories = $this->visibleCategoriesForCurrentUser()
                ->map(fn ($c) => [
                    'id'   => $c->id,
                    'name' => $c->name,
                ])
                ->values();

            $items = collect($extracted['items'] ?? [])
                ->map(function ($item) use ($categories, $user) {
                    $amount = $this->sanitizeAmount($item['amount'] ?? 0);

                    $categoryName = trim($item['category_name'] ?? 'Other');
                    $suggestedCategoryId = $this->guessCategoryIdByName($categoryName, $categories);

                    if (!$suggestedCategoryId) {
                        $fallback = CostCategory::firstOrCreate(
                            ['name' => 'Other', 'user_id' => $user->id],
                            ['name' => 'Other', 'user_id' => $user->id]
                        );

                        $suggestedCategoryId = $fallback->id;
                        $categoryName = $fallback->name;
                    }

                    return [
                        'description'           => trim($item['description'] ?? ''),
                        'amount'                => $amount,
                        'category_name'         => $categoryName,
                        'suggested_category_id' => $suggestedCategoryId,
                        'is_duplicate'          => false,
                        'duplicate_reason'      => null,
                    ];
                })
                ->filter(fn ($item) => $item['description'] !== '' && $item['amount'] > 0)
                ->values()
                ->all();

            return response()->json([
                'supplier_name' => $extracted['supplier_name'] ?? '',
                'invoice_code'  => $extracted['invoice_code'] ?? '',
                'date'          => $extracted['date'] ?? now()->format('Y-m-d'),
                'items'         => $items,
                'categories'    => $categories,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Cost invoice extraction error', ['msg' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * Process confirmed bulk cost rows.
     */
    public function processInvoice(Request $request)
    {
        $data = $request->validate([
            'supplier_name'          => ['required', 'string', 'max:255'],
            'invoice_code'           => ['nullable', 'string', 'max:255'],
            'date'                   => ['required', 'date'],
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.description'    => ['required', 'string', 'max:255'],
            'items.*.amount'         => ['required', 'numeric', 'min:0.01'],
            'items.*.category_id'    => ['required', 'exists:cost_categories,id'],
            'items.*.other_category' => ['nullable', 'string', 'max:255'],
            'items.*.skip'           => ['nullable', 'boolean'],
        ]);

        $user     = Auth::user();
        $inserted = 0;
        $skipped  = 0;
        $details  = [];

        DB::transaction(function () use ($data, $user, &$inserted, &$skipped, &$details) {
            foreach ($data['items'] as $row) {
                if (!empty($row['skip'])) {
                    $skipped++;
                    $details[] = [
                        'description' => $row['description'],
                        'action'      => 'skipped_by_user',
                    ];
                    continue;
                }

                $payload = [
                    'supplier'        => trim($data['supplier_name']),
                    'cost_identifier' => trim((string) ($data['invoice_code'] ?? '')),
                    'amount'          => $this->sanitizeAmount($row['amount']),
                    'due_date'        => $data['date'],
                    'category_id'     => (int) $row['category_id'],
                    'other_category'  => !empty($row['other_category']) ? trim($row['other_category']) : $row['description'],
                    'user_id'         => $user->id,
                ];

                $duplicate = $this->findExactDuplicateCost($payload);

                if ($duplicate) {
                    $skipped++;
                    $details[] = [
                        'description' => $row['description'],
                        'action'      => 'duplicate_skipped',
                        'amount'      => $payload['amount'],
                    ];
                    continue;
                }

                Cost::create($payload);

                $inserted++;
                $details[] = [
                    'description' => $row['description'],
                    'action'      => 'inserted',
                    'amount'      => $payload['amount'],
                ];
            }
        });

        return response()->json([
            'success' => true,
            'message' => "{$inserted} costi inseriti, {$skipped} saltati.",
            'summary' => [
                'inserted' => $inserted,
                'skipped'  => $skipped,
            ],
            'details' => $details,
        ]);
    }

    public function dashboard(Request $request)
    {
        $user = Auth::user();

        if (is_null($user->created_by)) {
            $children = User::where('created_by', $user->id)->pluck('id');
            $visibleUserIds = $children->isEmpty()
                ? collect([$user->id])
                : $children->push($user->id);
        } else {
            $visibleUserIds = collect([$user->id, $user->created_by]);
        }

        $year     = (int) $request->query('y', now()->year);
        $month    = (int) $request->query('m', now()->month);
        $lastYear = $year - 1;

        $categories = CostCategory::with('user')
            ->where(function ($q) use ($visibleUserIds) {
                $q->whereIn('user_id', $visibleUserIds)
                  ->orWhereNull('user_id');
            })
            ->orderBy('name')
            ->get();

        $availableYears = Cost::whereIn('user_id', $visibleUserIds)
            ->selectRaw('YEAR(due_date) as year')
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year');

        $raw = Cost::whereIn('user_id', $visibleUserIds)
            ->whereYear('due_date', $year)
            ->whereMonth('due_date', $month)
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->pluck('total', 'category_id');

        $costsThisYear = Cost::whereIn('user_id', $visibleUserIds)
            ->whereYear('due_date', $year)
            ->selectRaw('MONTH(due_date) as month, SUM(amount) as total')
            ->groupBy('month')
            ->pluck('total', 'month');

        $costsLastYear = Cost::whereIn('user_id', $visibleUserIds)
            ->whereYear('due_date', $lastYear)
            ->selectRaw('MONTH(due_date) as month, SUM(amount) as total')
            ->groupBy('month')
            ->pluck('total', 'month');

        $totalCostYear     = $costsThisYear->sum();
        $totalCostLastYear = $costsLastYear->sum();

        $incomeThisYearMonthly = [];
        $incomeLastYearMonthly = [];
        $netByMonth            = [];

        for ($m = 1; $m <= 12; $m++) {
            $i1 = Income::whereIn('user_id', $visibleUserIds)
                ->whereYear('date', $year)
                ->whereMonth('date', $m)
                ->sum('amount');

            $i2 = Income::whereIn('user_id', $visibleUserIds)
                ->whereYear('date', $lastYear)
                ->whereMonth('date', $m)
                ->sum('amount');

            $incomeThisYearMonthly[$m] = (float) $i1;
            $incomeLastYearMonthly[$m] = (float) $i2;
            $netByMonth[$m]            = (float) $i1 - (float) ($costsThisYear[$m] ?? 0);
        }

        $totalIncomeYear     = array_sum($incomeThisYearMonthly);
        $totalIncomeLastYear = array_sum($incomeLastYearMonthly);
        $netYear             = $totalIncomeYear - $totalCostYear;
        $netLastYear         = $totalIncomeLastYear - $totalCostLastYear;

        $bestNet    = max($netByMonth);
        $worstNet   = min($netByMonth);
        $bestMonth  = array_search($bestNet, $netByMonth, true);
        $worstMonth = array_search($worstNet, $netByMonth, true);

        if (count(array_unique($netByMonth)) === 1) {
            $worstMonth = null;
            $worstNet   = $bestNet;
        }

        $incomeThisMonth    = $incomeThisYearMonthly[$month] ?? 0;
        $incomeLastYearSame = $incomeLastYearMonthly[$month] ?? 0;

        $openingDaysThisYear = OpeningDay::where('user_id', $user->id)
            ->where('year', $year)
            ->pluck('days', 'month');

        $openingDaysLastYear = OpeningDay::where('user_id', $user->id)
            ->where('year', $lastYear)
            ->pluck('days', 'month');

        $bepThisYear = [];
        $bepLastYear = [];
        for ($m = 1; $m <= 12; $m++) {
            $d1 = (int) ($openingDaysThisYear[$m] ?? 0);
            $d2 = (int) ($openingDaysLastYear[$m] ?? 0);
            $c1 = (float) ($costsThisYear[$m] ?? 0);
            $c2 = (float) ($costsLastYear[$m] ?? 0);
            $bepThisYear[$m] = $d1 > 0 ? $c1 / $d1 : 0.0;
            $bepLastYear[$m] = $d2 > 0 ? $c2 / $d2 : 0.0;
        }

        $sumDaysThisYear     = array_sum($openingDaysThisYear->toArray());
        $sumDaysLastYear     = array_sum($openingDaysLastYear->toArray());
        $overallBepThisYear  = $sumDaysThisYear > 0 ? ($totalCostYear / $sumDaysThisYear) : 0.0;
        $overallBepLastYear  = $sumDaysLastYear > 0 ? ($totalCostLastYear / $sumDaysLastYear) : 0.0;

        return view('frontend.costs.dashboard', compact(
            'availableYears',
            'year',
            'month',
            'lastYear',
            'categories',
            'raw',
            'incomeThisMonth',
            'incomeLastYearSame',
            'costsThisYear',
            'costsLastYear',
            'netByMonth',
            'incomeThisYearMonthly',
            'incomeLastYearMonthly',
            'totalCostYear',
            'totalIncomeYear',
            'netYear',
            'totalCostLastYear',
            'totalIncomeLastYear',
            'netLastYear',
            'bestMonth',
            'bestNet',
            'worstMonth',
            'worstNet',
            'openingDaysThisYear',
            'openingDaysLastYear',
            'bepThisYear',
            'bepLastYear',
            'sumDaysThisYear',
            'sumDaysLastYear',
            'overallBepThisYear',
            'overallBepLastYear'
        ));
    }

    /**
     * AJAX: save one month of opening days.
     */
    public function saveOpeningDays(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'year'  => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'days'  => ['nullable', 'integer', 'between:0,31'],
        ]);

        OpeningDay::updateOrCreate(
            [
                'user_id' => $user->id,
                'year'    => (int) $data['year'],
                'month'   => (int) $data['month'],
            ],
            ['days' => (int) ($data['days'] ?? 0)]
        );

        return response()->json(['ok' => true]);
    }

    private function sanitizeAmount(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return round((float) $value, 2);
        }

        $str = trim((string) $value);
        $str = preg_replace('/[€$£\s]/u', '', $str);

        if (str_contains($str, ',') && !str_contains($str, '.')) {
            $str = str_replace(',', '.', $str);
        } elseif (str_contains($str, ',') && str_contains($str, '.')) {
            $lastDot   = strrpos($str, '.');
            $lastComma = strrpos($str, ',');

            if ($lastComma > $lastDot) {
                $str = str_replace('.', '', $str);
                $str = str_replace(',', '.', $str);
            } else {
                $str = str_replace(',', '', $str);
            }
        }

        return round((float) $str, 2);
    }

    private function visibleCategoriesForCurrentUser()
    {
        $user = Auth::user();

        if (is_null($user->created_by)) {
            $visibleUserIds = User::where('created_by', $user->id)
                ->pluck('id')
                ->push($user->id)
                ->unique();
        } else {
            $visibleUserIds = collect([$user->id, $user->created_by])->unique();
        }

        return CostCategory::where(function ($q) use ($visibleUserIds) {
                $q->whereIn('user_id', $visibleUserIds)
                  ->orWhereNull('user_id');
            })
            ->orderBy('name')
            ->get();
    }

    private function guessCategoryIdByName(string $name, $categories): ?int
    {
        $needle = $this->normalizeText($name);

        if ($needle === '') {
            return null;
        }

        $aliases = [
            'electricity' => ['electricity', 'power', 'energia', 'electric', 'utility'],
            'packaging'   => ['packaging', 'package', 'boxes', 'bags', 'imballaggi', 'pack'],
            'rent'        => ['rent', 'lease', 'affitto', 'rental'],
            'transport'   => ['transport', 'delivery', 'fuel', 'shipping', 'spedizione', 'trasporto'],
            'cleaning'    => ['cleaning', 'clean', 'pulizia'],
            'maintenance' => ['maintenance', 'repair', 'service', 'fix', 'manutenzione'],
            'salaries'    => ['salary', 'salaries', 'payroll', 'stipendi'],
            'ingredients' => ['ingredient', 'ingredients', 'food', 'flour', 'sugar', 'milk', 'butter', 'farina', 'zucchero'],
            'utilities'   => ['utilities', 'internet', 'phone', 'telefono'],
            'other'       => ['other', 'misc', 'miscellaneous', 'various', 'altro'],
        ];

        foreach ($categories as $cat) {
            $catName = $this->normalizeText($cat['name']);

            if ($catName === $needle) {
                return $cat['id'];
            }
        }

        foreach ($categories as $cat) {
            $catName = $this->normalizeText($cat['name']);

            if (str_contains($catName, $needle) || str_contains($needle, $catName)) {
                return $cat['id'];
            }
        }

        foreach ($aliases as $group => $words) {
            foreach ($words as $word) {
                $word = $this->normalizeText($word);

                if (str_contains($needle, $word) || str_contains($word, $needle)) {
                    foreach ($categories as $cat) {
                        $catName = $this->normalizeText($cat['name']);
                        if (str_contains($catName, $group) || str_contains($group, $catName)) {
                            return $cat['id'];
                        }
                    }
                }
            }
        }

        return null;
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value);
        $value = preg_replace('/[^\p{L}0-9\s]/u', '', $value);
        return trim($value);
    }

    private function findExactDuplicateCost(array $payload): ?Cost
    {
        return Cost::where('user_id', $payload['user_id'])
            ->where('supplier', $payload['supplier'])
            ->where('cost_identifier', $payload['cost_identifier'])
            ->where('amount', $payload['amount'])
            ->whereDate('due_date', $payload['due_date'])
            ->where('category_id', $payload['category_id'])
            ->first();
    }
}