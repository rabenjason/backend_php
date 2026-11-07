<?php

// Retourne uniquement le compte actuellement connecté, jamais la liste des users.

require_once dirname(__DIR__) . "/_lib/auth.php";

funbikeBootstrapApi();

if (strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET") !== "GET") {
    funbikeJsonResponse(405, [
        "success" => false,
        "code" => "METHOD_NOT_ALLOWED",
        "message" => "Utilisez GET pour consulter la session."
    ]);
}

$user = funbikeRequireAuthenticatedUser(true);

funbikeJsonResponse(200, [
    "success" => true,
    "mustChangePassword" => !empty($user["mustChangePassword"]),
    "user" => funbikePublicUser($user)
]);
