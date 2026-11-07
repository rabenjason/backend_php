<?php

// =====================================================
// FUNBIKE - SESSIONS ET AUTORISATIONS ADMIN
// =====================================================

require_once __DIR__ . "/http.php";
require_once __DIR__ . "/user-store.php";

function funbikeStartSession()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $sameSite = getenv("FUNBIKE_COOKIE_SAMESITE");
    $sameSite = $sameSite === false ? "Lax" : ucfirst(strtolower($sameSite));

    if (!in_array($sameSite, ["Lax", "Strict", "None"], true)) {
        $sameSite = "Lax";
    }

    $https = (
        (!empty($_SERVER["HTTPS"]) && strtolower($_SERVER["HTTPS"]) !== "off") ||
        (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https") ||
        (($_SERVER["SERVER_PORT"] ?? null) == 443)
    );

    // SameSite=None exige Secure. C'est nécessaire si le frontend et l'API
    // sont sur des sites différents. En local et sur le même site, Lax suffit.
    $secure = $https || $sameSite === "None" || getenv("FUNBIKE_COOKIE_SECURE") === "1";

    session_name("funbike_admin");
    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => $secure,
        "httponly" => true,
        "samesite" => $sameSite
    ]);
    session_start();
}

function funbikeClearSession()
{
    funbikeStartSession();

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            "",
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
}

function funbikeCurrentUser()
{
    funbikeStartSession();

    if (empty($_SESSION["user_id"])) {
        return null;
    }

    try {
        $store = new FunbikeUserStore();
        $user = $store->findById((string) $_SESSION["user_id"]);
    } catch (Throwable $error) {
        error_log("FUNBIKE auth: " . $error->getMessage());
        funbikeJsonResponse(500, [
            "success" => false,
            "code" => "AUTH_STORAGE_ERROR",
            "message" => "Le service d'authentification est indisponible."
        ]);
    }

    $sessionVersion = (int) ($_SESSION["auth_version"] ?? 0);
    $userVersion = (int) ($user["authVersion"] ?? 1);

    // Le compte peut avoir été supprimé ou son mot de passe réinitialisé.
    if (!$user || $sessionVersion !== $userVersion) {
        funbikeClearSession();
        return null;
    }

    return $user;
}

/**
 * Connexion obligatoire. Le changement initial de mot de passe est autorisé
 * uniquement lorsque $allowPasswordChange vaut true.
 */
function funbikeRequireAuthenticatedUser($allowPasswordChange = false)
{
    $user = funbikeCurrentUser();

    if (!$user) {
        funbikeJsonResponse(401, [
            "success" => false,
            "code" => "AUTH_REQUIRED",
            "message" => "Connexion administrateur requise."
        ]);
    }

    if (!in_array($user["role"] ?? "admin", ["admin", "superadmin"], true)) {
        funbikeJsonResponse(403, [
            "success" => false,
            "code" => "ADMIN_REQUIRED",
            "message" => "Droits administrateur requis."
        ]);
    }

    if (!$allowPasswordChange && !empty($user["mustChangePassword"])) {
        funbikeJsonResponse(403, [
            "success" => false,
            "code" => "PASSWORD_CHANGE_REQUIRED",
            "message" => "Vous devez changer votre mot de passe temporaire avant de continuer.",
            "mustChangePassword" => true
        ]);
    }

    return $user;
}

function funbikeRequireAdmin()
{
    return funbikeRequireAuthenticatedUser(false);
}

/** Seul le superadmin unique peut gérer les comptes utilisateurs. */
function funbikeRequireSuperAdmin()
{
    $user = funbikeRequireAuthenticatedUser(false);

    if (($user["role"] ?? "admin") !== "superadmin") {
        funbikeJsonResponse(403, [
            "success" => false,
            "code" => "SUPERADMIN_REQUIRED",
            "message" => "Seul le superadmin peut gérer les utilisateurs."
        ]);
    }

    return $user;
}

/** Les consultations restent publiques ; toute écriture exige un admin. */
function funbikeProtectWriteMethods($method)
{
    $method = strtoupper((string) $method);

    if (!in_array($method, ["GET", "OPTIONS"], true)) {
        funbikeRequireTrustedOrigin();
        funbikeRequireAdmin();
    }
}
