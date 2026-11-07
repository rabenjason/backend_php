<?php

// =====================================================
// FUNBIKE - GESTION DES ADMINS PAR LE SUPERADMIN UNIQUE
// =====================================================

require_once dirname(__DIR__) . "/_lib/auth.php";

funbikeBootstrapApi();

$method = strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");

if (!in_array($method, ["GET", "POST", "PUT", "DELETE"], true)) {
    funbikeJsonResponse(405, [
        "success" => false,
        "code" => "METHOD_NOT_ALLOWED",
        "message" => "Méthode non autorisée pour la gestion des utilisateurs."
    ]);
}

if ($method !== "GET") {
    funbikeRequireTrustedOrigin();
}

$currentUser = funbikeRequireSuperAdmin();

try {
    $store = new FunbikeUserStore();

    // LISTE SÉCURISÉE : aucun hash et aucun mot de passe.
    if ($method === "GET") {
        $users = array_map("funbikeManagedUser", $store->listUsers());

        funbikeJsonResponse(200, [
            "success" => true,
            "users" => $users
        ]);
    }

    // CRÉER UN ADMIN. Le rôle superadmin ne peut pas être demandé ici.
    if ($method === "POST") {
        $body = funbikeReadJsonBody();
        $username = FunbikeUserStore::normalizeUsername($body["username"] ?? "");
        $usernameError = FunbikeUserStore::validateUsername($username);

        if ($usernameError !== null) {
            funbikeJsonResponse(400, [
                "success" => false,
                "code" => "INVALID_USERNAME",
                "message" => $usernameError
            ]);
        }

        if ($store->findByUsername($username)) {
            funbikeJsonResponse(409, [
                "success" => false,
                "code" => "USERNAME_EXISTS",
                "message" => "Ce nom d'utilisateur existe déjà."
            ]);
        }

        $temporaryPassword = FunbikeUserStore::generateTemporaryPassword();
        $newUser = $store->createAdmin($username, $temporaryPassword);

        // Le temporaire est transmis une seule fois au superadmin. Il n'est
        // jamais écrit en clair dans users.json et doit être communiqué en privé.
        funbikeJsonResponse(201, [
            "success" => true,
            "message" => "Admin créé. Transmettez le mot de passe temporaire en privé : il devra le changer à sa première connexion.",
            "user" => funbikeManagedUser($newUser),
            "temporaryPassword" => $temporaryPassword
        ]);
    }

    $id = trim((string) ($_GET["id"] ?? ""));

    if ($id === "") {
        funbikeJsonResponse(400, [
            "success" => false,
            "code" => "USER_ID_REQUIRED",
            "message" => "L'identifiant de l'utilisateur est obligatoire."
        ]);
    }

    $targetUser = $store->findById($id);

    if (!$targetUser) {
        funbikeJsonResponse(404, [
            "success" => false,
            "code" => "USER_NOT_FOUND",
            "message" => "Utilisateur introuvable."
        ]);
    }

    // RÉINITIALISER LE MOT DE PASSE D'UN AUTRE ADMIN.
    if ($method === "PUT") {
        if ((string) $targetUser["id"] === (string) $currentUser["id"]) {
            funbikeJsonResponse(400, [
                "success" => false,
                "code" => "USE_OWN_PASSWORD_CHANGE",
                "message" => "Pour votre propre compte, utilisez la page de changement de mot de passe."
            ]);
        }

        if (($targetUser["role"] ?? "admin") !== "admin") {
            funbikeJsonResponse(403, [
                "success" => false,
                "code" => "SUPERADMIN_RESET_FORBIDDEN",
                "message" => "Le mot de passe du superadmin ne peut pas être réinitialisé ici."
            ]);
        }

        $temporaryPassword = FunbikeUserStore::generateTemporaryPassword();
        $updatedUser = $store->resetAdminPasswordById($id, $temporaryPassword);

        if (!$updatedUser) {
            funbikeJsonResponse(404, [
                "success" => false,
                "code" => "USER_NOT_FOUND",
                "message" => "Cet admin vient d'être supprimé."
            ]);
        }

        funbikeJsonResponse(200, [
            "success" => true,
            "message" => "Mot de passe réinitialisé. Les anciennes sessions de cet admin sont révoquées et le changement sera obligatoire à sa prochaine connexion.",
            "user" => funbikeManagedUser($updatedUser),
            "temporaryPassword" => $temporaryPassword
        ]);
    }

    // SUPPRIMER UN AUTRE ADMIN, JAMAIS LE SUPERADMIN.
    if ($method === "DELETE") {
        if ((string) $targetUser["id"] === (string) $currentUser["id"]) {
            funbikeJsonResponse(400, [
                "success" => false,
                "code" => "CANNOT_DELETE_SELF",
                "message" => "Vous ne pouvez pas supprimer votre propre compte superadmin."
            ]);
        }

        if (($targetUser["role"] ?? "admin") !== "admin") {
            funbikeJsonResponse(403, [
                "success" => false,
                "code" => "CANNOT_DELETE_SUPERADMIN",
                "message" => "Le superadmin ne peut pas être supprimé."
            ]);
        }

        $deletedUser = $store->deleteAdminById($id);

        if (!$deletedUser) {
            funbikeJsonResponse(404, [
                "success" => false,
                "code" => "USER_NOT_FOUND",
                "message" => "Cet admin est déjà supprimé."
            ]);
        }

        funbikeJsonResponse(200, [
            "success" => true,
            "message" => "Admin supprimé. Ses sessions ne sont plus valides.",
            "deletedUser" => funbikeManagedUser($deletedUser)
        ]);
    }
} catch (FunbikeDuplicateUsernameException $error) {
    funbikeJsonResponse(409, [
        "success" => false,
        "code" => "USERNAME_EXISTS",
        "message" => $error->getMessage()
    ]);
} catch (InvalidArgumentException $error) {
    funbikeJsonResponse(400, [
        "success" => false,
        "code" => "INVALID_USER_DATA",
        "message" => $error->getMessage()
    ]);
} catch (Throwable $error) {
    error_log("FUNBIKE user management: " . $error->getMessage());
    funbikeJsonResponse(500, [
        "success" => false,
        "code" => "AUTH_STORAGE_ERROR",
        "message" => "La gestion des utilisateurs est momentanément indisponible."
    ]);
}
