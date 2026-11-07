<?php

require_once dirname(__DIR__) . "/_lib/auth.php";

funbikeBootstrapApi();

if (strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
    funbikeJsonResponse(405, [
        "success" => false,
        "code" => "METHOD_NOT_ALLOWED",
        "message" => "Utilisez POST pour vous déconnecter."
    ]);
}

funbikeRequireTrustedOrigin();
funbikeClearSession();

funbikeJsonResponse(200, [
    "success" => true,
    "message" => "Déconnexion réussie."
]);
