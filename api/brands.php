<?php
// server/api/brands.php

// 1. HTTP, CORS et authentification partagés
require_once __DIR__ . "/_lib/auth.php";
funbikeBootstrapApi();

// 2. Chemins
$storageDir = dirname(__DIR__) . '/storage/data';
$uploadsDir = dirname(__DIR__) . '/uploads/brands';
$jsonFile = $storageDir . '/brands.json';

// 3. Fonction utilitaire pour générer un ID (slug)
function generateSlug($string) {
    $string = strtolower($string);
    $string = preg_replace('/[^a-z0-9\s-]/', '', $string);
    $string = preg_replace('/[\s-]+/', '-', $string);
    return trim($string, '-');
}

// 4. Astuce PHP : Supporter PUT avec des fichiers via _method=PUT en POST
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && isset($_POST['_method'])) {
    $method = strtoupper($_POST['_method']);
}

funbikeProtectWriteMethods($method);

// 5. Chargement et normalisation des données existantes
// Accepte les booléens JSON et les valeurs envoyées par un FormData.
function normalizeBrandBoolean($value) {
    if (is_bool($value)) {
        return $value;
    }

    if ($value === 1 || $value === "1") {
        return true;
    }

    if ($value === 0 || $value === "0") {
        return false;
    }

    if (is_string($value)) {
        $value = strtolower(trim($value));

        if (in_array($value, ["true", "on"], true)) {
            return true;
        }

        if (in_array($value, ["false", ""], true)) {
            return false;
        }
    }

    return null;
}

function cleanBrands($brands) {
    $result = [];

    foreach ($brands as $brand) {
        if (!is_array($brand) || !isset($brand['id'], $brand['name'], $brand['logo'])) {
            continue;
        }

        $isEquipmentBrand = normalizeBrandBoolean($brand['isEquipmentBrand'] ?? false);

        $result[] = [
            "id" => (string) $brand['id'],
            "name" => (string) $brand['name'],
            "logo" => (string) $brand['logo'],
            "isEquipmentBrand" => $isEquipmentBrand === null ? false : $isEquipmentBrand
        ];
    }

    return $result;
}

function loadData($jsonFile) {
    if (!file_exists($jsonFile)) {
        return ["brands" => []];
    }

    $data = json_decode(file_get_contents($jsonFile), true);

    if (!is_array($data) || !isset($data['brands']) || !is_array($data['brands'])) {
        return ["brands" => []];
    }

    return ["brands" => cleanBrands($data['brands'])];
}

function saveData($jsonFile, $data) {
    $data = ["brands" => cleanBrands($data['brands'])];

    return file_put_contents(
        $jsonFile,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    ) !== false;
}

// ==========================================
// ROUTES
// ==========================================

if ($method === 'GET') {
    $data = loadData($jsonFile);
    http_response_code(200);
    echo json_encode($data);
    exit;
}

if ($method === 'POST') {
    // --- CRÉER UNE NOUVELLE MARQUE ---
    if (empty($_POST['name'])) {
        http_response_code(400);
        echo json_encode(["error" => "Le nom de la marque est requis."]);
        exit;
    }

    $name = trim($_POST['name']);
    $id = generateSlug($name);
    $isEquipmentBrand = false;

    if (array_key_exists('isEquipmentBrand', $_POST)) {
        $isEquipmentBrand = normalizeBrandBoolean($_POST['isEquipmentBrand']);

        if ($isEquipmentBrand === null) {
            http_response_code(400);
            echo json_encode(["error" => "isEquipmentBrand doit être true, false, 1 ou 0."]);
            exit;
        }
    }

    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(["error" => "Le logo est requis."]);
        exit;
    }

    $file = $_FILES['logo'];
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExt, ['jpg', 'jpeg', 'png', 'webp'])) {
        http_response_code(400);
        echo json_encode(["error" => "Format de fichier non autorisé (PNG, JPG, WEBP)."]);
        exit;
    }

    $brandDir = $uploadsDir . '/' . $id;
    if (!is_dir($brandDir)) {
        mkdir($brandDir, 0755, true);
    }

    $destination = $brandDir . '/logo.' . $fileExt;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        http_response_code(500);
        echo json_encode(["error" => "Échec de l'enregistrement du logo."]);
        exit;
    }

    $data = loadData($jsonFile);
    foreach ($data['brands'] as $brand) {
        if ($brand['id'] === $id) {
            http_response_code(409);
            echo json_encode(["error" => "Cette marque existe déjà."]);
            @unlink($destination);
            exit;
        }
    }

    $newBrand = [
        "id" => $id,
        "name" => $name,
        "logo" => "/server/uploads/brands/$id/logo.$fileExt",
        "isEquipmentBrand" => $isEquipmentBrand
    ];
    $data['brands'][] = $newBrand;
    saveData($jsonFile, $data);

    http_response_code(201);
    echo json_encode(["message" => "Marque ajoutée.", "brand" => $newBrand]);
    exit;
}

if ($method === 'PUT') {
    // --- METTRE À JOUR UNE MARQUE ---
    $id = $_GET['id'] ?? $_POST['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(["error" => "L'ID de la marque est requis pour la mise à jour."]);
        exit;
    }

    $data = loadData($jsonFile);
    $brandIndex = -1;
    foreach ($data['brands'] as $index => $brand) {
        if ($brand['id'] === $id) {
            $brandIndex = $index;
            break;
        }
    }

    if ($brandIndex === -1) {
        http_response_code(404);
        echo json_encode(["error" => "Marque introuvable."]);
        exit;
    }

    // Mise à jour du nom
    if (!empty($_POST['name'])) {
        $data['brands'][$brandIndex]['name'] = trim($_POST['name']);
    }

    // Mise à jour du type de marque sans exiger un nouveau logo.
    if (array_key_exists('isEquipmentBrand', $_POST)) {
        $isEquipmentBrand = normalizeBrandBoolean($_POST['isEquipmentBrand']);

        if ($isEquipmentBrand === null) {
            http_response_code(400);
            echo json_encode(["error" => "isEquipmentBrand doit être true, false, 1 ou 0."]);
            exit;
        }

        $data['brands'][$brandIndex]['isEquipmentBrand'] = $isEquipmentBrand;
    }

    // Mise à jour du logo (si un nouveau fichier est fourni)
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['logo'];
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExt, ['jpg', 'jpeg', 'png', 'webp'])) {
            http_response_code(400);
            echo json_encode(["error" => "Format de fichier non autorisé."]);
            exit;
        }

        $brandDir = $uploadsDir . '/' . $id;
        if (!is_dir($brandDir)) {
            mkdir($brandDir, 0755, true);
        }

        // Supprimer l'ancien logo pour éviter les fichiers orphelins
        $oldFiles = glob($brandDir . '/logo.*');
        foreach ($oldFiles as $oldFile) {
            if (is_file($oldFile)) unlink($oldFile);
        }

        $destination = $brandDir . '/logo.' . $fileExt;
        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $data['brands'][$brandIndex]['logo'] = "/server/uploads/brands/$id/logo.$fileExt";
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Échec de la mise à jour du logo."]);
            exit;
        }
    }

    saveData($jsonFile, $data);
    http_response_code(200);
    echo json_encode(["message" => "Marque mise à jour.", "brand" => $data['brands'][$brandIndex]]);
    exit;
}

if ($method === 'DELETE') {
    // --- SUPPRIMER UNE MARQUE ---
    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(["error" => "L'ID de la marque est requis pour la suppression."]);
        exit;
    }

    $data = loadData($jsonFile);
    $brandIndex = -1;
    foreach ($data['brands'] as $index => $brand) {
        if ($brand['id'] === $id) {
            $brandIndex = $index;
            break;
        }
    }

    if ($brandIndex === -1) {
        http_response_code(404);
        echo json_encode(["error" => "Marque introuvable."]);
        exit;
    }

    // Supprimer le dossier et son contenu
    $brandDir = $uploadsDir . '/' . $id;
    if (is_dir($brandDir)) {
        $files = array_diff(scandir($brandDir), ['.', '..']);
        foreach ($files as $file) {
            unlink($brandDir . '/' . $file);
        }
        rmdir($brandDir);
    }

    // Retirer du tableau et réindexer
    unset($data['brands'][$brandIndex]);
    $data['brands'] = array_values($data['brands']);
    
    saveData($jsonFile, $data);
    http_response_code(200);
    echo json_encode(["message" => "Marque supprimée avec succès."]);
    exit;
}

http_response_code(405);
echo json_encode(["error" => "Méthode non autorisée."]);