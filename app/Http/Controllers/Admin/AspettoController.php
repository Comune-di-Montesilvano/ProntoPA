<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Impostazioni\SalvaImpostazioni;
use App\Support\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AspettoController extends Controller
{
    public function index(): View
    {
        return view('admin.aspetto', ['logoUrl' => Brand::logoEnteUrl(), 'haLogoFile' => Brand::logoEnteFile() !== null]);
    }

    public function update(Request $request, SalvaImpostazioni $salva): RedirectResponse
    {
        $request->validate([
            // Niente SVG: servito dal nostro dominio potrebbe eseguire script.
            'ente_logo_upload' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'rimuovi_logo_file' => ['nullable', 'boolean'],
        ]);

        $salva->salva('ASPETTO', $request->input('impostazioni', []));

        if ($request->hasFile('ente_logo_upload')) {
            Brand::salvaLogoFile($request->file('ente_logo_upload'));
        } elseif ($request->boolean('rimuovi_logo_file')) {
            Brand::rimuoviLogoFile();
        }

        return back()->with('success', 'Aspetto salvato.');
    }
}
