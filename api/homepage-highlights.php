<?php

// =====================================================
// FUNBIKE - NOUVEAUTÉS DE LA PAGE D'ACCUEIL
// GET uniquement
// =====================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

$method = strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");

if ($method === "OPTIONS") {
    http_response_code(200);
    exit;
}

// =====================================================
// FONCTION DE RÉPONSE
// =====================================================

function respond(
    $success,
    $data = null,
    $message = "",
    $status = 200,
    $meta = null
) {
    http_response_code($status);

    $response = [
        "success" => $success,
        "message" => $message,
        "data" => $data
    ];

    if ($meta !== null) {
        $response["meta"] = $meta;
    }

    echo json_encode(
        $response,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

if ($method !== "GET") {
    respond(false, null, "Méthode non autorisée.", 405);
}

// =====================================================
// CONFIGURATION
// =====================================================

// Ce fichier se trouve dans /server/api.
$serverRoot = dirname(__DIR__);
$productsDir = $serverRoot . "/storage/data/products";

$maxHighlights = 3;

// L'ordre détermine l'ordre des produits retournés.
$files = [
    $productsDir . "/motorcycles.json",
    $productsDir . "/spare-parts.json",
    $productsDir . "/helmets.json",
    $productsDir . "/riding-gear.json"
];

// =====================================================
// LECTURE DES PRODUITS
// =====================================================

$markedCount = 0;
$publishedHighlights = [];

foreach ($files as $file) {
    $content = @file_get_contents($file);

    if ($content === false) {
        respond(
            false,
            null,
            "Impossible de lire le fichier " . basename($file) . ".",
            500
        );
    }

    $data = json_decode($content, true);

    if (
        !is_array($data) ||
        !isset($data["products"]) ||
        !is_array($data["products"])
    ) {
        respond(
            false,
            null,
            "Le fichier " . basename($file) . " est invalide.",
            500
        );
    }

    foreach ($data["products"] as $product) {
        if (!is_array($product)) {
            continue;
        }

        if (($product["isNewArrival"] ?? false) !== true) {
            continue;
        }

        // Compte tous les produits marqués true,
        // y compris ceux qui ne sont pas publiés.
        $markedCount++;

        // Seuls les produits publiés sont envoyés à la page d'accueil.
        if (($product["status"] ?? null) === "PUBLISHED") {
            $publishedHighlights[] = $product;
        }
    }
}

// =====================================================
// RÉPONSE
// =====================================================

$displayedProducts = array_slice(
    $publishedHighlights,
    0,
    $maxHighlights
);

$meta = [
    "maxHighlights" => $maxHighlights,
    "markedCount" => $markedCount,
    "publishedCount" => count($publishedHighlights),
    "displayedCount" => count($displayedProducts),
    "remainingSlots" => max(0, $maxHighlights - $markedCount),
    "limitReached" => $markedCount >= $maxHighlights,
    "limitExceeded" => $markedCount > $maxHighlights
];

respond(
    true,
    $displayedProducts,
    "Nouveautés récupérées.",
    200,
    $meta
);