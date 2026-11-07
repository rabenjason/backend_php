<?php
// ==========================================
// FUNBIKE - PRODUCT TYPES API
// server/api/product-types.php
// ==========================================

require_once __DIR__ . "/_lib/auth.php";
funbikeBootstrapApi();

// ==========================================
// 1. CHEMINS
// ==========================================

$dataFile = dirname(__DIR__) . "/storage/data/product-types.json";
$categoriesFile = dirname(__DIR__) . "/storage/data/categories.json";
$familiesFile = dirname(__DIR__) . "/storage/data/families.json";

// ==========================================
// 2. FONCTIONS
// ==========================================

function response($status, $data) {
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    exit;
}

// Générer automatiquement un ID
function slugify($text) {
    $text = trim($text);

    if (function_exists("iconv")) {
        $converted = iconv(
            "UTF-8",
            "ASCII//TRANSLIT//IGNORE",
            $text
        );

        if ($converted !== false) {
            $text = $converted;
        }
    }

    $text = strtolower($text);
    $text = preg_replace("/[^a-z0-9]+/", "-", $text);

    return trim($text, "-");
}

// Lire un fichier JSON
function readJson($file, $key) {
    if (!file_exists($file)) {
        return [];
    }

    $content = file_get_contents($file);

    if ($content === false) {
        response(500, [
            "error" => "Impossible de lire le fichier JSON."
        ]);
    }

    $data = json_decode($content, true);

    if (
        !is_array($data) ||
        !isset($data[$key]) ||
        !is_array($data[$key])
    ) {
        response(500, [
            "error" => "Structure JSON invalide.",
            "file" => basename($file)
        ]);
    }

    return $data[$key];
}

// Récupérer les données envoyées
function getRequestData() {
    $data = $_POST;

    $raw = file_get_contents("php://input");

    if (!empty($raw)) {
        $json = json_decode($raw, true);

        if (is_array($json)) {
            $data = array_merge($data, $json);
        } else {
            $parsed = [];
            parse_str($raw, $parsed);
            $data = array_merge($data, $parsed);
        }
    }

    return $data;
}

// Nettoyer les données : 3 champs obligatoires + familyId facultatif
function cleanProductTypes($types) {
    $result = [];

    foreach ($types as $type) {
        if (
            !is_array($type) ||
            !isset($type["id"], $type["name"], $type["categoryId"])
        ) {
            continue;
        }

        $clean = [
            "id" => (string) $type["id"],
            "name" => (string) $type["name"],
            "categoryId" => (string) $type["categoryId"]
        ];

        if (isset($type["familyId"]) && trim((string) $type["familyId"]) !== "") {
            $clean["familyId"] = trim((string) $type["familyId"]);
        }

        $result[] = $clean;
    }

    return $result;
}

// Enregistrer les types
function saveProductTypes($file, $types) {
    $directory = dirname($file);

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $data = [
        "productTypes" => cleanProductTypes($types)
    ];

    $json = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    return file_put_contents($file, $json, LOCK_EX) !== false;
}

// Vérifier si une catégorie existe
function categoryExists($categoriesFile, $categoryId) {
    $categories = readJson($categoriesFile, "categories");

    foreach ($categories as $category) {
        if (
            isset($category["id"]) &&
            (string) $category["id"] === $categoryId
        ) {
            return true;
        }
    }

    return false;
}

// Lire familyId envoyé : null ou une chaîne vide signifie "aucune famille".
function requestedFamilyId($body) {
    if (!array_key_exists("familyId", $body) || $body["familyId"] === null) {
        return null;
    }

    if (!is_string($body["familyId"]) && !is_numeric($body["familyId"])) {
        response(400, ["error" => "familyId doit être un identifiant texte ou null."]);
    }

    $familyId = trim((string) $body["familyId"]);

    return $familyId === "" ? null : $familyId;
}

// Vérifier l'existence de la famille et sa cohérence avec la catégorie.
function validateFamilyAssignment($familiesFile, $familyId, $categoryId) {
    if ($familyId === null) {
        return;
    }

    $families = readJson($familiesFile, "families");

    foreach ($families as $family) {
        if (!isset($family["id"]) || (string) $family["id"] !== $familyId) {
            continue;
        }

        if (($family["categoryId"] ?? null) !== $categoryId) {
            response(400, [
                "error" => "Cette famille n'appartient pas à la catégorie choisie.",
                "requested_familyId" => $familyId,
                "requested_categoryId" => $categoryId
            ]);
        }

        return;
    }

    response(400, [
        "error" => "Cette famille n'existe pas.",
        "requested_familyId" => $familyId
    ]);
}

// Trouver un type par ID
function findTypeIndex($types, $id) {
    foreach ($types as $index => $type) {
        if ($type["id"] === $id) {
            return $index;
        }
    }

    return -1;
}

// ==========================================
// 3. MÉTHODE ET DONNÉES
// ==========================================

$method = strtoupper($_SERVER["REQUEST_METHOD"]);
$body = getRequestData();

// Support POST avec _method
if (
    $method === "POST" &&
    isset($body["_method"])
) {
    $method = strtoupper($body["_method"]);
}

funbikeProtectWriteMethods($method);

$id = trim((string) (
    $_GET["id"] ?? $body["id"] ?? ""
));

$types = cleanProductTypes(
    readJson($dataFile, "productTypes")
);

// ==========================================
// 4. GET - AFFICHER
// ==========================================

if ($method === "GET") {
    response(200, [
        "productTypes" => $types
    ]);
}

// ==========================================
// 5. POST - AJOUTER
// ==========================================

if ($method === "POST") {

    $name = trim($body["name"] ?? "");
    $categoryId = trim($body["categoryId"] ?? "");

    if ($name === "" || $categoryId === "") {
        response(400, [
            "error" => "Le nom et la catégorie sont obligatoires."
        ]);
    }

    // Vérifier la catégorie associée
    if (!categoryExists($categoriesFile, $categoryId)) {
        response(400, [
            "error" => "Cette catégorie n'existe pas.",
            "requested_categoryId" => $categoryId
        ]);
    }

    $familyId = requestedFamilyId($body);
    validateFamilyAssignment($familiesFile, $familyId, $categoryId);

    $newId = slugify($name);

    if ($newId === "") {
        response(400, [
            "error" => "Impossible de générer un identifiant."
        ]);
    }

    // Vérifier les doublons
    if (findTypeIndex($types, $newId) !== -1) {
        response(409, [
            "error" => "Ce type de produit existe déjà.",
            "requested_id" => $newId
        ]);
    }

    $newType = [
        "id" => $newId,
        "name" => $name,
        "categoryId" => $categoryId
    ];

    if ($familyId !== null) {
        $newType["familyId"] = $familyId;
    }

    $types[] = $newType;

    if (!saveProductTypes($dataFile, $types)) {
        response(500, [
            "error" => "Erreur lors de l'enregistrement."
        ]);
    }

    response(201, [
        "message" => "Type de produit ajouté avec succès.",
        "productType" => $newType
    ]);
}

// ==========================================
// 6. PUT - MODIFIER
// ==========================================

if ($method === "PUT") {

    if ($id === "") {
        response(400, [
            "error" => "L'ID du type est obligatoire."
        ]);
    }

    $name = trim($body["name"] ?? "");
    $categoryId = trim($body["categoryId"] ?? "");

    if ($name === "" || $categoryId === "") {
        response(400, [
            "error" => "Le nom et la catégorie sont obligatoires."
        ]);
    }

    if (!categoryExists($categoriesFile, $categoryId)) {
        response(400, [
            "error" => "Cette catégorie n'existe pas.",
            "requested_categoryId" => $categoryId
        ]);
    }

    $index = findTypeIndex($types, $id);

    if ($index === -1) {
        response(404, [
            "error" => "Type de produit introuvable.",
            "requested_id" => $id
        ]);
    }

    // Conserver la famille si elle n'est pas envoyée et que la catégorie ne
    // change pas. Lors d'un changement de catégorie, l'ancienne famille est
    // retirée pour éviter une association incohérente.
    $familyId = $types[$index]["familyId"] ?? null;

    if (array_key_exists("familyId", $body)) {
        $familyId = requestedFamilyId($body);
    } elseif ($categoryId !== $types[$index]["categoryId"]) {
        $familyId = null;
    }

    validateFamilyAssignment($familiesFile, $familyId, $categoryId);

    // Conserver l'ancien ID
    $types[$index]["name"] = $name;
    $types[$index]["categoryId"] = $categoryId;

    if ($familyId === null) {
        unset($types[$index]["familyId"]);
    } else {
        $types[$index]["familyId"] = $familyId;
    }

    if (!saveProductTypes($dataFile, $types)) {
        response(500, [
            "error" => "Erreur lors de la modification."
        ]);
    }

    response(200, [
        "message" => "Type de produit modifié avec succès.",
        "productType" => $types[$index]
    ]);
}

// ==========================================
// 7. DELETE - SUPPRIMER
// ==========================================

if ($method === "DELETE") {

    if ($id === "") {
        response(400, [
            "error" => "L'ID du type est obligatoire."
        ]);
    }

    $index = findTypeIndex($types, $id);

    if ($index === -1) {
        response(404, [
            "error" => "Type de produit introuvable.",
            "requested_id" => $id
        ]);
    }

    $deletedType = $types[$index];

    unset($types[$index]);

    $types = array_values($types);

    if (!saveProductTypes($dataFile, $types)) {
        response(500, [
            "error" => "Erreur lors de la suppression."
        ]);
    }

    response(200, [
        "message" => "Type de produit supprimé avec succès.",
        "productType" => $deletedType
    ]);
}

// ==========================================
// 8. MÉTHODE NON AUTORISÉE
// ==========================================

response(405, [
    "error" => "Méthode non autorisée."
]);
