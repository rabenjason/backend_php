<?php

// =====================================================
// FUNBIKE - CASQUES ET ÉQUIPEMENTS DE LA PAGE D'ACCUEIL
// GET uniquement : isEquipementAccueil=true et status=PUBLISHED
// =====================================================

require_once __DIR__ . "/_lib/http.php";
funbikeBootstrapApi();

function respond($success, $data = null, $message = "", $status = 200, $meta = null)
{
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

$method = strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");

if ($method !== "GET") {
    respond(false, null, "Méthode non autorisée.", 405);
}

$productsDir = dirname(__DIR__) . "/storage/data/products";
$files = [
    $productsDir . "/helmets.json",
    $productsDir . "/riding-gear.json"
];

$markedCount = 0;
$publishedEquipment = [];

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

    if (!is_array($data) || !isset($data["products"]) || !is_array($data["products"])) {
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

        // Un ancien produit sans ce champ n'est pas affiché sur l'accueil.
        if (($product["isEquipementAccueil"] ?? false) !== true) {
            continue;
        }

        $markedCount++;

        // Un brouillon ne doit jamais apparaître publiquement sur l'accueil.
        if (($product["status"] ?? null) === "PUBLISHED") {
            $publishedEquipment[] = $product;
        }
    }
}

respond(
    true,
    $publishedEquipment,
    "Casques et équipements de la page d'accueil récupérés.",
    200,
    [
        "markedCount" => $markedCount,
        "publishedCount" => count($publishedEquipment),
        "displayedCount" => count($publishedEquipment)
    ]
);
