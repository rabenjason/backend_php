<?php
// ==========================================
// FUNBIKE - FAMILLES DE PRODUITS API
// GET public / POST, PUT, DELETE admin
// ==========================================

require_once __DIR__ . "/_lib/auth.php";
funbikeBootstrapApi();

$dataFile = dirname(__DIR__) . "/storage/data/families.json";
$categoriesFile = dirname(__DIR__) . "/storage/data/categories.json";

function response($status, $data) {
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

function slugify($text) {
    $text = trim($text);

    if (function_exists("iconv")) {
        $converted = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }

    $text = strtolower($text);
    $text = preg_replace("/[^a-z0-9]+/", "-", $text);

    return trim($text, "-");
}

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

function readJson($file, $key) {
    if (!file_exists($file)) {
        return [];
    }

    $content = file_get_contents($file);

    if ($content === false) {
        response(500, ["error" => "Impossible de lire le fichier JSON."]);
    }

    $data = json_decode($content, true);

    if (!is_array($data) || !isset($data[$key]) || !is_array($data[$key])) {
        response(500, [
            "error" => "Structure JSON invalide.",
            "file" => basename($file)
        ]);
    }

    return $data[$key];
}

function cleanFamilies($families) {
    $result = [];

    foreach ($families as $family) {
        if (!is_array($family) || !isset($family["id"], $family["name"], $family["categoryId"])) {
            continue;
        }

        $result[] = [
            "id" => (string) $family["id"],
            "name" => (string) $family["name"],
            "categoryId" => (string) $family["categoryId"]
        ];
    }

    return $result;
}

function saveFamilies($file, $families) {
    $directory = dirname($file);

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $json = json_encode(
        ["families" => cleanFamilies($families)],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if ($json === false) {
        return false;
    }

    return file_put_contents($file, $json, LOCK_EX) !== false;
}

function categoryExists($categoriesFile, $categoryId) {
    $categories = readJson($categoriesFile, "categories");

    foreach ($categories as $category) {
        if (isset($category["id"]) && (string) $category["id"] === $categoryId) {
            return true;
        }
    }

    return false;
}

function findFamilyIndex($families, $id) {
    foreach ($families as $index => $family) {
        if ($family["id"] === $id) {
            return $index;
        }
    }

    return -1;
}

$method = strtoupper($_SERVER["REQUEST_METHOD"]);
$body = getRequestData();

if ($method === "POST" && isset($body["_method"])) {
    $method = strtoupper($body["_method"]);
}

funbikeProtectWriteMethods($method);

$id = trim((string) ($_GET["id"] ?? $body["id"] ?? ""));
$families = cleanFamilies(readJson($dataFile, "families"));

// GET - AFFICHER TOUTES LES FAMILLES
if ($method === "GET") {
    response(200, ["families" => $families]);
}

// POST - AJOUTER UNE FAMILLE
if ($method === "POST") {
    $name = trim($body["name"] ?? "");
    $categoryId = trim($body["categoryId"] ?? "");

    if ($name === "" || $categoryId === "") {
        response(400, ["error" => "Le nom et la catégorie sont obligatoires."]);
    }

    if (!categoryExists($categoriesFile, $categoryId)) {
        response(400, [
            "error" => "Cette catégorie n'existe pas.",
            "requested_categoryId" => $categoryId
        ]);
    }

    $newId = slugify($name);

    if ($newId === "") {
        response(400, ["error" => "Impossible de générer un identifiant."]);
    }

    if (findFamilyIndex($families, $newId) !== -1) {
        response(409, [
            "error" => "Cette famille existe déjà.",
            "requested_id" => $newId
        ]);
    }

    $newFamily = [
        "id" => $newId,
        "name" => $name,
        "categoryId" => $categoryId
    ];

    $families[] = $newFamily;

    if (!saveFamilies($dataFile, $families)) {
        response(500, ["error" => "Erreur lors de l'enregistrement."]);
    }

    response(201, [
        "message" => "Famille ajoutée avec succès.",
        "family" => $newFamily
    ]);
}

// PUT - MODIFIER UNE FAMILLE
if ($method === "PUT") {
    if ($id === "") {
        response(400, ["error" => "L'ID de la famille est obligatoire."]);
    }

    $name = trim($body["name"] ?? "");
    $categoryId = trim($body["categoryId"] ?? "");

    if ($name === "" || $categoryId === "") {
        response(400, ["error" => "Le nom et la catégorie sont obligatoires."]);
    }

    if (!categoryExists($categoriesFile, $categoryId)) {
        response(400, [
            "error" => "Cette catégorie n'existe pas.",
            "requested_categoryId" => $categoryId
        ]);
    }

    $index = findFamilyIndex($families, $id);

    if ($index === -1) {
        response(404, [
            "error" => "Famille introuvable.",
            "requested_id" => $id
        ]);
    }

    // L'identifiant reste stable pour ne pas casser les familyId existants.
    $families[$index]["name"] = $name;
    $families[$index]["categoryId"] = $categoryId;

    if (!saveFamilies($dataFile, $families)) {
        response(500, ["error" => "Erreur lors de la modification."]);
    }

    response(200, [
        "message" => "Famille modifiée avec succès.",
        "family" => $families[$index]
    ]);
}

// DELETE - SUPPRIMER UNE FAMILLE
if ($method === "DELETE") {
    if ($id === "") {
        response(400, ["error" => "L'ID de la famille est obligatoire."]);
    }

    $index = findFamilyIndex($families, $id);

    if ($index === -1) {
        response(404, [
            "error" => "Famille introuvable.",
            "requested_id" => $id
        ]);
    }

    $deletedFamily = $families[$index];
    unset($families[$index]);
    $families = array_values($families);

    if (!saveFamilies($dataFile, $families)) {
        response(500, ["error" => "Erreur lors de la suppression."]);
    }

    response(200, [
        "message" => "Famille supprimée avec succès.",
        "family" => $deletedFamily
    ]);
}

response(405, ["error" => "Méthode non autorisée."]);
