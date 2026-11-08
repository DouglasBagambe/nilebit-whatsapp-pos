<?php

namespace Tests\Feature;

use App\Services\ChatLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChatLanguageTest extends TestCase
{
    #[DataProvider('requests')]
    public function test_business_requests_keep_their_intended_operation(string $request, string $expected): void
    {
        $this->assertSame($expected, app(ChatLanguage::class)->command($request));
    }

    public static function requests(): array
    {
        return [
            ['Please send me today’s sales report as a PDF', 'sales report today pdf'],
            ['Can you show me the profit report for last month?', 'profit report last month'],
            ['How much did we sell yesterday?', 'sales report yesterday'],
            ['I sold two Mango Juice', 'sell two Mango Juice'],
            ['How many Mango Juice do we have in stock?', 'stock Mango Juice'],
            ['Please show me Stock count', 'Stock count'],
            ['Send me receipt S-000005 as PDF', 'receipt S-000005 pdf'],
            ['Please show customers', 'customers'],
        ];
    }

    #[DataProvider('numbers')]
    public function test_numeric_words_and_currency_preserve_signs_and_ambiguous_values(string $input, ?string $unit, string $expected): void
    {
        $this->assertSame($expected, app(ChatLanguage::class)->number($input, $unit));
    }

    public static function numbers(): array
    {
        return [
            ['two bottles', 'bottle', '2'],
            ['UGX 2,000', null, '2000'],
            ['2,000 UGX', null, '2000'],
            ['-1', null, '-1'],
            ['1,5', null, '1,5'],
            ['2.5', null, '2.5'],
            ['2 boxes', 'bottle', '2 boxes'],
        ];
    }
}
