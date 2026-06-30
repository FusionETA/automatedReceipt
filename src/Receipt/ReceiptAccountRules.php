<?php

declare(strict_types=1);

namespace App\Receipt;

class ReceiptAccountRules
{
    private const RULES = [
        '2026-M1112' => [
            'template_name' => 'Mny Receipt - MNY May 2026',
            'description'   => 'ENGLISH M&Y FEE, May 7-10 2026 In Malaysia',
        ],
        '2026-M2111' => [
            'template_name' => 'Mny Receipt - MNY Oct 2026',
            'description'   => 'Powerful Presentation Fee, October 17-19, 2025 In Malaysia',
        ],
        'I-PRO-102' => [
            'template_name' => 'M&Y Book - Hardcover',
            'description'   => 'Money & You Book - HARDCOPY',
        ],
        'I-PRO-111' => [
            'template_name' => 'M&Y Book - Paperback',
            'description'   => 'Money & You Book - PAPERBACK',
        ],
    ];

    public static function matchInvoice(array $invoice): ?array
    {
        foreach (($invoice['LineItems'] ?? []) as $lineItem) {
            $code = trim((string) ($lineItem['AccountCode'] ?? ''));

            if ($code !== '' && isset(self::RULES[$code])) {
                return self::RULES[$code] + ['account_code' => $code];
            }
        }

        return null;
    }

    public static function matchReceiptData(array $data): ?array
    {
        foreach (($data['line_items'] ?? []) as $lineItem) {
            $code = trim((string) ($lineItem['AccountCode'] ?? ''));

            if ($code !== '' && isset(self::RULES[$code])) {
                return self::RULES[$code] + ['account_code' => $code];
            }
        }

        return null;
    }

    public static function allowedCodes(): array
    {
        return array_keys(self::RULES);
    }

    public static function all(): array
    {
        return self::RULES;
    }
}
