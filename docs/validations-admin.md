# Validation des réservations sensibles — contrat front

Toutes les routes ci-dessous ont le préfixe `/api` et exigent `Authorization: Bearer <token>` et `Accept: application/json`. Pour POST/PATCH, envoyer `Content-Type: application/json`. GET et DELETE n'ont pas de corps JSON. Les identifiants et données sont des exemples.

`statut` décrit le planning (`brouillon`/`valide`). `validation_admin` décrit la décision (`null`/`en_attente`/`acceptee`). Ne jamais déduire la décision de `statut`. Le front n'envoie pas `validation_admin` lors de la création.

Un refus supprime la ligne : `refusee` est une décision et un filtre autorisé, mais n'apparaît pas dans les réservations conservées. Après refus ou annulation d'une demande en attente, le planning et ses réservations restantes repassent en brouillon ; les approbations admin restantes sont conservées. Recharger le planning et `/api/me`, puis permettre une nouvelle validation.

Les créneaux/réservations côté bénévole restent limités à l'édition active. Les places ne révèlent jamais l'identité des autres bénévoles.

## GET /api/creneaux

Liste paginée ; `links` et `meta` de pagination sont omis ici pour alléger l’exemple. Inclut les missions sensibles.
Requête : sans corps JSON.

Réponse 200 :
```json
{
  "data": [
    {
      "id": 12,
      "jour": "2026-10-09",
      "heure_debut": "09:00:00",
      "heure_fin": "10:00:00",
      "capacite_max": 4,
      "mission": {
        "id": 3,
        "edition_id": 1,
        "nom": "Billetterie",
        "isSensible": true
      },
      "places_restantes": 3
    }
  ]
}
```

## POST /api/reservations

Mission non sensible : même réponse avec `validation_admin: null`. Les demandes en attente occupent une place et comptent dans toutes les contraintes.
Requête : 
```json
{
  "creneau_id": 12
}
```

Réponse 201 :
```json
{
  "data": {
    "id": 42,
    "statut": "brouillon",
    "validation_admin": "en_attente",
    "created_at": "2026-09-24T12:00:00.000000Z",
    "creneau": {
      "id": 12,
      "jour": "2026-10-09",
      "heure_debut": "09:00:00",
      "heure_fin": "10:00:00",
      "capacite_max": 4,
      "mission": {
        "id": 3,
        "edition_id": 1,
        "nom": "Billetterie",
        "isSensible": true
      }
    }
  }
}
```

## GET /api/planning


Requête : sans corps JSON.

Réponse 200 :
```json
{
  "data": [
    {
      "id": 42,
      "statut": "brouillon",
      "validation_admin": "en_attente",
      "created_at": "2026-09-24T12:00:00.000000Z",
      "creneau": {
        "id": 12,
        "jour": "2026-10-09",
        "heure_debut": "09:00:00",
        "heure_fin": "10:00:00",
        "capacite_max": 4,
        "mission": {
          "id": 3,
          "edition_id": 1,
          "nom": "Billetterie",
          "isSensible": true
        }
      }
    }
  ]
}
```

## GET /api/reservations


Requête : sans corps JSON.

Réponse 200 :
```json
{
  "data": [
    {
      "id": 42,
      "statut": "brouillon",
      "validation_admin": "en_attente",
      "created_at": "2026-09-24T12:00:00.000000Z",
      "creneau": {
        "id": 12,
        "jour": "2026-10-09",
        "heure_debut": "09:00:00",
        "heure_fin": "10:00:00",
        "capacite_max": 4,
        "mission": {
          "id": 3,
          "edition_id": 1,
          "nom": "Billetterie",
          "isSensible": true
        }
      }
    }
  ]
}
```

## POST /api/planning/valider

Les réservations passent à `statut: valide`, leur `validation_admin` reste inchangée.
Requête : 
```json
{}
```

Réponse 200 :
```json
{
  "data": {
    "id": 2,
    "nom": "Martin",
    "prenom": "Camille",
    "email": "camille@example.com",
    "telephone": "0600000000",
    "role": "benevole",
    "isMineur": false,
    "statut_planning": "valide",
    "photo_url": null
  }
}
```

## DELETE /api/reservations/42

Annulation permise si `validation_admin: en_attente`, même après validation du planning. Une réservation acceptée reste verrouillée si le planning est validé.
Requête : sans corps JSON.

Réponse 204 :
aucun contenu.

## POST /api/admin/reservations

Admin uniquement. Le statut du planning est conservé ; une mission sensible est acceptée immédiatement.
Requête : 
```json
{
  "user_id": 2,
  "creneau_id": 12
}
```

Réponse 201 :
```json
{
  "data": {
    "id": 42,
    "statut": "brouillon",
    "validation_admin": "acceptee",
    "created_at": "2026-09-24T12:00:00.000000Z",
    "creneau": {
      "id": 12,
      "jour": "2026-10-09",
      "heure_debut": "09:00:00",
      "heure_fin": "10:00:00",
      "capacite_max": 4,
      "mission": {
        "id": 3,
        "edition_id": 1,
        "nom": "Billetterie",
        "isSensible": true
      }
    },
    "user_id": 2
  }
}
```

## GET /api/admin/validations?statut=en_attente

Admin uniquement. Défaut : `en_attente`. Filtres : `en_attente`, `acceptee`, `refusee` ; pagination `page`, `per_page` (50 par défaut, 100 max). Tri `created_at` puis `id` croissants. `links` et `meta` de pagination omis ici. Toutes éditions. `refusee` renvoie une liste vide puisque les refus sont supprimés.
Requête : sans corps JSON.

Réponse 200 :
```json
{
  "data": [
    {
      "id": 42,
      "statut": "brouillon",
      "validation_admin": "en_attente",
      "created_at": "2026-09-24T12:00:00.000000Z",
      "creneau": {
        "id": 12,
        "jour": "2026-10-09",
        "heure_debut": "09:00:00",
        "heure_fin": "10:00:00",
        "capacite_max": 4,
        "mission": {
          "id": 3,
          "edition_id": 1,
          "nom": "Billetterie",
          "isSensible": true
        },
        "places_restantes": 3
      },
      "user_id": 2,
      "user": {
        "id": 2,
        "nom": "Martin",
        "prenom": "Camille",
        "email": "camille@example.com",
        "telephone": "0600000000",
        "role": "benevole",
        "isMineur": false,
        "statut_planning": "brouillon",
        "photo_url": null
      }
    }
  ]
}
```

## PATCH /api/admin/reservations/42/validation — acceptation

Admin uniquement. `statut` n’est pas modifié. Email synchrone après commit via le SMTP configuré ; son échec ne remet pas en cause la décision.
Requête : 
```json
{
  "decision": "acceptee"
}
```

Réponse 200 :
```json
{
  "data": {
    "id": 42,
    "statut": "brouillon",
    "validation_admin": "acceptee",
    "created_at": "2026-09-24T12:00:00.000000Z",
    "creneau": {
      "id": 12,
      "jour": "2026-10-09",
      "heure_debut": "09:00:00",
      "heure_fin": "10:00:00",
      "capacite_max": 4,
      "mission": {
        "id": 3,
        "edition_id": 1,
        "nom": "Billetterie",
        "isSensible": true
      },
      "places_restantes": 3
    },
    "user_id": 2
  }
}
```

## PATCH /api/admin/reservations/42/validation — refus


Requête : 
```json
{
  "decision": "refusee"
}
```

Réponse 200 :
```json
{
  "message": "Demande refusée. La place a été libérée et le planning est à nouveau modifiable."
}
```

## GET /api/admin/creneaux/12/inscrits


Requête : sans corps JSON.

Réponse 200 :
```json
{
  "data": [
    {
      "id": 42,
      "statut": "brouillon",
      "validation_admin": "en_attente",
      "user": {
        "id": 2,
        "nom": "Martin",
        "prenom": "Camille",
        "email": "camille@example.com",
        "telephone": "0600000000",
        "role": "benevole",
        "isMineur": false,
        "statut_planning": "brouillon",
        "photo_url": null
      }
    }
  ],
  "meta": {
    "creneau": {
      "id": 12,
      "jour": "2026-10-09",
      "heure_debut": "09:00:00",
      "heure_fin": "10:00:00",
      "capacite_max": 4,
      "mission": {
        "id": 3,
        "edition_id": 1,
        "nom": "Billetterie",
        "isSensible": true
      },
      "places_restantes": 3
    }
  }
}
```

## GET /api/admin/users

Pagination habituelle conservée (`links`/`meta` omis). Le compteur porte sur toutes les éditions.
Requête : sans corps JSON.

Réponse 200 :
```json
{
  "data": [
    {
      "id": 2,
      "nom": "Martin",
      "prenom": "Camille",
      "email": "camille@example.com",
      "telephone": "0600000000",
      "role": "benevole",
      "isMineur": false,
      "statut_planning": "brouillon",
      "photo_url": null,
      "demandes_en_attente": 1
    }
  ]
}
```

## GET /api/admin/plannings?edition_id=1

Le compteur porte sur l’édition consultée ; défaut : édition active.
Requête : sans corps JSON.

Réponse 200 :
```json
{
  "data": [
    {
      "id": 2,
      "nom": "Martin",
      "prenom": "Camille",
      "email": "camille@example.com",
      "telephone": "0600000000",
      "role": "benevole",
      "isMineur": false,
      "statut_planning": "brouillon",
      "photo_url": null,
      "demandes_en_attente": 1,
      "reservations": [
        {
          "id": 42,
          "statut": "brouillon",
          "validation_admin": "en_attente",
          "created_at": "2026-09-24T12:00:00.000000Z",
          "creneau": {
            "id": 12,
            "jour": "2026-10-09",
            "heure_debut": "09:00:00",
            "heure_fin": "10:00:00",
            "capacite_max": 4,
            "mission": {
              "id": 3,
              "edition_id": 1,
              "nom": "Billetterie",
              "isSensible": true
            },
            "places_restantes": 3
          },
          "user_id": 2
        }
      ]
    }
  ],
  "meta": {
    "edition_id": 1
  }
}
```

## Autres lectures et erreurs

Les routes utilisant les mêmes Resources (`GET /api/admin/users/{id}/planning`, `GET /api/admin/creneaux`, `GET /api/admin/creneaux/{id}`) bénéficient des mêmes champs. Le PDF `/api/planning/pdf` affiche aussi les réservations sensibles et distingue les demandes en attente.

- 401 : token absent/invalide.
- 403 : compte non admin sur les routes admin.
- 409 : capacité, quota, chevauchement, pause ou planning verrouillé.
- 422 : décision/filtre invalide, réservation non sensible, réservation acceptée déjà traitée.
- 404 : réservation absente (y compris après suppression suite à refus).

Exemple d’erreur 422 :
```json
{
  "message": "Cette réservation ne nécessite pas de validation ou a déjà été traitée.",
  "errors": {
    "decision": [
      "Cette réservation ne nécessite pas de validation ou a déjà été traitée."
    ]
  }
}
```

## Migration

Ajout de `validation_admin` nullable et indexé et de `created_at`. Les réservations sensibles existantes deviennent `acceptee`. Leur date de création historique était absente : le nouveau timestamp correspond à la migration, et le tri utilise `id` pour départager. Les nouvelles réservations enregistrent leur date réelle.
