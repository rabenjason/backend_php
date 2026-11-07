<?php

// Connexion d'un administrateur avec son mot de passe actuel ou temporaire.

require_once dirname(__DIR__) . "/_lib/auth.php";

funbikeBootstrapApi();

if (strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
    funbikeJsonResponse(405, [
        "success" => false,
        "code" => "METHOD_NOT_ALLOWED",
        "message" => "Utilisez POST pour vous connecter."
    ]);
}

funbikeRequireTrustedOrigin();
$body = funbikeReadJsonBody();
$username = FunbikeUserStore::normalizeUsername($body["username"] ?? "");
$password = $body["password"] ?? "";

if ($username === "" || !is_string($password) || $password === "") {
    funbikeJsonResponse(400, [
        "success" => false,
        "code" => "CREDENTIALS_REQUIRED",
        "message" => "Le nom d'utilisateur et le mot de passe sont obligatoires."
    ]);
}

try {
    $store = new FunbikeUserStore();
    $user = $store->findByUsername($username);
} catch (Throwable $error) {
    error_log("FUNBIKE login: " . $error->getMessage());
    funbikeJsonResponse(500, [
        "success" => false,
        "code" => "AUTH_STORAGE_ERROR",
        "message" => "Le service d'authentification est indisponible."
    ]);
}

// Message volontairement identique pour ne pas révéler les noms existants.
if (!$user || !password_verify($password, (string) ($user["passwordHash"] ?? ""))) {
    usleep(250000);
    funbikeJsonResponse(401, [
        "success" => false,
        "code" => "INVALID_CREDENTIALS",
        "message" => "Nom d'utilisateur ou mot de passe incorrect."
    ]);
}

funbikeStartSession();
session_regenerate_id(true);
$_SESSION["user_id"] = $user["id"];
$_SESSION["auth_version"] = (int) ($user["authVersion"] ?? 1);
$_SESSION["login_at"] = time();

$mustChangePassword = !empty($user["mustChangePassword"]);

funbikeJsonResponse(200, [
    "success" => true,
    "message" => $mustChangePassword
        ? "Connexion réussie. Changez maintenant votre mot de passe temporaire."
        : "Connexion réussie.",
    "mustChangePassword" => $mustChangePassword,
    "user" => funbikePublicUser($user)
]);
