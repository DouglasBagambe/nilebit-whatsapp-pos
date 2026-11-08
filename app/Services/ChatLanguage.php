<?php

namespace App\Services;

/** Converts familiar business phrasing into supported intents, never into a POS write. */
class ChatLanguage
{
    public function command(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $text = preg_replace('/^(?:please\s+)?(?:can you|could you|would you|i want to|i need to|help me)\s+/iu', '', $text);
        $text = preg_replace('/^please\s+|[,!? .]+$|\s+please$/iu', '', $text);
        $text = rtrim(preg_replace('/(?:,\s*|\s+)please[!? .]*$/iu', '', $text), ' ,!? .');
        $text = preg_replace('/^(?:show|tell|send|give|get) me\s+/iu', '', $text);
        $text = preg_replace('/^(?:show|send|give|get)\s+(?:the |our |my )?/iu', '', $text);
        $text = preg_replace('/^(?:a|the|our|my)\s+/iu', '', $text);

        if (preg_match('/^(?:how many|do we have)\s+(.+?)(?:\s+do we have)?(?:\s+in stock)?$/iu', $text, $matches)) {
            return 'stock '.trim($matches[1]);
        }
        if (preg_match('/^(?:what(?:’s|\'s| is) in stock|inventory|stock levels)$/iu', $text)) {
            return 'stock';
        }
        if (preg_match('/^(?:who owes (?:me|us)|what do customers owe)$/iu', $text)) {
            return 'debt report';
        }
        $text = preg_replace('/^how much (?:did we sell|have we sold|sales did we make)\s*/iu', 'sales report ', $text);
        $text = preg_replace('/^how much profit (?:did we make|have we made)\s*/iu', 'profit report ', $text);
        $text = preg_replace('/^(?:what (?:is|was|are) (?:the |our |my )?)/iu', '', $text);
        $text = preg_replace('/^(?:i sold|record (?:a )?sale(?: of)?)\s*/iu', 'sell ', $text);

        $pdf = preg_match('/\bpdf\b/iu', $text) === 1;
        $report = trim(preg_replace('/\b(?:(?:as|in) (?:a )?)?pdf(?: format)?\b/iu', '', $text));
        $report = preg_replace('/\s+/u', ' ', $report);
        $report = preg_replace('/^(today|yesterday)(?:’s|\'s)?\s+(.+)$/iu', '$2 $1', $report);
        $report = preg_replace('/\s+(?:for|in|from)\s+(today|yesterday|this week|last week|last 7 days|this month|last month)$/iu', ' $1', $report);
        if (preg_match('/^(sales|profit|debt|stock|production)(?: report| summary)?(?:\s+(today|yesterday|this week|last week|last 7 days|this month|last month))?$/iu', $report, $matches)
            && ($pdf || preg_match('/report|summary|^(?:sales|profit|production)(?:\s|$)/iu', $report))) {
            return mb_strtolower($matches[1]).' report'.(isset($matches[2]) ? ' '.mb_strtolower($matches[2]) : '').($pdf ? ' pdf' : '');
        }
        if (preg_match('/^reports?$/iu', $text)) {
            return 'Reports';
        }
        if (preg_match('/^(?:receipt|invoice)\s+(S-[a-z0-9-]+)(?:\s+(?:as )?(?:a )?pdf)?$/iu', $text, $matches)) {
            return 'receipt '.$matches[1].($pdf ? ' pdf' : '');
        }

        return trim($text);
    }

    public function plural(string $unit): string
    {
        if (preg_match('/(?:s|sh|ch|x|z)$/iu', $unit)) {
            return $unit.'es';
        }

        return preg_match('/[^aeiou]y$/iu', $unit) ? mb_substr($unit, 0, -1).'ies' : $unit.'s';
    }

    /** Keep signs and decimal separators intact. Only unambiguous thousands grouping is removed. */
    public function number(string $text, ?string $unit = null): string
    {
        $text = trim($text);
        if ($unit !== null) {
            $text = preg_replace('/\s+'.'(?:'.preg_quote($unit, '/').'|'.preg_quote($this->plural($unit), '/').')$/iu', '', $text);
        }
        $text = trim(preg_replace('/^(?:UGX|USHS|USH)\s*|\s*(?:UGX|USHS|USH)$/iu', '', $text));
        $words = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
            'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty'];
        $number = array_search(mb_strtolower($text), $words, true);
        if ($number !== false) {
            return (string) $number;
        }
        if (preg_match('/^-?\d{1,3}(?:,\d{3})+(?:\.\d{1,3})?$/D', $text)) {
            return str_replace(',', '', $text);
        }

        return $text;
    }
}
