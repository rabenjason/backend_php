
<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

$dataFile = dirname(__DIR__) . "/storage/data/models.json";
$brandsFile = dirname(__DIR__) . "/storage/data/brands.json";

function response($status, $data) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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

function readJson($file, $key) {
    if (!file_exists($file)) {
        return [];
    }

    $content = file_get_contents($file);
    $data = json_decode($content, true);

    return is_array($data) && isset($data[$key]) && is_array($data[$key])
        ? $data[$key]
        : [];
}

function cleanModels($models) {
    $result = [];

    foreach ($models as $model) {
        if (
            !is_array($model) ||
            !isset($model["id"], $model["name"], $model["brandId"])
        ) {
            continue;
        }

        $result[] = [
            "id" => (string) $model["id"],
            "name" => (string) $model["name"],
            "brandId" => (string) $model["brandId"]
        ];
    }

    return $result;
}

function saveModels($file, $models) {
    $directory = dirname($file);

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $content = [
        "models" => cleanModels($models)
    ];

    $saved = file_put_contents(
        $file,
        json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );

    return $saved !== false;
}

function getRequestData() {
    $data = $_POST;

    if (empty($data)) {
        $raw = file_get_contents("php://input");
        $json = json_decode($raw, true);

        if (is_array($json)) {
            $data = $json;
        } else {
            parse_str($raw, $data);
        }
    }

    return $data;
}

function brandExists($brandsFile, $brandId) {
    $brands = readJson($brandsFile, "brands");

    foreach ($brands as $brand) {
        if (
            isset($brand["id"]) &&
            $brand["id"] === $brandId
        ) {
            return true;
        }
    }

    return false;
}

$method = $_SERVER["REQUEST_METHOD"];
$body = getRequestData();

if (isset($body["_method"])) {
    $method = strtoupper($body["_method"]);
}

$models = cleanModels(readJson($dataFile, "models"));

if ($method === "GET") {
    response(200, ["models" => $models]);
}

// Ajouter un modèle
if ($method === "POST") {
    $name = trim($body["name"] ?? "");
    $brandId = trim($body["brandId"] ?? "");

    if ($name === "" || $brandId === "") {
        response(400, [
            "success" => false,
            "message" => "Le nom et la marque sont obligatoires."
        ]);
    }

    if (!brandExists($brandsFile, $brandId)) {
        response(400, [
            "success" => false,
            "message" => "Cette marque n'existe pas."
        ]);
    }

    $id = slugify($name);

    if ($id === "") {
        response(400, [
            "success" => false,
            "message" => "Impossible de générer un identifiant."
        ]);
    }

    foreach ($models as $model) {
        if ($model["id"] === $id) {
            response(409, [
                "success" => false,
                "message" => "Ce modèle existe déjà."
            ]);
        }
    }

    $models[] = [
        "id" => $id,
        "name" => $name,
        "brandId" => $brandId
    ];

    if (!saveModels($dataFile, $models)) {
        response(500, [
            "success" => false,
            "message" => "Erreur lors de l'enregistrement."
        ]);
    }

    response(201, [
        "success" => true,
        "message" => "Modèle ajouté.",
        "model" => end($models)
    ]);
}

// Modifier un modèle
if ($method === "PUT") {
    $id = trim($_GET["id"] ?? $body["id"] ?? "");
    $name = trim($body["name"] ?? "");
    $brandId = trim($body["brandId"] ?? "");

    if ($id === "" || $name === "" || $brandId === "") {
        response(400, [
            "success" => false,
            "message" => "ID, nom et marque sont obligatoires."
        ]);
    }

    if (!brandExists($brandsFile, $brandId)) {
        response(400, [
            "success" => false,
            "message" => "Cette marque n'existe pas."
        ]);
    }

    $found = false;

    foreach ($models as &$model) {
        if ($model["id"] === $id) {
            $model["name"] = $name;
            $model["brandId"] = $brandId;
            $found = true;
            break;
        }
    }

    unset($model);

    if (!$found) {
        response(404, [
            "success" => false,
            "message" => "Modèle introuvable."
        ]);
    }

    if (!saveModels($dataFile, $models)) {
        response(500, [
            "success" => false,
            "message" => "Erreur lors de l'enregistrement."
        ]);
    }

    response(200, [
        "success" => true,
        "message" => "Modèle modifié."
    ]);
}

// Supprimer un modèle
if ($method === "DELETE") {
    $id = trim($_GET["id"] ?? $body["id"] ?? "");

    if ($id === "") {
        response(400, [
            "success" => false,
            "message" => "ID du modèle obligatoire."
        ]);
    }

    $before = count($models);

    $models = array_values(array_filter(
        $models,
        function ($model) use ($id) {
            return $model["id"] !== $id;
        }
    ));

    if (count($models) === $before) {
        response(404, [
            "success" => false,
            "message" => "Modèle introuvable."
        ]);
    }

    if (!saveModels($dataFile, $models)) {
        response(500, [
            "success" => false,
            "message" => "Erreur lors de l'enregistrement."
        ]);
    }

    response(200, [
        "success" => true,
        "message" => "Modèle supprimé."
    ]);
}

response(405, [
    "success" => false,
    "message" => "Méthode non autorisée."
]);
