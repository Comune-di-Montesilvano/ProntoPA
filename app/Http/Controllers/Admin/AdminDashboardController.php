<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appalto;
use App\Models\Istituto;
use App\Models\Impresa;
use App\Models\Plesso;
use App\Models\SlaConfigurazione;
use App\Models\User;
use App\Services\Backup\CatalogoBackup;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function index(CatalogoBackup $backup): View
    {
        $conteggi = [
            'utenti_attivi' => User::where('attivo', true)->count(),
            'utenti_disattivati' => User::where('attivo', false)->count(),
            'enti' => Istituto::count(),
            'sedi' => Plesso::count(),
            'imprese' => Impresa::count(),
            'appalti' => Appalto::count(),
            'sla' => SlaConfigurazione::count(),
        ];

        $backupInAllarme = $backup->inAllarme(CarbonImmutable::now());

        return view('admin.dashboard', compact('conteggi', 'backupInAllarme'));
    }
}