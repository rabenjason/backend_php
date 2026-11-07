
<?php
// ==========================================
// FUNBIKE - CATEGORIES API
// server/api/categories.php
// ==========================================

// 1. HEADERS CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ==========================================
// 2. CHEMINS
// ==========================================

$storageDir = dirname(__DIR__) . '/storage/data';
$jsonFile = $storageDir . '/categories.json';

if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
}

// ==========================================
// 3. FONCTIONS
// ==========================================

// Réponse JSON
function response($status, $data) {
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

// Générer un ID automatiquement
function generateSlug($string) {
    $string = strtolower(trim($string));

    if (function_exists('iconv')) {
        $converted = iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $string
        );

        if ($converted !== false) {
            $string = $converted;
        }
    }

    $string = preg_replace('/[^a-z0-9\s-]/', '', $string);
    $string = preg_replace('/[\s-]+/', '-', $string);

    return trim($string, '-');
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

// Charger les catégories
function loadData($jsonFile) {
    if (!file_exists($jsonFile)) {
        return ["categories" => []];
    }

    $content = file_get_contents($jsonFile);

    if ($content === false) {
        response(500, [
            "error" => "Impossible de lire categories.json."
        ]);
    }

    $data = json_decode($content, true);

    if (
        !is_array($data) ||
        !isset($data['categories']) ||
        !is_array($data['categories'])
    ) {
        response(500, [
            "error" => "Fichier categories.json invalide."
        ]);
    }

    return $data;
}

// Sauvegarder les catégories
function saveData($jsonFile, $data) {
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        return false;
    }

    return file_put_contents(
        $jsonFile,
        $json,
        LOCK_EX
    ) !== false;
}

// Rechercher une catégorie par ID
function findCategoryIndex($categories, $id) {
    foreach ($categories as $index => $category) {
        if (
            isset($category['id']) &&
            trim((string)$category['id']) === $id
        ) {
            return $index;
        }
    }

    return -1;
}

// Nettoyer les catégories : uniquement ID et NAME
function cleanCategories($categories) {
    $result = [];

    foreach ($categories as $category) {
        $result[] = [
            "id" => $category['id'],
            "name" => $category['name']
        ];
    }

    return $result;
}

// ==========================================
// 4. MÉTHODE ET PARAMÈTRES
// ==========================================

$method = strtoupper($_SERVER['REQUEST_METHOD']);

$requestData = getRequestData();

// Support POST avec _method
if (
    $method === 'POST' &&
    isset($requestData['_method'])
) {
    $method = strtoupper($requestData['_method']);
}

// Récupérer ID depuis URL ou Body
$id = null;

if (isset($_GET['id'])) {
    $id = trim((string)$_GET['id']);
} elseif (isset($requestData['id'])) {
    $id = trim((string)$requestData['id']);
}

// ==========================================
// 5. GET - AFFICHER
// ==========================================

if ($method === 'GET') {

    $data = loadData($jsonFile);

    response(200, [
        "categories" => cleanCategories($data['categories'])
    ]);
}

// ==========================================
// 6. POST - AJOUTER
// ==========================================

if ($method === 'POST') {

    $name = trim($requestData['name'] ?? '');

    if ($name === '') {
        response(400, [
            "error" => "Le nom de la catégorie est requis."
        ]);
    }

    $id = generateSlug($name);

    if ($id === '') {
        response(400, [
            "error" => "Nom de catégorie invalide."
        ]);
    }

    $data = loadData($jsonFile);

    // Vérifier les doublons
    if (findCategoryIndex($data['categories'], $id) !== -1) {
        response(409, [
            "error" => "Cette catégorie existe déjà."
        ]);
    }

    // Nouvelle catégorie
    $newCategory = [
        "id" => $id,
        "name" => $name
    ];

    $data['categories'][] = $newCategory;

    if (!saveData($jsonFile, $data)) {
        response(500, [
            "error" => "Erreur lors de l'enregistrement."
        ]);
    }

    response(201, [
        "message" => "Catégorie ajoutée avec succès.",
        "category" => $newCategory
    ]);
}

// ==========================================
// 7. PUT - MODIFIER
// ==========================================

if ($method === 'PUT') {

    if ($id === null || $id === '') {
        response(400, [
            "error" => "L'ID de la catégorie est requis."
        ]);
    }

    $name = trim($requestData['name'] ?? '');

    if ($name === '') {
        response(400, [
            "error" => "Le nouveau nom est requis."
        ]);
    }

    $data = loadData($jsonFile);

    $index = findCategoryIndex($data['categories'], $id);

    if ($index === -1) {
        response(404, [
            "error" => "Catégorie introuvable.",
            "requested_id" => $id
        ]);
    }

    // Modifier uniquement le nom
    $data['categories'][$index]['name'] = $name;

    if (!saveData($jsonFile, $data)) {
        response(500, [
            "error" => "Erreur lors de la mise à jour."
        ]);
    }

    response(200, [
        "message" => "Catégorie mise à jour avec succès.",
        "category" => $data['categories'][$index]
    ]);
}

// ==========================================
// 8. DELETE - SUPPRIMER
// ==========================================

if ($method === 'DELETE') {

    if ($id === null || $id === '') {
        response(400, [
            "error" => "L'ID de la catégorie est requis."
        ]);
    }

    $data = loadData($jsonFile);

    $index = findCategoryIndex($data['categories'], $id);

    if ($index === -1) {
        response(404, [
            "error" => "Catégorie introuvable.",
            "requested_id" => $id
        ]);
    }

    // Récupérer la catégorie avant suppression
    $deletedCategory = [
        "id" => $data['categories'][$index]['id'],
        "name" => $data['categories'][$index]['name']
    ];

    // Supprimer
    unset($data['categories'][$index]);

    // Réindexer
    $data['categories'] = array_values($data['categories']);

    if (!saveData($jsonFile, $data)) {
        response(500, [
            "error" => "Erreur lors de la suppression."
        ]);
    }

    response(200, [
        "message" => "Catégorie supprimée avec succès.",
        "category" => $deletedCategory
    ]);
}

// ==========================================
// 9. MÉTHODE NON AUTORISÉE
// ==========================================

response(405, [
    "error" => "Méthode non autorisée."
]);

