# Salon de la Danse — backend API

API REST de gestion des bénévoles : invitations, authentification Sanctum, réservations, validation des plannings, administration des utilisateurs, PDF personnel et export CSV.

## Stack

- Laravel 12 (version conservée avec l'accord du porteur du projet), PHP 8.2.
- MariaDB 10.11, Eloquent, Sanctum par Bearer token.
- Front Vite séparé ; aucune compilation npm nécessaire pour ce backend.
- Dompdf pour le PDF ; CSV UTF-8 compatible Excel.

## Dossier local Windows déjà préparé

PHP, Composer, MariaDB et les dépendances Composer sont installés dans le dossier local livré. Les outils portables (`.tools/`), données locales (`.local/`), secrets (`.env`) et bibliothèques (`vendor/`) sont exclus du dépôt Git.

Depuis ce dossier dans PowerShell :

```powershell
.\Start-Local.ps1
```

L'API écoute sur `http://127.0.0.1:8000/api` et MariaDB sur `127.0.0.1:3307`, uniquement sur la machine locale. Pour arrêter ces services :

```powershell
.\Stop-Local.ps1
```

Créer le premier administrateur, avec saisie interactive du mot de passe :

```powershell
& .\.tools\php\php.exe artisan salon:admin votre-email@example.com
```

Exécuter les tests :

```powershell
& .\.tools\php\php.exe artisan test
& .\.tools\php\php.exe tests/schema-smoke.php
& .\.tools\php\php.exe tests/api-smoke.php
```

Les scripts `Start-Local.ps1` et `Stop-Local.ps1` utilisent le runtime portable du dossier livré. Après un nouveau clone GitHub, suivre l'installation standard ci-dessous ou installer des outils PHP/MariaDB équivalents.

## Installation standard après un clone

Prérequis : PHP 8.2 avec `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `dom`, `xml`, `xmlwriter`, `curl`, `gd`, `zip`, Composer 2 et MariaDB. `pdo_sqlite` sert aux tests Laravel de base. L'outil de logs Pail, spécifique à Unix, a été retiré pour permettre l'installation Windows.

```sh
composer install
cp .env.example .env
php artisan key:generate
```

Sous PowerShell, remplacer `cp` par `Copy-Item`. Créer une base MariaDB vide et un utilisateur limité à cette base, puis renseigner `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` et `DB_PASSWORD` dans `.env`. Le port 3307 de l'exemple correspond au runtime portable ; l'adapter à votre serveur MariaDB si nécessaire.

```sh
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

Ne pas utiliser `migrate:fresh` sur une base contenant des données à conserver. La migration `adapt_users_table` conserve l'historique du squelette Laravel et exige une table utilisateurs initialement vide.

## Connexion du frontend

Dans le `.env` Vite :

```dotenv
VITE_API_URL=http://127.0.0.1:8000/api
```

Le backend accepte l'origine `http://localhost:5173` par défaut. Modifier `FRONTEND_URL` dans son `.env` si le front utilise une autre origine. Envoyer `Authorization: Bearer <token>` pour les routes privées ; aucun cookie de session ni endpoint CSRF n'est nécessaire.

## Règles métier

- Inscription exclusivement avec un code actif, consommé atomiquement ; email unique.
- De 1 à 3 créneaux par édition active ; le minimum est vérifié à la validation du planning.
- Aucun chevauchement, même entre missions différentes.
- Pas de trois créneaux consécutifs le même jour. Toute interruption casse actuellement l'enchaînement ; aucune durée minimale de pause n'a été imposée.
- Capacité contrôlée par transaction et verrouillages MariaDB. Les brouillons occupent une place.
- Planning validé verrouillé pour le bénévole ; intervention administrateur sans contourner la capacité ni les contraintes horaires.
- Missions sensibles uniquement visibles dans les routes admin. Aucune identité d'un autre bénévole dans les réponses publiques.
- Profil personnel modifiable uniquement par un admin ; photos privées, mots de passe et invitations exclus des réponses utilisateur.

La logique métier se trouve dans `app/Services`, les entrées dans les Form Requests et les sorties JSON dans les API Resources. Les routes admin utilisent `role:admin`.

## Endpoints et données initiales

Les **23 endpoints** sont détaillés dans [API-BACKEND.md](API-BACKEND.md).

La base locale commence vide. Après la création de l'admin, renseigner l'édition, les missions et les créneaux réels via Tinker ou la base, puis générer les codes d'invitation avec l'API admin.

L'extension demandée pour créer/modifier éditions, missions et créneaux depuis le back-office n'a pas encore été implémentée : l'accès au VPS avait été bloqué avant le début de ce travail. Cette livraison récupère le backend déjà terminé. La modification des utilisateurs et les attributions manuelles sont disponibles ; la création d'admins se fait par la commande `salon:admin`.

## Vérification et déploiement

- Suite Laravel : `composer test`.
- Tests MariaDB, relations et rollback : `php tests/schema-smoke.php`.
- Tests API et concurrence avec processus PHP indépendants : `php tests/api-smoke.php`.
- Dépendances : `composer validate` et `composer check-platform-reqs`.

Les tests MariaDB créent des tables isolées avec un préfixe aléatoire, puis les suppriment. Le test PDF génère `tests/planning-test.pdf`, ignoré par Git.

Le dossier `deployment/` conserve l'ancienne configuration Nginx du VPS, à adapter à votre environnement. Les certificats, clés privées, identifiants SSH et données de production ne font pas partie du dépôt.


## Recuperation de mot de passe et editions

- `POST /api/forgot-password` : `{"email":"benevole@example.com"}`. Reponse generique 200 ; email synchrone via Brevo avec un code de reinitialisation (60 minutes). Limite : 5 requetes/minute/IP et un email/minute/compte.
- `POST /api/reset-password` : `{"email":"benevole@example.com","token":"code recu par email","password":"nouveau-mot-de-passe","password_confirmation":"nouveau-mot-de-passe"}`. Le code est consomme et toutes les sessions du compte sont revoquees. Code invalide/expire : 422.
- `GET /api/admin/missions/{id}` et `GET /api/admin/creneaux/{id}` : details, token admin requis.
- `PATCH /api/admin/editions/{id}` avec `{"isArchived":true}` archive et desactive une edition. Les reservations restent consultables avec `GET /api/admin/plannings?edition_id={id}`.
- Restaurer : `{"isArchived":false}` ; activer : `{"isArchived":false,"isActive":true}`. Une seule edition active ; les statuts historiques sont conserves dans les reservations, et `users.statut_planning` reflete l'edition active.
- `PATCH /api/admin/creneaux/{id}` avec `{"capacite_max":10}` : refuse (409) si inferieur au nombre de reservations. Les horaires/jour/mission d'un creneau reserve ne sont pas deplacables avant retrait des reservations.
- Les PATCH de dates/heures sont verifies avec les valeurs existantes. Les dates d'une edition doivent contenir tous ses creneaux. Une mission avec creneaux ne change pas d'edition.
- Aucun email reel n'est envoye par les tests automatises (transport simule).
