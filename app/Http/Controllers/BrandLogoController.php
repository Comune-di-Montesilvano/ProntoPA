<?php

namespace App\Http\Controllers;

use App\Support\Brand;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serve il logo ente caricato: rotta dell'app invece di storage:link, che
 * sui volumi rootless di prod è fragile.
 */
class BrandLogoController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $file = Brand::logoEnteFile();
        abort_if($file === null, 404);

        return response()->file($file, [
            'Content-Type' => mime_content_type($file) ?: 'application/octet-stream',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
