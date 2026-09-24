<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvitationDeliveryException;
use App\Models\InvitationCode;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InvitationCsvService
{
    public function __construct(private InvitationService $invitations) {}

    public function import(User $actor, UploadedFile $file, int $offset, int $limit): array
    {
        abort_unless($actor->role === User::ROLE_ADMIN, 403);
        $rows = $this->parse($file);
        if ($offset > count($rows)) {
            throw ValidationException::withMessages(['offset' => 'Position supérieure au nombre de lignes.']);
        }
        $results = [];
        $started = microtime(true);
        foreach (array_slice($rows, $offset, $limit) as $row) {
            // Return a continuation before another SMTP call can exceed the HTTP time budget.
            if ($results !== [] && microtime(true) - $started >= 10) {
                break;
            }
            if ($row['statut'] === null) {
                try {
                    $existing = InvitationCode::where('email', $row['email'])->first();
                    $this->invitations->send($actor, $row['email']);
                    $row['statut'] = $existing?->statut_envoi === 'envoye' ? 'deja_invite' : 'envoye';
                    $row['message'] = $row['statut'] === 'envoye'
                        ? 'Invitation transmise au service d’envoi.' : 'Invitation déjà envoyée, aucun nouvel envoi.';
                } catch (ValidationException $exception) {
                    $row['statut'] = 'deja_inscrit';
                    $row['message'] = 'Cette adresse possède déjà un compte.';
                } catch (BusinessRuleException $exception) {
                    $row['statut'] = 'en_cours';
                    $row['message'] = 'Un envoi est en cours ou doit être vérifié par un administrateur.';
                } catch (InvitationDeliveryException $exception) {
                    $row['statut'] = 'echec';
                    $row['message'] = $exception->getMessage();
                }
            }
            $results[] = $row;
        }
        $next = $offset + count($results);

        return ['rows' => $results, 'meta' => [
            'total' => count($rows), 'offset' => $offset, 'traites' => count($results),
            'next_offset' => $next < count($rows) ? $next : null,
            'resultats' => array_count_values(array_column($results, 'statut')),
        ]];
    }

    private function parse(UploadedFile $file): array
    {
        $contents = file_get_contents($file->getRealPath());
        if (! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")) {
            throw ValidationException::withMessages(['file' => 'Le CSV doit être un fichier texte UTF-8.']);
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $first = strtok(ltrim($contents), "\r\n") ?: '';
        $delimiter = count(str_getcsv($first, ';', '"', '')) > count(str_getcsv($first, ',', '"', '')) ? ';' : ',';
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        $rows = [];
        $seen = [];
        $column = 0;
        $firstRow = true;
        $line = 0;
        try {
            while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
                $line++;
                if (count(array_filter($cells, fn ($cell) => trim((string) $cell) !== '')) === 0) {
                    continue;
                }
                if ($firstRow) {
                    $firstRow = false;
                    $headers = array_map(fn ($cell) => mb_strtolower(trim((string) $cell)), $cells);
                    $emailColumns = array_keys($headers, 'email', true);
                    if (count($emailColumns) > 1) {
                        throw ValidationException::withMessages(['file' => 'Une seule colonne email est autorisée.']);
                    }
                    if ($emailColumns !== []) {
                        $column = $emailColumns[0];

                        continue;
                    }
                    if (count($cells) !== 1) {
                        throw ValidationException::withMessages(['file' => 'Un CSV multicolonne doit avoir un en-tête email.']);
                    }
                }
                $email = mb_strtolower(trim((string) ($cells[$column] ?? '')));
                $status = null;
                $message = null;
                if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:255']])->fails()) {
                    $status = 'invalide';
                    $message = 'Adresse email invalide.';
                } elseif (isset($seen[$email])) {
                    $status = 'doublon';
                    $message = 'Adresse déjà présente dans ce CSV.';
                }
                $seen[$email] = true;
                $rows[] = ['ligne' => $line, 'email' => $email, 'statut' => $status, 'message' => $message];
                if (count($rows) > 200) {
                    throw ValidationException::withMessages(['file' => 'Le CSV est limité à 200 adresses.']);
                }
            }
        } finally {
            fclose($stream);
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'Le CSV ne contient aucune adresse.']);
        }

        return $rows;
    }
}
