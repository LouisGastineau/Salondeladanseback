<?php

namespace App\Services;

use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;

class ExportService
{
    public function __construct(private PlanningService $planning, private EditionService $editions, private AdminService $admins) {}

    public function pdf(User $user): string
    {
        $edition = $this->editions->active();
        $reservations = $this->planning->reservations($user);
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows = '';
        foreach ($reservations as $reservation) {
            $slot = $reservation->creneau;
            $rows .= '<tr><td>'.$escape($slot->jour->format('d/m/Y')).'</td><td>'
                .$escape(substr($slot->heure_debut, 0, 5).' - '.substr($slot->heure_fin, 0, 5))
                .'</td><td>'.$escape($slot->mission->nom).'</td><td>'.$escape($reservation->statut.($reservation->validation_admin ? ' / '.str_replace('_', ' ', $reservation->validation_admin) : '')).'</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4">Aucun créneau réservé.</td></tr>';
        }
        $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>
            @page { margin: 45px; } body { font-family: DejaVu Sans, sans-serif; color: #243044; font-size: 11px; }
            h1 { font-size: 25px; margin-bottom: 6px; color: #273e66; } h2 { font-size: 16px; margin: 24px 0 8px; }
            .muted { color: #617086; } .badge { margin: 16px 0 25px; padding: 12px; background: #edf2f8; }
            table { width: 100%; border-collapse: collapse; table-layout: fixed; }
            th { background: #273e66; color: white; text-align: left; }
            th, td { padding: 12px 8px; border-bottom: 1px solid #dce3ed; vertical-align: top; word-wrap: break-word; }
            tr { page-break-inside: avoid; } .foot { margin-top: 30px; font-size: 10px; color: #617086; }
            </style></head><body><p class="muted">SALON DE LA DANSE</p><h1>Mon planning bénévole</h1>
            <p>'.$escape($edition->nom).' · '.$escape($edition->date_debut->format('d/m/Y')).' au '.$escape($edition->date_fin->format('d/m/Y')).'</p>
            <h2>'.$escape($user->prenom.' '.$user->nom).'</h2>
            <div class="badge">Statut du planning : <strong>'.$escape($user->fresh()->statut_planning).'</strong></div>
            <table><thead><tr><th style="width:19%">Jour</th><th style="width:23%">Horaires</th><th style="width:38%">Mission</th><th style="width:20%">Statut</th></tr></thead>
            <tbody>'.$rows.'</tbody></table><p class="foot">Pour toute modification après validation, contactez un administrateur.</p></body></html>';
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('fontCache', storage_path('framework/cache'));
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return $pdf->output();
    }

    public function csv(User $actor, array $filters): \Closure
    {
        $edition = $this->editions->active();
        $users = $this->admins->users($actor, $filters)
            ->with(['reservations' => fn ($q) => $q->whereHas('creneau.mission', fn ($q) => $q->where('edition_id', $edition->id))->with('creneau.mission')])
            ->get();

        return function () use ($users): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            $write = function (array $row) use ($out): void {
                // Prevent spreadsheet formula execution, including phone numbers starting with +.
                $row = array_map(fn ($value) => preg_match('/^[\s]*[=+\-@]/u', (string) $value) ? "'".$value : $value, $row);
                fputcsv($out, $row, ';', '"', '');
            };
            $write(['ID', 'Nom', 'Prénom', 'Email', 'Téléphone', 'Rôle', 'Mineur', 'Planning', 'Mission', 'Jour', 'Début', 'Fin', 'Réservation']);
            foreach ($users as $user) {
                $identity = [$user->id, $user->nom, $user->prenom, $user->email, $user->telephone, $user->role, $user->isMineur ? 'oui' : 'non', $user->statut_planning];
                if ($user->reservations->isEmpty()) {
                    $write([...$identity, '', '', '', '', '']);
                }
                foreach ($user->reservations as $reservation) {
                    $slot = $reservation->creneau;
                    $write([...$identity, $slot->mission->nom, $slot->jour->toDateString(), $slot->heure_debut, $slot->heure_fin, $reservation->statut]);
                }
            }
            fclose($out);
        };
    }
}
