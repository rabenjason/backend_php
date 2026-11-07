<?php

// =====================================================
// FUNBIKE - OUTILS HTTP PARTAGÉS
// =====================================================

/**
 * Origines autorisées à utiliser les sessions d'administration.
 *
 * En production, définir FUNBIKE_ALLOWED_ORIGINS avec les URL du frontend,
 * séparées par des virgules (sans / final). Exemple :
 * https://www.funbike.mg,https://admin.funbike.mg
 */
function funbikeAllowedOrigins()
{
    $defaults = [
        "http://localhost:3000",
        "http://localhost:5173",
        "http://127.0.0.1:3000",
        "http://127.0.0.1:5173"
    ];

    $configured = getenv("FUNBIKE_ALLOWED_ORIGINS");

    if ($configured === false || trim($configured) === "") {
        return $defaults;
    }

    return array_values(array_filter(array_map(
        "trim",
        explode(",", $configured)
    )));
}

function funbikeServerOrigin()
{
    $host = trim((string) ($_SERVER["HTTP_HOST"] ?? ""));

    if ($host === "") {
        return "";
    }

    $https = (
        (!empty($_SERVER["HTTPS"]) && strtolower($_SERVER["HTTPS"]) !== "off") ||
        (($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https") ||
        (($_SERVER["SERVER_PORT"] ?? null) == 443)
    );

    return ($https ? "https://" : "http://") . $host;
}

function funbikeIsTrustedOrigin($origin)
{
    if ($origin === "") {
        return true;
    }

    if (in_array($origin, funbikeAllowedOrigins(), true)) {
        return true;
    }

    $serverOrigin = funbikeServerOrigin();

    return $serverOrigin !== "" && hash_equals(strtolower($serverOrigin), strtolower($origin));
}

/**
 * Bloque aussi la requête, pas seulement la lecture de sa réponse par CORS.
 * Les clients non navigateur sans Origin peuvent utiliser leur session normalement.
 */
function funbikeRequireTrustedOrigin()
{
    $origin = trim((string) ($_SERVER["HTTP_ORIGIN"] ?? ""));

    if (!funbikeIsTrustedOrigin($origin)) {
        funbikeJsonResponse(403, [
            "success" => false,
            "code" => "ORIGIN_NOT_ALLOWED",
            "message" => "Cette origine n'est pas autorisée à administrer FunBike."
        ]);
    }
}

/**
 * En-têtes JSON/CORS communs et réponse aux précontrôles OPTIONS.
 *
 * Les GET publics restent accessibles depuis n'importe quelle origine.
 * Une origine doit être explicitement autorisée pour envoyer le cookie
 * de session d'administration.
 */
function funbikeBootstrapApi()
{
    header("Content-Type: application/json; charset=UTF-8");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Access-Control-Max-Age: 600");
    header("Vary: Origin");
    header("X-Content-Type-Options: nosniff");

    $origin = trim((string) ($_SERVER["HTTP_ORIGIN"] ?? ""));

    if ($origin !== "" && funbikeIsTrustedOrigin($origin)) {
        header("Access-Control-Allow-Origin: " . $origin);
        header("Access-Control-Allow-Credentials: true");
    } else {
        header("Access-Control-Allow-Origin: *");
    }

    $method = strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");

    if ($method === "OPTIONS") {
        http_response_code(204);
        exit;
    }
}

function funbikeJsonResponse($status, $data)
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    exit;
}

function funbikeReadJsonBody()
{
    $raw = file_get_contents("php://input");

    if ($raw === false || trim($raw) === "") {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        funbikeJsonResponse(400, [
            "success" => false,
            "code" => "INVALID_JSON",
            "message" => "Le corps de la requête doit être un objet JSON valide."
        ]);
    }

    return $data;
}
