# Backend Salon de la Danse

Backend récupéré localement depuis la dernière archive du VPS. Laravel 12 / PHP 8.2 / MariaDB ; Sanctum par Bearer token. Voir `Readme.md` pour démarrer le projet local. Les informations de déploiement VPS ci-dessous décrivent l'installation historique et ne garantissent pas sa disponibilité actuelle.

## Connexion du front

Base de l’API locale : `http://127.0.0.1:8000/api`.

```js
const response = await fetch(`${import.meta.env.VITE_API_URL}/creneaux`, {
  headers: {
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  },
});
const result = await response.json();
```

Dans le `.env` du front :

```dotenv
VITE_API_URL=http://127.0.0.1:8000/api
```

CORS autorise `http://localhost:5173`, sans cookies. Pour ajouter une origine, renseigner `FRONTEND_URL` dans le `.env` du backend, avec plusieurs origines séparées par des virgules, puis `php artisan config:clear`. Les chemins de photos renvoyés sont relatifs au domaine de l’API ; les télécharger avec le Bearer token via `fetch`, puis utiliser un object URL côté front.

## Authentification

`POST /register` accepte :

```json
{
  "nom": "Dupont",
  "prenom": "Élodie",
  "email": "elodie@example.com",
  "telephone": "0600000000",
  "password": "un-mot-de-passe-personnel",
  "password_confirmation": "un-mot-de-passe-personnel",
  "code_invitation": "CODE_FOURNI_PAR_ADMIN",
  "isMineur": false
}
```

Le champ `photo` est facultatif : utiliser `multipart/form-data` pour envoyer un fichier JPEG, PNG ou WebP, limité à 2 Mio et 4096 × 4096 pixels. En multipart, envoyer `isMineur` sous forme `0` ou `1`. Sans photo, le chemin stocké est une chaîne vide et `photo_url` vaut `null`.

`POST /login` accepte `email` et `password`. Ces deux endpoints renvoient :

```json
{
  "data": {
    "user": {"id": 1, "nom": "Dupont", "prenom": "Élodie", "email": "elodie@example.com", "telephone": "0600000000", "role": "benevole", "isMineur": false, "statut_planning": "brouillon", "photo_url": null},
    "token": "TOKEN_SANCTUM",
    "token_type": "Bearer"
  }
}
```

Les tokens expirent après 7 jours. `POST /logout` révoque uniquement le token courant et répond `204`. `GET /me` renvoie le profil courant dans `data`. Il n’existe aucune route de modification personnelle pour le bénévole.

## Endpoints bénévoles

Tous nécessitent `Authorization: Bearer ...`.

| Méthode | Chemin | Entrée / résultat |
| --- | --- | --- |
| GET | `/me` | Profil courant |
| GET | `/me/photo` | Photo privée, `404` si absente |
| GET | `/creneaux` | Filtres `jour=YYYY-MM-DD`, `mission_id`, `page`, `per_page` (1–100) |
| POST | `/reservations` | JSON `{"creneau_id": 12}` ; création `201` |
| DELETE | `/reservations/{id}` | Suppression `204` |
| GET | `/reservations` | Réservations publiques du bénévole |
| GET | `/planning` | Même résultat que `/reservations` |
| POST | `/planning/valider` | Valide atomiquement le planning et ses réservations |
| GET | `/planning/pdf` | Téléchargement PDF du planning public personnel |

Chaque créneau de la liste fournit `id`, `jour`, `heure_debut`, `heure_fin`, `capacite_max`, `places_restantes` et `mission` (`id`, `edition_id`, `nom`). Les listes paginées utilisent `data`, `links` et `meta`. Une réservation fournit `id`, `statut` et `creneau`, sans identité d’un autre bénévole.

## Endpoints administrateur

Toutes les routes `/admin/*` sont protégées par `auth:sanctum` puis `role:admin`.

| Méthode | Chemin | Entrée / résultat |
| --- | --- | --- |
| GET | `/admin/users` | Recherche `q` sur nom/prénom/email ; filtres `role`, `statut_planning`, `isMineur=0|1`, pagination |
| PATCH | `/admin/users/{id}` | `nom`, `prenom`, `email`, `telephone`, `isMineur`, `photo` |
| GET | `/admin/users/{id}/photo` | Photo privée du compte |
| GET | `/admin/users/{id}/planning` | Inclut les missions sensibles |
| POST | `/admin/users/{id}/planning/valider` | Validation par un admin |
| POST | `/admin/users/{id}/planning/deverrouiller` | Repasse le planning et les réservations en brouillon |
| GET | `/admin/creneaux` | Inclut les missions sensibles ; mêmes filtres que la liste bénévole |
| POST | `/admin/reservations` | JSON `{"user_id": 5, "creneau_id": 12}` ; `201` |
| DELETE | `/admin/reservations/{id}` | Suppression y compris planning validé ; `204` |
| POST | `/admin/invitation-codes` | JSON `{"nombre": 10}` ; renvoie les nouveaux codes, entre 1 et 200 |
| GET | `/admin/export` | CSV UTF-8 avec BOM et séparateur `;`, compatible Excel ; mêmes filtres utilisateurs |

Pour modifier une photo avec PHP 8.2, envoyer un `POST` multipart vers `/admin/users/{id}` avec `_method=PATCH` ; Laravel traite alors la requête comme un PATCH. Les rôles, mots de passe et statuts ne sont pas modifiables par ce endpoint.

## Règles appliquées

- Code d’invitation vérifié et verrouillé dans la transaction d’inscription ; création du compte, consommation du code et création du token atomiques. L’email est normalisé en minuscules et reste unique en base.
- Une unique édition doit être active. Les réservations consultées et modifiées concernent cette édition. En l’absence d’édition active, ou si plusieurs sont actives, les opérations de planning répondent `409`.
- Maximum 3 réservations sur le week-end de l’édition, toutes missions confondues. Minimum 1 au moment de valider. Un brouillon peut être vide.
- Les intervalles horaires d’une même journée ne peuvent pas se chevaucher, même partiellement et même sur des missions différentes.
- Trois créneaux consécutifs sont interdits, quelle que soit l’ordre d’ajout. « Consécutifs » signifie que l’heure de fin du précédent égale l’heure de début du suivant. Les créneaux doivent commencer et finir le même jour.
- Brouillons et réservations validées occupent tous une place. Verrouillage du bénévole puis du créneau, lectures SQL verrouillées et reprises sur deadlock pour gérer la concurrence MariaDB.
- Le bénévole ne modifie plus un planning validé. L’admin peut intervenir, mais respecte les capacités et contraintes horaires. Il doit déverrouiller un planning avant de supprimer sa dernière réservation.
- Les missions sensibles sont absentes des réponses bénévoles, même lorsqu’elles leur sont attribuées par un admin. Elles comptent néanmoins dans les limites de planning et ne figurent pas dans le PDF public. Elles restent visibles dans le back-office et le CSV admin.
- Photos stockées sur le disque privé ; aucune exposition directe des modèles ni des secrets. Le CSV neutralise les valeurs interprétables comme formules Excel. Le PDF échappe les textes et désactive les ressources distantes et l’exécution de PHP/JavaScript.

Le schéma conserve un statut de planning global sur `users`. Cette version vise une édition active ; le passage à une autre édition et l’archivage de statuts par édition ne sont pas automatisés.

## Préparer les données réelles

Aucun compte réel ni calendrier d’événement n’a été créé par les tests.

Créer le premier administrateur sur le VPS :

```sh
cd /var/www/back
php artisan salon:admin votre-email@example.com
```

La commande demande nom, prénom, téléphone et mot de passe sans afficher ce dernier. Se connecter ensuite avec `/api/login`, puis générer les invitations via `/api/admin/invitation-codes`.

Les éditions, missions et créneaux doivent être renseignés avec les dates et capacités réelles dans la base ou via `php artisan tinker`. Les CRUD de configuration de l’événement ne faisaient pas partie des endpoints demandés. Exemple de structure dans Tinker, à adapter avant exécution :

```php
$edition = App\Models\Edition::create(['nom' => 'Salon de la Danse', 'date_debut' => '2026-10-09', 'date_fin' => '2026-10-11', 'isActive' => true]);
$mission = $edition->missions()->create(['nom' => 'Accueil', 'isSensible' => false]);
$mission->creneaux()->create(['jour' => '2026-10-09', 'heure_debut' => '09:00:00', 'heure_fin' => '11:00:00', 'capacite_max' => 10]);
```

## Erreurs et tests

### Consultation admin groupée

Ces routes exigent `Authorization: Bearer <token-admin>` et renvoient `403` pour un bénévole.

| Méthode | Route | Résultat |
|---|---|---|
| GET | `/api/admin/users/{id}` | Fiche utilisateur au format `UserResource`, sans mot de passe ni code d’invitation |
| GET | `/api/admin/plannings` | Tous les utilisateurs et leurs réservations de l’édition active, en une requête HTTP |
| GET | `/api/admin/creneaux/{id}/inscrits` | Toutes les réservations du créneau, avec le profil de chaque inscrit |

`/admin/plannings` accepte `q`, `role`, `statut_planning`, `isMineur` et `edition_id`. Exemple : `/api/admin/plannings?role=benevole`. Sans `edition_id`, il doit exister exactement une édition active, sinon `409`. Un `edition_id` explicite permet de consulter une édition inactive (`404` si elle n’existe pas). Les utilisateurs sans réservation sont inclus avec `reservations: []`. Cette route n’est pas paginée pour le MVP ; les relations sont chargées en groupe pour éviter une requête SQL par bénévole.

Structure de la réponse groupée :

```json
{
  "data": [
    {
      "id": 2,
      "nom": "Test",
      "prenom": "Benevole",
      "email": "benevole.test@example.com",
      "telephone": "0600000001",
      "role": "benevole",
      "isMineur": false,
      "statut_planning": "brouillon",
      "photo_url": null,
      "reservations": []
    }
  ],
  "meta": { "edition_id": 1 }
}
```

Chaque réservation utilise le même format que `/api/admin/users/{id}/planning`. Les missions sensibles sont incluses pour l’administrateur uniquement. `statut_planning` reste l’état global actuel du compte ; pour une ancienne édition, utiliser le `statut` des réservations, le schéma ne conserve pas d’état de planning par édition.

`/admin/creneaux/{id}/inscrits` retourne `data: [{ id, statut, user: {...} }]` et `meta.creneau` (créneau, mission et places restantes). Un créneau vide retourne `data: []`, un créneau inexistant `404`. Les réservations brouillon et validées sont incluses. Le créneau peut appartenir à une édition inactive.

### Photos et ouverture du planning

La validation de l’inscription et de la modification admin limite chaque photo à **2048 Kio (2 Mio)**, aux formats JPEG, PNG ou WebP et à 4096 × 4096 pixels. Le VPS accepte 3 Mio par fichier côté PHP et 4 Mio pour le corps de requête côté PHP/Nginx, mais la limite métier de 2 Mio s’applique en premier aux fichiers valides transmis à Laravel. Un dépassement de 2 Mio renvoie `422` sur `photo` tant que les plafonds du serveur ne sont pas atteints.

Le planning exige exactement une édition active. Aucune édition active (ou plusieurs) produit `409`. Avec une édition active mais aucun créneau, la liste retourne un tableau vide. Le déploiement ne crée pas automatiquement les éditions, missions ou créneaux.

### Fonctionnalités encore absentes

La réinitialisation de mot de passe par email, le CRUD des éditions/missions/créneaux, la modification de capacité et un workflow d’archivage ne sont pas encore implémentés. L’envoi de récupération de mot de passe nécessitera la configuration du fournisseur mail et une URL frontend de réinitialisation. La gestion de plusieurs éditions devra traiter explicitement l’état du planning, actuellement stocké sur l’utilisateur et non par édition.

### Envoyer une invitation par email

`POST /api/admin/invitations` exige un token administrateur. Le frontend envoie :

```js
const response = await fetch(`${API_URL}/admin/invitations`, {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`,
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({ email: 'benevole@example.com' }),
});
const result = await response.json();
if (!response.ok) throw new Error(result.message ?? 'Invitation impossible');
```

Avec `API_URL=https://vps123924.serveur-vps.net/api`. Réponse `201` :

```json
{
  "data": { "id": 42, "code": "EXEMPLE_DE_CODE", "isActive": true },
  "message": "Invitation transmise au service d’envoi."
}
```

Le service génère un code unique, envoie le mail de façon synchrone et active le code après acceptation par le transport mail. L’acceptation ne garantit pas l’arrivée en boîte de réception : les rejets ultérieurs et le dossier spam dépendent du fournisseur. Aucun job ni aucune queue.

Une adresse déjà inscrite est refusée (`422`). Sans transport mail réel, ou en cas d’échec d’envoi, la route renvoie `503` ; aucun nouveau code utilisable n’est laissé après un échec du transport. Limite : 10 requêtes par minute et par administrateur. Un nouvel appel génère un nouveau code : ne pas réessayer automatiquement après une réponse réseau incertaine.

L’ancienne route `POST /api/admin/invitation-codes` avec `{ "nombre": 5 }` reste disponible pour générer des codes sans envoyer de mail. Les codes restent à usage unique et ne sont pas liés à l’adresse destinataire dans le schéma MVP.

Configuration à renseigner dans `/var/www/back/shared/.env` sur le VPS :

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=<serveur du fournisseur>
MAIL_PORT=587
MAIL_USERNAME=<identifiant SMTP>
MAIL_PASSWORD=<mot de passe SMTP>
MAIL_FROM_ADDRESS=<adresse expéditeur validée>
MAIL_FROM_NAME="Salon de la Danse"
```

Pour un fournisseur utilisant TLS implicite sur le port 465, utiliser `MAIL_SCHEME=smtps`. Le port 587 utilise STARTTLS selon la configuration du fournisseur. Après modification :

```sh
sudo -u deploy bash -c 'cd /var/www/back/current && php artisan config:cache'
systemctl reload php8.2-fpm
```

Les identifiants SMTP doivent rester sur le serveur, jamais dans les variables Vite ou GitHub. Tant que `MAIL_MAILER=log`, seule la génération sans mail est utilisable.

- `401` : token absent, invalide, expiré ou révoqué.
- `403` : accès administrateur refusé.
- `404` : ressource absente, appartenant à un autre bénévole ou mission sensible inaccessible.
- `409` : conflit avec une règle métier.
- `422` : validation d’entrée ; détail dans `errors`.
- `429` : limitation de fréquence.
- `500` : message générique, aucun détail technique dans la réponse API.

```sh
php tests/api-smoke.php
php tests/schema-smoke.php
```

Les tests utilisent des tables MariaDB avec un préfixe aléatoire, puis les suppriment. Les tests de concurrence lancent deux processus PHP indépendants pour la dernière place, le quota individuel, les horaires en conflit, le code partagé et l’email partagé. Aucun `migrate:fresh` n’est exécuté sur la base réelle.

La suite API couvre les règles de réservation, les accès et les invitations par email (envoi simulé et échec du transport). Le rendu PDF de test a également été contrôlé visuellement et son texte vérifié pour l’absence de mission sensible.

Nginx pointe vers le dossier `public` de Laravel. HTTPS utilise un certificat Let's Encrypt avec renouvellement automatique et rechargement de Nginx après renouvellement. HTTP redirige vers HTTPS. Le site Nginx par défaut n’a pas été remplacé : un hôte dédié au nom du VPS a été ajouté.

Sauvegarde avant modifications : `/home/deploy/back-before-api-VL0s8e/source.tar.gz`.
