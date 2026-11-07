<?php

// =====================================================
// FUNBIKE - API SPARE PARTS
// GET / POST / PUT / DELETE
// =====================================================

require_once dirname(__DIR__) . "/_lib/auth.php";
funbikeBootstrapApi();

// =====================================================
// CONFIGURATION
// =====================================================

$serverRoot = dirname(__DIR__, 2);

$storageDir = $serverRoot . "/storage/data/products";
$uploadsDir = $serverRoot . "/uploads/products/spare-parts";
$jsonFile   = $storageDir . "/spare-parts.json";

$uploadsUrl = "/server/uploads/products/spare-parts";
$categoryId = "pieces-detachees";

// =====================================================
// FONCTIONS
// =====================================================

function respond($success, $data = null, $message = "", $status = 200)
{
    http_response_code($status);

    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data"    => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    exit;
}

function readProducts($file)
{
    $content = @file_get_contents($file);
    $json = json_decode((string) $content, true);

    if (
        !is_array($json) ||
        !isset($json["products"]) ||
        !is_array($json["products"])
    ) {
        respond(false, null, "Le fichier spare-parts.json est invalide.", 500);
    }

    return $json;
}

function saveProducts($file, $data)
{
    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        respond(false, null, "Erreur lors de la préparation du JSON.", 500);
    }

    if (file_put_contents($file, $json, LOCK_EX) === false) {
        respond(false, null, "Impossible d'enregistrer spare-parts.json.", 500);
    }
}

function createSlug($value)
{
    $value = trim((string) $value);

    if (function_exists("iconv")) {
        $converted = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $value);

        if ($converted !== false) {
            $value = $converted;
        }
    }

    $value = strtolower($value);
    $value = preg_replace("/[^a-z0-9]+/", "-", $value);

    return trim($value, "-");
}

function isValidProductId($id)
{
    return is_string($id) &&
        preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/i', $id);
}

function readProductInput()
{
    // FormData / multipart : le JSON est envoyé dans le champ "product".
    if (isset($_POST["product"])) {
        $product = json_decode($_POST["product"], true);

        if (!is_array($product)) {
            respond(false, null, "Les données du produit sont invalides.", 400);
        }

        return $product;
    }

    // Requête JSON classique.
    $raw = file_get_contents("php://input");
    $product = json_decode($raw, true);

    if (!is_array($product)) {
        respond(false, null, "Données JSON invalides.", 400);
    }

    return $product;
}

/**
 * Vérifie que isNewArrival est un booléen lorsqu'il est fourni.
 */
function validateNewArrival($product)
{
    if (
        array_key_exists("isNewArrival", $product) &&
        !is_bool($product["isNewArrival"])
    ) {
        respond(
            false,
            null,
            "isNewArrival doit être un booléen.",
            400
        );
    }
}

function normalizeImages($images, $productId, $productName)
{
    if (!is_array($images)) {
        return [];
    }

    $validImages = [];

    foreach ($images as $image) {
        if (
            !is_array($image) ||
            empty($image["url"]) ||
            !is_string($image["url"])
        ) {
            continue;
        }

        $validImages[] = $image;
    }

    if (count($validImages) === 0) {
        return [];
    }

    // La première image marquée principale passe en première position.
    $mainIndex = 0;

    foreach ($validImages as $index => $image) {
        if (!empty($image["isMain"])) {
            $mainIndex = $index;
            break;
        }
    }

    if ($mainIndex > 0) {
        $mainImage = $validImages[$mainIndex];
        array_splice($validImages, $mainIndex, 1);
        array_unshift($validImages, $mainImage);
    }

    $normalized = [];

    foreach (array_values($validImages) as $index => $image) {
        $imageId = !empty($image["id"])
            ? (string) $image["id"]
            : $productId . "-image-" . ($index + 1);

        $altText = !empty($image["altText"])
            ? (string) $image["altText"]
            : $productName;

        $normalized[] = [
            "id"       => $imageId,
            "url"      => (string) $image["url"],
            "altText"  => $altText,
            "isMain"   => $index === 0,
            "position" => $index
        ];
    }

    return $normalized;
}

function hasUploadedFile($file)
{
    return isset($file["error"]) &&
        (int) $file["error"] !== UPLOAD_ERR_NO_FILE;
}

function saveUploadedImage($file, $destinationWithoutExtension)
{
    if (!isset($file["error"])) {
        respond(false, null, "Fichier image invalide.", 400);
    }

    if ((int) $file["error"] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ((int) $file["error"] !== UPLOAD_ERR_OK) {
        respond(false, null, "Erreur lors de l'envoi d'une image.", 400);
    }

    if (
        empty($file["tmp_name"]) ||
        !is_uploaded_file($file["tmp_name"])
    ) {
        respond(false, null, "Le fichier envoyé est invalide.", 400);
    }

    if ((int) ($file["size"] ?? 0) > 8 * 1024 * 1024) {
        respond(false, null, "Une image dépasse la limite de 8 Mo.", 400);
    }

    $mime = mime_content_type($file["tmp_name"]);

    $allowedTypes = [
        "image/jpeg" => "jpg",
        "image/png"  => "png",
        "image/webp" => "webp"
    ];

    if (!isset($allowedTypes[$mime])) {
        respond(
            false,
            null,
            "Format d'image non autorisé. Utilise JPG, PNG ou WEBP.",
            400
        );
    }

    $extension = $allowedTypes[$mime];
    $destination = $destinationWithoutExtension . "." . $extension;

    if (!move_uploaded_file($file["tmp_name"], $destination)) {
        respond(false, null, "Impossible d'enregistrer une image.", 500);
    }

    return $destination;
}

function getGalleryFiles()
{
    if (!isset($_FILES["gallery"])) {
        return [];
    }

    $gallery = $_FILES["gallery"];

    if (!isset($gallery["name"])) {
        return [];
    }

    // Accepte un seul fichier ou plusieurs fichiers.
    if (!is_array($gallery["name"])) {
        return [$gallery];
    }

    $files = [];

    foreach ($gallery["name"] as $index => $name) {
        $files[] = [
            "name"     => $gallery["name"][$index] ?? "",
            "type"     => $gallery["type"][$index] ?? "",
            "tmp_name" => $gallery["tmp_name"][$index] ?? "",
            "error"    => $gallery["error"][$index] ?? UPLOAD_ERR_NO_FILE,
            "size"     => $gallery["size"][$index] ?? 0
        ];
    }

    return $files;
}

function removeMainFiles($productDir)
{
    foreach (glob($productDir . "/main.*") ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

function nextGalleryNumber($productDir)
{
    $maxNumber = 1;

    foreach (glob($productDir . "/image-*.*") ?: [] as $file) {
        if (preg_match('/^image-(\d+)\./', basename($file), $matches)) {
            $maxNumber = max($maxNumber, (int) $matches[1]);
        }
    }

    return $maxNumber + 1;
}

function applyImageUploads(&$product, $productId, $productDir, $uploadsUrl)
{
    $images = isset($product["images"]) && is_array($product["images"])
        ? array_values($product["images"])
        : [];

    $productName = (string) ($product["name"] ?? $productId);

    // Remplacer la photo principale si une nouvelle est envoyée.
    if (
        isset($_FILES["mainImage"]) &&
        hasUploadedFile($_FILES["mainImage"])
    ) {
        $images = array_values(array_filter($images, function ($image) {
            return !is_array($image) || empty($image["isMain"]);
        }));

        removeMainFiles($productDir);

        $savedFile = saveUploadedImage(
            $_FILES["mainImage"],
            $productDir . "/main"
        );

        if ($savedFile !== null) {
            $mainUrl = $uploadsUrl . "/" . $productId . "/" . basename($savedFile);

            array_unshift($images, [
                "id"       => $productId . "-image-1",
                "url"      => $mainUrl,
                "altText"  => $productName . " – vue principale",
                "isMain"   => true,
                "position" => 0
            ]);
        }
    }

    // Ajouter des photos dans la galerie.
    $imageNumber = nextGalleryNumber($productDir);

    foreach (getGalleryFiles() as $file) {
        if (!hasUploadedFile($file)) {
            continue;
        }

        $savedFile = saveUploadedImage(
            $file,
            $productDir . "/image-" . $imageNumber
        );

        if ($savedFile === null) {
            continue;
        }

        $position = count($images);
        $imageUrl = $uploadsUrl . "/" . $productId . "/" . basename($savedFile);

        $images[] = [
            "id"       => $productId . "-image-" . ($position + 1),
            "url"      => $imageUrl,
            "altText"  => $productName . " – vue " . $imageNumber,
            "isMain"   => false,
            "position" => $position
        ];

        $imageNumber++;
    }

    $product["images"] = normalizeImages(
        $images,
        $productId,
        $productName
    );
}

function deleteProductDirectory($directory)
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($directory);
}

// =====================================================
// DOSSIERS ET JSON
// =====================================================

foreach ([$storageDir, $uploadsDir] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        respond(false, null, "Impossible de créer les dossiers nécessaires.", 500);
    }
}

if (!file_exists($jsonFile)) {
    $initialJson = json_encode(
        ["products" => []],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if (file_put_contents($jsonFile, $initialJson, LOCK_EX) === false) {
        respond(false, null, "Impossible de créer spare-parts.json.", 500);
    }
}

// =====================================================
// ROUTAGE
// =====================================================

$method = strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");

// Supporte POST avec _method=PUT pour les formulaires contenant des fichiers.
if ($method === "POST" && isset($_POST["_method"])) {
    $method = strtoupper($_POST["_method"]);
}

funbikeProtectWriteMethods($method);

$id   = $_GET["id"] ?? $_POST["id"] ?? null;
$data = readProducts($jsonFile);

// =====================================================
// GET - RÉCUPÉRER LES PIÈCES DÉTACHÉES
// =====================================================

if ($method === "GET") {
    if ($id !== null && $id !== "") {
        foreach ($data["products"] as $product) {
            if (($product["id"] ?? null) === $id) {
                respond(true, $product, "Pièce détachée trouvée.");
            }
        }

        respond(false, null, "Pièce détachée introuvable.", 404);
    }

    respond(true, $data["products"], "Liste des pièces détachées récupérée.");
}

// =====================================================
// POST - AJOUTER UNE PIÈCE DÉTACHÉE
// =====================================================

if ($method === "POST") {
    $product = readProductInput();

    validateNewArrival($product);

    // Une nouvelle pièce n'est pas une nouveauté par défaut.
    $product["isNewArrival"] = $product["isNewArrival"] ?? false;

    if (empty($product["name"])) {
        respond(false, null, "Le nom du produit est obligatoire.", 400);
    }

    $product["name"] = trim((string) $product["name"]);

    $baseId = createSlug(
        !empty($product["id"]) ? $product["id"] : $product["name"]
    );

    if ($baseId === "") {
        respond(false, null, "Identifiant du produit invalide.", 400);
    }

    $newId = $baseId;
    $counter = 2;

    while (true) {
        $exists = false;

        foreach ($data["products"] as $existingProduct) {
            if (($existingProduct["id"] ?? null) === $newId) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            break;
        }

        $newId = $baseId . "-" . $counter;
        $counter++;
    }

    $product["id"] = $newId;
    $product["categoryId"] = $categoryId;
    $product["description"] = $product["description"] ?? "";
    $product["specifications"] = is_array($product["specifications"] ?? null)
        ? $product["specifications"]
        : [];
    $product["status"] = $product["status"] ?? "PUBLISHED";
    $product["createdAt"] = $product["createdAt"] ?? date("c");
    $product["updatedAt"] = date("c");

    $productDir = $uploadsDir . "/" . $newId;

    if (!is_dir($productDir) && !mkdir($productDir, 0755, true)) {
        respond(false, null, "Impossible de créer le dossier du produit.", 500);
    }

    applyImageUploads($product, $newId, $productDir, $uploadsUrl);

    $data["products"][] = $product;
    saveProducts($jsonFile, $data);

    respond(true, $product, "Pièce détachée ajoutée avec succès.", 201);
}

// =====================================================
// PUT - MODIFIER UNE PIÈCE DÉTACHÉE
// =====================================================

if ($method === "PUT") {
    if (!isValidProductId($id)) {
        respond(false, null, "Identifiant du produit obligatoire ou invalide.", 400);
    }

    $productInput = readProductInput();

    validateNewArrival($productInput);

    $found = false;

    foreach ($data["products"] as $index => $existingProduct) {
        if (($existingProduct["id"] ?? null) !== $id) {
            continue;
        }

        $found = true;
        $product = array_merge($existingProduct, $productInput);

        // Conserve la valeur existante si le champ n'est pas envoyé.
        // Pour une ancienne pièce sans ce champ, utilise false.
        $product["isNewArrival"] = $product["isNewArrival"] ?? false;

        if (empty($product["name"])) {
            respond(false, null, "Le nom du produit est obligatoire.", 400);
        }

        $product["id"] = $id;
        $product["categoryId"] = $categoryId;
        $product["name"] = trim((string) $product["name"]);
        $product["specifications"] = is_array($product["specifications"] ?? null)
            ? $product["specifications"]
            : [];
        $product["createdAt"] = $existingProduct["createdAt"] ?? date("c");
        $product["updatedAt"] = date("c");

        $productDir = $uploadsDir . "/" . $id;

        if (!is_dir($productDir) && !mkdir($productDir, 0755, true)) {
            respond(false, null, "Impossible de créer le dossier du produit.", 500);
        }

        applyImageUploads($product, $id, $productDir, $uploadsUrl);

        $data["products"][$index] = $product;
        break;
    }

    if (!$found) {
        respond(false, null, "Pièce détachée introuvable.", 404);
    }

    $data["products"] = array_values($data["products"]);
    saveProducts($jsonFile, $data);

    respond(true, $product, "Pièce détachée modifiée avec succès.");
}

// =====================================================
// DELETE - SUPPRIMER UNE PIÈCE DÉTACHÉE
// =====================================================

if ($method === "DELETE") {
    if (!isValidProductId($id)) {
        respond(false, null, "Identifiant du produit obligatoire ou invalide.", 400);
    }

    $found = false;

    foreach ($data["products"] as $index => $product) {
        if (($product["id"] ?? null) === $id) {
            unset($data["products"][$index]);
            $found = true;
            break;
        }
    }

    if (!$found) {
        respond(false, null, "Pièce détachée introuvable.", 404);
    }

    $data["products"] = array_values($data["products"]);
    saveProducts($jsonFile, $data);

    deleteProductDirectory($uploadsDir . "/" . $id);

    respond(true, ["id" => $id], "Pièce détachée supprimée avec succès.");
}

// =====================================================
// MÉTHODE NON AUTORISÉE
// =====================================================

respond(false, null, "Méthode non autorisée.", 405);