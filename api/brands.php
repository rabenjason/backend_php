<?php
// server/api/brands.php

// 1. Gestion des en-têtes CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

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

// 5. Chargement des données existantes
function loadData($jsonFile) {
    if (file_exists($jsonFile)) {
        return json_decode(file_get_contents($jsonFile), true) ?: ["brands" => []];
    }
    return ["brands" => []];
}

function saveData($jsonFile, $data) {
    return file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
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

    $newBrand = ["id" => $id, "name" => $name, "logo" => "/server/uploads/brands/$id/logo.$fileExt"];
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