<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChatDocument
{
    /** Render escaped POS data without network, PHP or JavaScript execution. */
    public function make(string $shop, string $title, array $lines, string $filename): array
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setFontCache(storage_path('framework/cache/data'));
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('chat-document', compact('shop', 'title', 'lines'))->render(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $bytes = $pdf->output();
        if (strlen($bytes) > 2097152) {
            throw ValidationException::withMessages(['document' => 'This PDF is too large. Choose a shorter reporting period.']);
        }

        return ['type' => 'document', 'document' => [
            'filename' => Str::slug($filename).'.pdf',
            'caption' => Str::limit($shop.' · '.$title, 1000, ''),
        ], '_pdf' => base64_encode($bytes)];
    }
}
