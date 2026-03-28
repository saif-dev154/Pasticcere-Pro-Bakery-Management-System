<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CostInvoiceParserService
{
    private ?string $key;

    public function __construct()
    {
        $this->key = config('services.openai.key') ?? null;
    }

    public function parse(string $rawText): array
    {
        if (empty(trim($rawText))) {
            return $this->emptyResult();
        }

        if (empty($this->key)) {
            throw new \RuntimeException(
                'OpenAI API key not configured. Run: php artisan config:clear'
            );
        }

        $prevLocale = setlocale(LC_NUMERIC, '0');
        setlocale(LC_NUMERIC, 'C');

        try {
            return $this->callOpenAI($rawText);
        } finally {
            setlocale(LC_NUMERIC, $prevLocale);
        }
    }

    private function callOpenAI(string $rawText): array
    {
        $today = now()->format('Y-m-d');

        $system = 'You are a precision invoice expense extraction engine. '
                . 'Return ONLY valid JSON. No markdown. No explanation.';

        $user = <<<PROMPT
Extract expense rows from this invoice and return ONLY a JSON object.

IMPORTANT RULES:

1. Detect supplier_name
2. Detect invoice_code
3. Detect invoice date in YYYY-MM-DD format
4. Extract MULTIPLE COST ROWS if possible
5. Each row must represent an expense line or a reasonable accounting split from the invoice
6. Ignore subtotal, vat, iva, tax summary lines unless they are the ONLY usable amount
7. If the invoice has only one final total and no usable line items, return one row using that total
8. Use DOT decimal format in JSON numbers only
9. Never return markdown

NUMBER RULES:
- "12,50" => 12.50
- "1.234,56" => 1234.56
- "1,234.56" => 1234.56
- The LAST separator is decimal separator when both comma and dot exist

CATEGORY SUGGESTION RULES:
Try to suggest a category_name for each row using row meaning.
Examples:
- electricity, power, energia => Electricity
- packaging, boxes, bags => Packaging
- rent, lease => Rent
- transport, delivery, fuel => Transport
- cleaning => Cleaning
- internet, phone => Utilities
- maintenance, repair => Maintenance
- salary, payroll => Salaries
- ingredient, flour, sugar, milk, food items => Ingredients
- if unknown => Other

RETURN ONLY JSON IN THIS FORMAT:
{
  "supplier_name": "string",
  "invoice_code": "string",
  "date": "YYYY-MM-DD",
  "items": [
    {
      "description": "string",
      "amount": 0.00,
      "category_name": "string"
    }
  ]
}

INVOICE TEXT:
---
{$rawText}
---
PROMPT;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->key,
            'Content-Type'  => 'application/json',
        ])->timeout(90)->post('https://api.openai.com/v1/chat/completions', [
            'model'           => 'gpt-4o',
            'temperature'     => 0,
            'max_tokens'      => 4096,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
        ]);

        if ($response->failed()) {
            Log::error('CostInvoiceParser OpenAI error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException("OpenAI API {$response->status()}: {$response->body()}");
        }

        $raw = $response->json('choices.0.message.content', '{}');
        $raw = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw));

        $parsed = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($parsed['items']) || !is_array($parsed['items'])) {
            Log::error('CostInvoiceParser bad JSON', ['raw' => $raw]);
            throw new \RuntimeException('AI returned invalid JSON. Please try again.');
        }

        $items = [];
        foreach ($parsed['items'] as $item) {
            $description  = trim($item['description'] ?? '');
            $amount       = $this->parseEuropeanNumber((string) ($item['amount'] ?? '0'));
            $categoryName = trim($item['category_name'] ?? 'Other');

            if ($description === '' || $amount <= 0) {
                continue;
            }

            $items[] = [
                'description'   => $description,
                'amount'        => round($amount, 2),
                'category_name' => $categoryName !== '' ? $categoryName : 'Other',
            ];
        }

        if (empty($items)) {
            return $this->emptyResult();
        }

        return [
            'supplier_name' => trim($parsed['supplier_name'] ?? 'Unknown Supplier'),
            'invoice_code'  => trim($parsed['invoice_code'] ?? ''),
            'date'          => $parsed['date'] ?? $today,
            'items'         => $items,
        ];
    }

    private function parseEuropeanNumber(string $value): float
    {
        $v = trim($value);
        $v = preg_replace('/[€$£\s]/u', '', $v);

        if ($v === '' || $v === '-') {
            return 0.0;
        }

        if (str_contains($v, '.') && str_contains($v, ',')) {
            $lastDot   = strrpos($v, '.');
            $lastComma = strrpos($v, ',');

            if ($lastComma > $lastDot) {
                $v = str_replace('.', '', $v);
                $v = str_replace(',', '.', $v);
            } else {
                $v = str_replace(',', '', $v);
            }
        } elseif (str_contains($v, ',')) {
            $v = str_replace(',', '.', $v);
        }

        return (float) $v;
    }

    private function emptyResult(): array
    {
        return [
            'supplier_name' => '',
            'invoice_code'  => '',
            'date'          => now()->format('Y-m-d'),
            'items'         => [],
        ];
    }
}