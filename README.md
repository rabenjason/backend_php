# Backend FunBike

API PHP avec stockage JSON. Le catalogue reste consultable publiquement, mais toutes les modifications sont réservées aux administrateurs connectés.

Prérequis : PHP 7.4 ou supérieur (PHP 8 recommandé), les sessions PHP activées et un dossier privé accessible en écriture par PHP.

## 1. Règles d'accès

| Requête | Visiteur | Admin avec mot de passe temporaire | Admin avec mot de passe définitif |
|---|---:|---:|---:|
| `GET` sur les catalogues et coordonnées | Oui | Oui | Oui |
| Connexion et consultation de sa propre session | Oui | Oui | Oui |
| Changement de son propre mot de passe | Non | **Oui, obligatoire** | Oui |
| `POST`, `PUT`, `DELETE` sur le contenu | Non | **Non** | Oui |

Il n'y a volontairement **aucune route pour lister les utilisateurs** et aucune route publique pour créer un administrateur.

## 2. Créer le premier administrateur

Sur le serveur, dans le dossier du backend :

```bash
php scripts/create-admin.php admin
```

Le terminal affiche un mot de passe temporaire aléatoire. Il faut le transmettre à l'administrateur par un canal privé. Aucun compte ni mot de passe par défaut n'est inclus dans le projet.

Pour réinitialiser le mot de passe d'un compte existant :

```bash
php scripts/reset-admin-password.php admin
```

Cette commande révoque aussi ses sessions existantes et impose de nouveau le changement de mot de passe.

Pour un déploiement automatisé, il est possible de fournir le temporaire via la variable `FUNBIKE_TEMP_PASSWORD`. Ne pas mettre cette valeur dans Git ni dans une URL.

## 3. Parcours de première connexion

### Connexion

```http
POST /server/api/auth/login.php
Content-Type: application/json

{
  "username": "admin",
  "password": "MOT_DE_PASSE_TEMPORAIRE"
}
```

La réponse contient `"mustChangePassword": true`. Le frontend doit alors afficher **uniquement la page de changement de mot de passe**, sans ouvrir l'administration du catalogue.

### Changement obligatoire

```http
POST /server/api/auth/change-password.php
Content-Type: application/json

{
  "currentPassword": "MOT_DE_PASSE_TEMPORAIRE",
  "newPassword": "UN_NOUVEAU_MDP_DU_USER",
  "newPasswordConfirmation": "UN_NOUVEAU_MDP_DU_USER"
}
```

Le nouveau mot de passe doit comporter entre 12 et 72 octets et être différent de l'ancien. Après cette étape, `mustChangePassword` devient `false` et l'accès administrateur est ouvert.

Si le frontend essaie quand même une écriture avant cette étape, l'API répond `403` avec le code `PASSWORD_CHANGE_REQUIRED`. La restriction est donc appliquée par le serveur, pas seulement par React.

### Exemples JavaScript

Le cookie de session doit accompagner toutes les requêtes d'authentification et toutes les écritures :

```js
async function api(url, options = {}) {
  const response = await fetch(url, {
    ...options,
    credentials: "include",
    headers: {
      "Content-Type": "application/json",
      ...(options.headers || {})
    }
  });

  const data = await response.json();

  if (!response.ok) {
    throw data;
  }

  return data;
}

await api("/server/api/auth/login.php", {
  method: "POST",
  body: JSON.stringify({ username, password })
});

await api("/server/api/auth/change-password.php", {
  method: "POST",
  body: JSON.stringify({
    currentPassword,
    newPassword,
    newPasswordConfirmation
  })
});
```

Pour les formulaires produits utilisant `FormData`, ajouter aussi `credentials: "include"`, mais **ne pas définir manuellement** `Content-Type` : le navigateur doit générer la frontière multipart.

Autres routes :

- `GET /server/api/auth/me.php` : compte connecté et état `mustChangePassword` ;
- `POST /server/api/auth/logout.php` : déconnexion.

Les réponses d'authentification ne contiennent jamais `passwordHash`.

## 4. Confidentialité de `users.json`

Les comptes sont enregistrés dans :

```text
storage/private/users.json
```

Exemple de structure interne :

```json
{
  "users": [
    {
      "id": "identifiant-aleatoire",
      "username": "admin",
      "passwordHash": "$2y$...",
      "role": "admin",
      "mustChangePassword": true,
      "authVersion": 1
    }
  ]
}
```

Le mot de passe temporaire et le mot de passe définitif ne sont **jamais stockés en clair**. `password_hash()` crée l'empreinte et `password_verify()` vérifie la connexion.

Protections mises en place :

- aucun endpoint `GET users` ou équivalent ;
- `storage/.htaccess` refuse l'accès HTTP direct à tous les JSON ;
- `api/_lib/.htaccess` et `scripts/.htaccess` bloquent les fichiers internes ;
- les scripts de création/réinitialisation refusent toute exécution hors CLI ;
- le vrai `users.json`, ses verrous et ses fichiers temporaires sont exclus de Git ;
- écriture atomique et verrouillage pour éviter un JSON corrompu.

### Apache

Vérifier que les fichiers `.htaccess` sont pris en compte (`AllowOverride All` ou au minimum `AllowOverride AuthConfig`). Tester ensuite que l'URL suivante renvoie bien `403` ou `404`, jamais `200` :

```text
/server/storage/private/users.json
```

### Nginx ou hébergement sans `.htaccess`

`.htaccess` n'est pas lu par Nginx. Ajouter des règles équivalentes, par exemple en adaptant le préfixe `/server` :

```nginx
location ^~ /server/storage/ { deny all; }
location ^~ /server/api/_lib/ { deny all; }
location ^~ /server/scripts/ { deny all; }
```

La protection la plus forte consiste à placer le fichier **en dehors de la racine web** :

```bash
export FUNBIKE_USERS_FILE=/chemin/prive/hors-web/funbike-users.json
```

Cette variable doit avoir la même valeur pour PHP-FPM/Apache et pour les commandes CLI. Le dossier doit être accessible en écriture par le processus PHP, mais jamais servi par le web.

## 5. Configuration frontend et HTTPS

Si React et l'API ont des origines différentes, définir les origines autorisées sans `/` final :

```bash
export FUNBIKE_ALLOWED_ORIGINS=https://www.funbike.mg,https://admin.funbike.mg
```

Les origines locales `localhost:3000`, `localhost:5173` et leurs équivalents `127.0.0.1` sont acceptées par défaut pour le développement. L'origine du serveur lui-même est aussi acceptée. Une connexion ou une écriture portant l'en-tête `Origin` d'un site non autorisé est refusée avec `403 ORIGIN_NOT_ALLOWED`, même si un cookie de session est présent.

Le cookie est `HttpOnly` et utilise `SameSite=Lax` par défaut. Pour un frontend et une API sur des **sites** différents, utiliser HTTPS et :

```bash
export FUNBIKE_COOKIE_SAMESITE=None
export FUNBIKE_COOKIE_SECURE=1
```

Ne pas utiliser `SameSite=None` sans HTTPS en production.

## 6. Comportement des anciennes routes

Les routes suivantes continuent à répondre publiquement en `GET` :

- marques, catégories, modèles, types de produits ;
- motos, casques, pièces détachées et équipements ;
- dealers, contacts et nouveautés de l'accueil.

Leurs routes `POST`, `PUT` et `DELETE` passent désormais par `funbikeProtectWriteMethods()`. Sans session, elles répondent `401 AUTH_REQUIRED`. Avec un mot de passe temporaire non changé, elles répondent `403 PASSWORD_CHANGE_REQUIRED`.
