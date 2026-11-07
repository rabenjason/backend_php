<?php

// Change le mot de passe du compte connecté, y compris à la première connexion.

require_once dirname(__DIR__) . "/_lib/auth.php";

funbikeBootstrapApi();

if (strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
    funbikeJsonResponse(405, [
        "success" => false,
        "code" => "METHOD_NOT_ALLOWED",
        "message" => "Utilisez POST pour changer le mot de passe."
    ]);
}

funbikeRequireTrustedOrigin();

// true : cette route reste accessible avec un mot de passe temporaire.
$user = funbikeRequireAuthenticatedUser(true);
$body = funbikeReadJsonBody();

$currentPassword = $body["currentPassword"] ?? "";
$newPassword = $body["newPassword"] ?? "";
$confirmation = $body["newPasswordConfirmation"] ?? "";

if (
    !is_string($currentPassword) || $currentPassword === "" ||
    !is_string($newPassword) || $newPassword === "" ||
    !is_string($confirmation) || $confirmation === ""
) {
    funbikeJsonResponse(400, [
        "success" => false,
        "code" => "PASSWORDS_REQUIRED",
        "message" => "Le mot de passe actuel, le nouveau mot de passe et sa confirmation sont obligatoires."
    ]);
}

if (!password_verify($currentPassword, (string) $user["passwordHash"])) {
    funbikeJsonResponse(400, [
        "success" => false,
        "code" => "INVALID_CURRENT_PASSWORD",
        "message" => "Le mot de passe actuel est incorrect."
    ]);
}

$passwordError = FunbikeUserStore::validatePassword($newPassword);

if ($passwordError !== null) {
    funbikeJsonResponse(400, [
        "success" => false,
        "code" => "WEAK_PASSWORD",
        "message" => $passwordError
    ]);
}

if ($newPassword !== $confirmation) {
    funbikeJsonResponse(400, [
        "success" => false,
        "code" => "PASSWORD_CONFIRMATION_MISMATCH",
        "message" => "La confirmation ne correspond pas au nouveau mot de passe."
    ]);
}

if (password_verify($newPassword, (string) $user["passwordHash"])) {
    funbikeJsonResponse(400, [
        "success" => false,
        "code" => "PASSWORD_UNCHANGED",
        "message" => "Le nouveau mot de passe doit être différent de l'ancien."
    ]);
}

if (strtolower($newPassword) === (string) $user["username"]) {
    funbikeJsonResponse(400, [
        "success" => false,
        "code" => "WEAK_PASSWORD",
        "message" => "Le mot de passe ne peut pas être identique au nom d'utilisateur."
    ]);
}

try {
    $store = new FunbikeUserStore();
    $updatedUser = $store->changePassword((string) $user["id"], $newPassword);
} catch (Throwable $error) {
    error_log("FUNBIKE password change: " . $error->getMessage());
    funbikeJsonResponse(500, [
        "success" => false,
        "code" => "AUTH_STORAGE_ERROR",
        "message" => "Impossible d'enregistrer le nouveau mot de passe."
    ]);
}

if (!$updatedUser) {
    funbikeClearSession();
    funbikeJsonResponse(401, [
        "success" => false,
        "code" => "AUTH_REQUIRED",
        "message" => "Le compte n'existe plus. Reconnectez-vous."
    ]);
}

// Invalide les autres sessions grâce à authVersion, puis renouvelle celle-ci.
session_regenerate_id(true);
$_SESSION["auth_version"] = (int) $updatedUser["authVersion"];
$_SESSION["login_at"] = time();

funbikeJsonResponse(200, [
    "success" => true,
    "message" => "Mot de passe changé. Vous pouvez maintenant administrer le site.",
    "mustChangePassword" => false,
    "user" => funbikePublicUser($updatedUser)
]);
