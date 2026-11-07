
<?php
// ==========================================
// FUNBIKE - DEALERS API
// server/api/dealers.php
// ==========================================

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// ==========================================
// 1. CHEMIN
// ==========================================

$dataFile = dirname(__DIR__) . "/storage/data/dealers.json";

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

// Lire les données envoyées
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

// Lire le JSON
function readDealers($file) {
    if (!file_exists($file)) {
        return [];
    }

    $content = file_get_contents($file);

    if ($content === false) {
        response(500, [
            "error" => "Impossible de lire dealers.json."
        ]);
    }

    $data = json_decode($content, true);

    if (
        !is_array($data) ||
        !isset($data["dealers"]) ||
        !is_array($data["dealers"])
    ) {
        response(500, [
            "error" => "Structure dealers.json invalide."
        ]);
    }

    return cleanDealers($data["dealers"]);
}

// Nettoyer les données
// Ne conserver que les propriétés connues de React
function cleanDealers($dealers) {
    $result = [];

    foreach ($dealers as $dealer) {
        if (
            !is_array($dealer) ||
            !isset(
                $dealer["key"],
                $dealer["city"],
                $dealer["name"],
                $dealer["kind"],
                $dealer["address"],
                $dealer["hours"],
                $dealer["phone"],
                $dealer["email"],
                $dealer["desc"],
                $dealer["lat"],
                $dealer["lon"],
                $dealer["services"]
            ) ||
            !is_array($dealer["services"]) ||
            !is_numeric($dealer["lat"]) ||
            !is_numeric($dealer["lon"])
        ) {
            continue;
        }

        $clean = [
            "key" => (string) $dealer["key"],
            "city" => (string) $dealer["city"],
            "name" => (string) $dealer["name"],
            "kind" => (string) $dealer["kind"],
            "address" => (string) $dealer["address"],
            "hours" => (string) $dealer["hours"],
            "phone" => (string) $dealer["phone"],
            "email" => (string) $dealer["email"],
            "desc" => (string) $dealer["desc"],
            "lat" => (float) $dealer["lat"],
            "lon" => (float) $dealer["lon"],
            "services" => array_values(
                array_map("strval", $dealer["services"])
            )
        ];

        // Facultatif : uniquement pour les fiches de démonstration
        if (isset($dealer["isDemo"])) {
            $clean["isDemo"] = (bool) $dealer["isDemo"];
        }

        $result[] = $clean;
    }

    return $result;
}

// Enregistrer les données
function saveDealers($file, $dealers) {
    $directory = dirname($file);

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $data = [
        "dealers" => cleanDealers($dealers)
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

    return file_put_contents(
        $file,
        $json,
        LOCK_EX
    ) !== false;
}

// Vérifier les champs obligatoires
function validateDealer($body) {
    $required = [
        "city",
        "name",
        "kind",
        "address",
        "hours",
        "phone",
        "email",
        "desc",
        "lat",
        "lon",
        "services"
    ];

    foreach ($required as $field) {
        if (!isset($body[$field])) {
            return "Le champ " . $field . " est obligatoire.";
        }

        if (
            is_string($body[$field]) &&
            trim($body[$field]) === ""
        ) {
            return "Le champ " . $field . " ne peut pas être vide.";
        }
    }

    if (!is_numeric($body["lat"]) || !is_numeric($body["lon"])) {
        return "Les coordonnées GPS doivent être numériques.";
    }

    $lat = (float) $body["lat"];
    $lon = (float) $body["lon"];

    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        return "Coordonnées GPS invalides.";
    }

    if (!is_array($body["services"])) {
        return "Les services doivent être un tableau.";
    }

    foreach ($body["services"] as $service) {
        if (!is_string($service) || trim($service) === "") {
            return "Chaque service doit être un texte non vide.";
        }
    }

    return null;
}

// Chercher un dealer
function findDealerIndex($dealers, $key) {
    foreach ($dealers as $index => $dealer) {
        if ($dealer["key"] === $key) {
            return $index;
        }
    }

    return -1;
}

// Construire un dealer propre
function buildDealer($body, $key) {
    $dealer = [
        "key" => $key,
        "city" => trim($body["city"]),
        "name" => trim($body["name"]),
        "kind" => trim($body["kind"]),
        "address" => trim($body["address"]),
        "hours" => trim($body["hours"]),
        "phone" => trim($body["phone"]),
        "email" => trim($body["email"]),
        "desc" => trim($body["desc"]),
        "lat" => (float) $body["lat"],
        "lon" => (float) $body["lon"],
        "services" => array_values(array_map(
            "trim",
            $body["services"]
        ))
    ];

    if (isset($body["isDemo"])) {
        $value = $body["isDemo"];

        $dealer["isDemo"] = in_array(
            $value,
            [true, 1, "1", "true"],
            true
        );
    }

    return $dealer;
}

// ==========================================
// 3. MÉTHODE ET PARAMÈTRES
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

$key = trim((string) (
    $_GET["key"] ?? $body["key"] ?? ""
));

$dealers = readDealers($dataFile);

// ==========================================
// 4. GET - AFFICHER
// ==========================================

if ($method === "GET") {
    response(200, [
        "dealers" => $dealers
    ]);
}

// ==========================================
// 5. POST - AJOUTER
// ==========================================

if ($method === "POST") {

    $error = validateDealer($body);

    if ($error !== null) {
        response(400, [
            "error" => $error
        ]);
    }

    // Utiliser la clé envoyée ou la générer depuis la ville
    $newKey = $key !== ""
        ? $key
        : slugify($body["city"]);

    if ($newKey === "") {
        response(400, [
            "error" => "Impossible de générer une clé."
        ]);
    }

    if (findDealerIndex($dealers, $newKey) !== -1) {
        response(409, [
            "error" => "Ce dealer existe déjà.",
            "requested_key" => $newKey
        ]);
    }

    $newDealer = buildDealer($body, $newKey);

    $dealers[] = $newDealer;

    if (!saveDealers($dataFile, $dealers)) {
        response(500, [
            "error" => "Erreur lors de l'enregistrement."
        ]);
    }

    response(201, [
        "message" => "Dealer ajouté avec succès.",
        "dealer" => $newDealer
    ]);
}

// ==========================================
// 6. PUT - MODIFIER
// ==========================================

if ($method === "PUT") {

    if ($key === "") {
        response(400, [
            "error" => "La clé du dealer est obligatoire."
        ]);
    }

    $error = validateDealer($body);

    if ($error !== null) {
        response(400, [
            "error" => $error
        ]);
    }

    $index = findDealerIndex($dealers, $key);

    if ($index === -1) {
        response(404, [
            "error" => "Dealer introuvable.",
            "requested_key" => $key
        ]);
    }

    // Conserver la clé originale
    $updatedDealer = buildDealer($body, $key);

    $dealers[$index] = $updatedDealer;

    if (!saveDealers($dataFile, $dealers)) {
        response(500, [
            "error" => "Erreur lors de la modification."
        ]);
    }

    response(200, [
        "message" => "Dealer modifié avec succès.",
        "dealer" => $updatedDealer
    ]);
}

// ==========================================
// 7. DELETE - SUPPRIMER
// ==========================================

if ($method === "DELETE") {

    if ($key === "") {
        response(400, [
            "error" => "La clé du dealer est obligatoire."
        ]);
    }

    $index = findDealerIndex($dealers, $key);

    if ($index === -1) {
        response(404, [
            "error" => "Dealer introuvable.",
            "requested_key" => $key
        ]);
    }

    $deletedDealer = $dealers[$index];

    unset($dealers[$index]);

    $dealers = array_values($dealers);

    if (!saveDealers($dataFile, $dealers)) {
        response(500, [
            "error" => "Erreur lors de la suppression."
        ]);
    }

    response(200, [
        "message" => "Dealer supprimé avec succès.",
        "dealer" => $deletedDealer
    ]);
}

// ==========================================
// 8. MÉTHODE NON AUTORISÉE
// ==========================================

response(405, [
    "error" => "Méthode non autorisée."
]);
