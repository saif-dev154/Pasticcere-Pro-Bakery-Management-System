<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Ingredient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\Recipe;
use App\Services\GoogleVisionService;
use App\Services\InvoiceParserService;
use Illuminate\Support\Facades\Storage;

class IngredientController extends Controller
{
    public function index()
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

        $ingredients = Ingredient::with('recipe')
            ->whereIn('user_id', $visibleUserIds)
            ->get();

        $ingredientsForJs = $ingredients->map(fn($i) => [
            'id'               => $i->id,
            'name'             => $i->ingredient_name,
            'additional_names' => $i->additional_names ?? [],
        ])->values();

        return view('frontend.ingredients.index', compact('ingredients', 'ingredientsForJs'));
    }

    public function create()
    {
        return view('frontend.ingredients.create');
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'ingredient_name' => ['required', 'string', 'max:255'],
            'price_per_kg'    => ['required', 'numeric', 'min:0'],
        ]);

        $pricePerKg = $this->sanitizePrice($data['price_per_kg']);

        $rawAliases = $request->input('additional_names_raw', '');
        $aliases    = $this->parseAliasesRaw($rawAliases);

        $matchedIngredient = $this->findMatchingIngredientFromInputs(
            $data['ingredient_name'],
            $aliases,
            $user->id
        );

        if ($matchedIngredient) {
            $mergedAliases = $this->mergeNamesIntoAliases(
                $matchedIngredient->ingredient_name,
                $matchedIngredient->additional_names ?? [],
                $data['ingredient_name'],
                $aliases
            );

            $matchedIngredient->update([
                'price_per_kg'     => $pricePerKg,
                'additional_names' => $mergedAliases,
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success'    => true,
                    'action'     => 'updated',
                    'message'    => 'Ingredient updated successfully.',
                    'ingredient' => $matchedIngredient->fresh(),
                ], 200);
            }

            return back()->with('success', 'Ingrediente già esistente: prezzo aggiornato con successo.');
        }

        $ingredient = Ingredient::create([
            'ingredient_name'   => $this->cleanDisplayName($data['ingredient_name']),
            'price_per_kg'      => $pricePerKg,
            'user_id'           => $user->id,
            'additional_names'  => $this->normalizeAliasArrayForStorage($aliases),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success'    => true,
                'action'     => 'created',
                'message'    => 'Ingredient created successfully.',
                'ingredient' => $ingredient,
            ], 201);
        }

        return back()->with('success', 'Ingrediente salvato con successo.');
    }

    public function show(Ingredient $ingredient)
    {
        abort_unless($ingredient->user_id === Auth::id(), 403);
        return view('frontend.ingredients.show', compact('ingredient'));
    }

    public function edit(Ingredient $ingredient)
    {
        abort_unless($ingredient->user_id === Auth::id(), 403);
        return view('frontend.ingredients.create', compact('ingredient'));
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        $user = Auth::user();
        abort_unless($ingredient->user_id === $user->id, 403);

        $data = $request->validate([
            'ingredient_name' => ['required', 'string', 'max:255'],
            'price_per_kg'    => ['required', 'numeric', 'min:0'],
        ]);

        $pricePerKg = $this->sanitizePrice($data['price_per_kg']);
$aliases = $this->parseAliasesRaw($request->input('additional_names_raw', ''));
        $conflictingIngredient = $this->findMatchingIngredientFromInputs(
            $data['ingredient_name'],
            $aliases,
            $user->id,
            $ingredient->id
        );

        DB::transaction(function () use (
            $ingredient,
            $data,
            $pricePerKg,
            $aliases,
            $conflictingIngredient,
            $user
        ) {
            if ($conflictingIngredient) {
                $mergedAliases = $this->mergeNamesIntoAliases(
                    $conflictingIngredient->ingredient_name,
                    $conflictingIngredient->additional_names ?? [],
                    $data['ingredient_name'],
                    $aliases,
                    $ingredient->ingredient_name,
                    $ingredient->additional_names ?? []
                );

                $conflictingIngredient->update([
                    'price_per_kg'      => $pricePerKg,
                    'additional_names'  => $mergedAliases,
                    'last_invoice_date' => $ingredient->last_invoice_date,
                    'last_invoice_code' => $ingredient->last_invoice_code,
                ]);

                if ($ingredient->recipe_id) {
                    Recipe::where('id', $ingredient->recipe_id)
                        ->update(['production_cost_per_kg' => $pricePerKg]);
                }

                $visited = [];
                $this->cascadeFromIngredient($conflictingIngredient, $visited);

                $ingredient->delete();
            } else {
                $ingredient->update([
                    'ingredient_name'   => $this->cleanDisplayName($data['ingredient_name']),
                    'price_per_kg'      => $pricePerKg,
                    'additional_names'  => $this->normalizeAliasArrayForStorage($aliases),
                ]);

                if ($ingredient->recipe_id) {
                    Recipe::where('id', $ingredient->recipe_id)
                        ->update(['production_cost_per_kg' => $pricePerKg]);
                }

                $visited = [];
                $this->cascadeFromIngredient($ingredient, $visited);
            }
        });

        return redirect()
            ->route('ingredients.index')
            ->with('success', 'Ingrediente aggiornato correttamente.');
    }

    public function destroy(Ingredient $ingredient)
    {
        abort_unless($ingredient->user_id === Auth::id(), 403);

        $ingredient->recipe()->dissociate();
        $ingredient->delete();

        return redirect()
            ->route('ingredients.index')
            ->with('success', 'Ingrediente eliminato con successo!');
    }

    public function extractInvoice(Request $request)
    {
        $request->validate([
            'invoice' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:20480',
        ]);

        $file     = $request->file('invoice');
        $mimeType = $file->getMimeType();
        $path     = $file->store('temp/invoices', 'local');
        $fullPath = Storage::disk('local')->path($path);

        try {
            $rawText = app(GoogleVisionService::class)->extractText($fullPath, $mimeType);

            if (empty(trim($rawText))) {
                return response()->json([
                    'error' => 'Nessun testo rilevato. Assicurati che il documento sia leggibile e non ruotato.',
                ], 422);
            }

            $extracted             = app(InvoiceParserService::class)->parse($rawText);
            $extracted['raw_text'] = $rawText;

            if (!empty($extracted['items'])) {
                foreach ($extracted['items'] as &$item) {
                    if (isset($item['price_per_kg'])) {
                        $item['price_per_kg'] = $this->sanitizePrice($item['price_per_kg']);
                    }
                    if (isset($item['original_price'])) {
                        $item['original_price'] = $this->sanitizePrice($item['original_price']);
                    }
                }
                unset($item);
            }

            return response()->json($extracted);

        } catch (\Throwable $e) {
            \Log::error('Invoice extraction error', ['msg' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    public function processInvoice(Request $request)
    {
        $data = $request->validate([
            'supplier_name'        => 'nullable|string|max:255',
            'invoice_code'         => 'nullable|string|max:100',
            'date'                 => 'nullable|date',
            'items'                => 'required|array|min:1',
            'items.*.name'         => 'required|string|max:255',
            'items.*.price_per_kg' => 'required|numeric|min:0.0001|max:999999',
            'items.*.line_total'   => 'nullable|numeric|min:0',
        ]);

        $user       = Auth::user();
        $created    = 0;
        $updated    = 0;
        $details    = [];
        $grandTotal = 0;
        $costEntry  = null;

        DB::transaction(function () use ($data, $user, &$created, &$updated, &$details, &$grandTotal, &$costEntry) {
            foreach ($data['items'] as $item) {
                $name = trim($item['name']);

                $splitNames = $this->splitIngredientTokens($name);
                $mainName   = $splitNames[0] ?? $name;
                $aliases    = array_slice($splitNames, 1);

                $priceKg   = $this->sanitizePrice($item['price_per_kg']);
                $lineTotal = $this->sanitizePrice($item['line_total'] ?? 0);
                $grandTotal += $lineTotal;

                $existing = $this->findMatchingIngredientFromInputs($mainName, $aliases, $user->id);

                if ($existing) {
                    $mergedAliases = $this->mergeNamesIntoAliases(
                        $existing->ingredient_name,
                        $existing->additional_names ?? [],
                        $mainName,
                        $aliases
                    );

                    $existing->update([
                        'price_per_kg'      => $priceKg,
                        'additional_names'  => $mergedAliases,
                        'last_invoice_date' => $data['date'] ?? null,
                        'last_invoice_code' => $data['invoice_code'] ?? null,
                    ]);

                    $updated++;

                    $details[] = [
                        'name'       => $name,
                        'action'     => 'updated',
                        'matched_to' => $existing->ingredient_name,
                        'price'      => $priceKg,
                    ];

                    $visited = [];
                    $this->cascadeFromIngredient($existing, $visited);
                } else {
                    Ingredient::create([
                        'ingredient_name'   => $this->cleanDisplayName($mainName),
                        'price_per_kg'      => $priceKg,
                        'user_id'           => $user->id,
                        'additional_names'  => $this->normalizeAliasArrayForStorage($aliases),
                        'last_invoice_date' => $data['date'] ?? null,
                        'last_invoice_code' => $data['invoice_code'] ?? null,
                    ]);

                    $created++;

                    $details[] = [
                        'name'   => $name,
                        'action' => 'created',
                        'price'  => $priceKg,
                    ];
                }
            }

            if ($grandTotal > 0) {
                $category = \App\Models\CostCategory::firstOrCreate(
                    ['name' => 'Ingredienti', 'user_id' => $user->id],
                    ['name' => 'Ingredienti', 'user_id' => $user->id]
                );

                $invoiceDate = !empty($data['date'])
                    ? \Carbon\Carbon::parse($data['date'])
                    : now();

                $supplier = !empty($data['supplier_name'])
                    ? $data['supplier_name']
                    : 'Fornitore Ingredienti';

                $cleanTotal = $this->sanitizePrice($grandTotal);

                \App\Models\Cost::create([
                    'supplier'        => $supplier,
                    'cost_identifier' => $data['invoice_code'] ?? null,
                    'amount'          => $cleanTotal,
                    'due_date'        => $invoiceDate,
                    'category_id'     => $category->id,
                    'user_id'         => $user->id,
                ]);

                $costEntry = [
                    'amount'   => $cleanTotal,
                    'category' => $category->name,
                    'supplier' => $supplier,
                ];
            }
        });

        return response()->json([
            'success'    => true,
            'message'    => "{$created} ingredient" . ($created !== 1 ? 'i creati' : 'e creato') . ", {$updated} aggiornati.",
            'summary'    => compact('created', 'updated'),
            'details'    => $details,
            'cost_entry' => $costEntry,
        ]);
    }

    public function updateAliases(Request $request, Ingredient $ingredient)
    {
        abort_unless($ingredient->user_id === Auth::id(), 403);

        $request->validate([
            'additional_names' => 'nullable|string|max:2000'
        ]);

$aliases = $this->parseAliasesRaw($request->input('additional_names_raw', ''));        $ingredient->update(['additional_names' => $this->normalizeAliasArrayForStorage($aliases)]);

        return response()->json([
            'success'          => true,
            'additional_names' => $ingredient->fresh()->additional_names,
        ]);
    }

    private function sanitizePrice(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return round((float) $value, 4);
        }

        $str = trim((string) $value);
        $str = preg_replace('/[€$£\s]/u', '', $str);

        if (str_contains($str, ',') && !str_contains($str, '.')) {
            $str = str_replace(',', '.', $str);
        } elseif (str_contains($str, ',') && str_contains($str, '.')) {
            $str = str_replace(',', '', $str);
        }

        return round((float) $str, 4);
    }

    private function findMatchingIngredientFromInputs(
        string $ingredientName,
        array $aliases,
        int $userId,
        ?int $ignoreId = null
    ): ?Ingredient {
        $candidates = collect([$ingredientName])
            ->merge($aliases)
            ->flatMap(fn($value) => $this->splitIngredientTokens((string) $value))
            ->map(fn($value) => $this->normalizeIngName($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($candidates)) {
            return null;
        }

        return Ingredient::where('user_id', $userId)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->get()
            ->first(function ($ing) use ($candidates) {
                $existingNames = collect([$ing->ingredient_name])
                    ->merge($ing->additional_names ?? [])
                    ->flatMap(fn($value) => $this->splitIngredientTokens((string) $value))
                    ->map(fn($value) => $this->normalizeIngName($value))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return count(array_intersect($candidates, $existingNames)) > 0;
            });
    }

    private function normalizeIngName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[\/|;]+/u', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        $name = preg_replace('/[^\p{L}0-9\s]/u', '', $name);
        return trim($name);
    }

    private function parseAliasesRaw(?string $raw): array
{
    $raw = trim((string) $raw);

    if ($raw === '') {
        return [];
    }

    return collect(preg_split('/[,\/|;]+/u', $raw))
        ->map(fn($s) => trim($s))
        ->filter(fn($s) => $s !== '')
        ->unique(fn($s) => $this->normalizeIngName($s))
        ->values()
        ->toArray();
}

    private function splitIngredientTokens(string $value): array
    {
        return collect(preg_split('/[,\/|;]+/u', $value))
            ->map(fn($s) => trim($s))
            ->filter(fn($s) => $s !== '')
            ->unique(fn($s) => $this->normalizeIngName($s))
            ->values()
            ->toArray();
    }

    private function mergeNamesIntoAliases(
        string $existingMainName,
        array $existingAliases = [],
        ?string $newMainName = null,
        array $newAliases = [],
        ?string $otherMainName = null,
        array $otherAliases = []
    ): array {
        $allNames = collect([$existingMainName, $newMainName, $otherMainName])
            ->merge($existingAliases)
            ->merge($newAliases)
            ->merge($otherAliases)
            ->flatMap(fn($value) => $value ? $this->splitIngredientTokens((string) $value) : [])
            ->map(fn($value) => trim($value))
            ->filter(fn($value) => $value !== '')
            ->unique(fn($value) => $this->normalizeIngName($value))
            ->values();

        return $allNames
            ->reject(fn($value) => $this->normalizeIngName($value) === $this->normalizeIngName($existingMainName))
            ->values()
            ->toArray();
    }

    private function normalizeAliasArrayForStorage(array $aliases): array
    {
        return collect($aliases)
            ->flatMap(fn($value) => $this->splitIngredientTokens((string) $value))
            ->map(fn($value) => trim($value))
            ->filter(fn($value) => $value !== '')
            ->unique(fn($value) => $this->normalizeIngName($value))
            ->values()
            ->toArray();
    }

    private function cleanDisplayName(string $name): string
    {
        $parts = $this->splitIngredientTokens($name);
        return trim($parts[0] ?? $name);
    }

    private function cascadeFromIngredient(Ingredient $ingredient, array &$visited): void
    {
        if (isset($visited[$ingredient->id])) {
            return;
        }

        $visited[$ingredient->id] = true;

        $recipes = Recipe::whereHas('ingredients', function ($q) use ($ingredient) {
            $q->where('ingredient_id', $ingredient->id);
        })
            ->with(['ingredients.ingredient'])
            ->get();

        foreach ($recipes as $recipe) {
            $this->recalcAndPersistRecipeUnitIngCost($recipe);

            $linkedIng = Ingredient::where('recipe_id', $recipe->id)
                ->where('user_id', $recipe->user_id)
                ->first();

            if ($linkedIng) {
                $linkedIng->update([
                    'price_per_kg' => $this->sanitizePrice($recipe->unit_ing_cost)
                ]);

                $this->cascadeFromIngredient($linkedIng, $visited);
            }
        }
    }

    private function recalcAndPersistRecipeUnitIngCost(Recipe $recipe): void
    {
        $recipe->loadMissing('ingredients.ingredient');

        $batchIngCost = 0.0;
        $sumWeightG   = 0.0;

        foreach ($recipe->ingredients as $line) {
            $qtyG       = (float) $line->quantity_g;
            $priceKg    = (float) ($line->ingredient->price_per_kg ?? 0);
            $sumWeightG += $qtyG;
            $batchIngCost += ($qtyG / 1000.0) * $priceKg;
        }

        $batchIngCost = round($batchIngCost, 2);

        if ($recipe->sell_mode === 'piece') {
            $pcs         = $recipe->total_pieces > 0 ? $recipe->total_pieces : 1;
            $unitIngCost = round($batchIngCost / $pcs, 2);
        } else {
            $wLossG = (float) ($recipe->recipe_weight ?? 0);

            if ($wLossG <= 0) {
                $wLossG = $sumWeightG;
            }

            $kg = $wLossG > 0 ? ($wLossG / 1000.0) : 1;
            $unitIngCost = round($batchIngCost / $kg, 2);
        }

        $recipe->update([
            'unit_ing_cost' => $unitIngCost
        ]);
    }
}