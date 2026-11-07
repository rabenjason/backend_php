<?php

// =====================================================
// FUNBIKE - API MOTORCYCLES
// GET / POST / PUT / DELETE
// =====================================================

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// =====================================================
// CONFIGURATION
// =====================================================

$baseDir = dirname(__DIR__, 2); // Remonte jusqu'à /server

$storageDir = $baseDir . "/storage/data/products";
$uploadsDir = $baseDir . "/uploads/products/motorcycles";
$jsonFile = $storageDir . "/motorcycles.json";

$uploadsUrl = "/server/uploads/products/motorcycles";

if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
}

if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

if (!file_exists($jsonFile)) {
    file_put_contents(
        $jsonFile,
        json_encode(
            ["products" => []],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )
    );
}

// =====================================================
// FONCTIONS
// =====================================================

function respond($success, $data = null, $message = "", $status = 200)
{
    http_response_code($status);

    echo json_encode(
        [
            "success" => $success,
            "message" => $message,
            "data" => $data
        ],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );

    exit;
}

function readMotorcycles($jsonFile)
{
    $content = file_get_contents($jsonFile);
    $json = json_decode($content, true);

    if (
        !is_array($json)
        || !isset($json["products"])
        || !is_array($json["products"])
    ) {
        respond(false, null, "Format de motorcycles.json invalide.", 500);
    }

    return $json;
}

function saveMotorcycles($jsonFile, $data)
{
    $tempFile = $jsonFile . ".tmp";

    $json = json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        respond(false, null, "Erreur lors de la préparation du JSON.", 500);
    }

    if (file_put_contents($tempFile, $json, LOCK_EX) === false) {
        respond(false, null, "Impossible d'enregistrer motorcycles.json.", 500);
    }

    if (!rename($tempFile, $jsonFile)) {
        @unlink($tempFile);
        respond(false, null, "Impossible de remplacer motorcycles.json.", 500);
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

function readProductInput()
{
    if (isset($_POST["product"])) {
        $product = json_decode($_POST["product"], true);

        if (!is_array($product)) {
            respond(false, null, "Les données du produit sont invalides.", 400);
        }

        return $product;
    }

    $raw = file_get_contents("php://input");
    $product = json_decode($raw, true);

    if (!is_array($product)) {
        respond(false, null, "Données JSON invalides.", 400);
    }

    return $product;
}

/**
 * Valide les champs de sélection pour les sections de l'accueil.
 */
function validateHomepageFields($product)
{
    $fields = [
        "isNewArrival",
        "isHomepageHero",
        "isMotoAccueil"
    ];

    foreach ($fields as $field) {
        if (
            array_key_exists($field, $product)
            && !is_bool($product[$field])
        ) {
            respond(
                false,
                null,
                $field . " doit être un booléen.",
                400
            );
        }
    }
}

/**
 * Initialise à false les champs d'accueil absents.
 */
function applyHomepageDefaults(&$product)
{
    $product["isNewArrival"] = $product["isNewArrival"] ?? false;
    $product["isHomepageHero"] = $product["isHomepageHero"] ?? false;
    $product["isMotoAccueil"] = $product["isMotoAccueil"] ?? false;
}

function saveUploadedImage($file, $destinationWithoutExtension)
{
    if (!isset($file["error"]) || $file["error"] !== UPLOAD_ERR_OK) {
        respond(false, null, "Erreur lors de l'envoi d'une image.", 400);
    }

    $allowedTypes = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp"
    ];

    $mime = mime_content_type($file["tmp_name"]);

    if (!isset($allowedTypes[$mime])) {
        respond(
            false,
            null,
            "Format d'image non autorisé. Utilise JPG, PNG ou WEBP.",
            400
        );
    }

    if ($file["size"] > 8 * 1024 * 1024) {
        respond(false, null, "Une image dépasse la limite de 8 Mo.", 400);
    }

    $extension = $allowedTypes[$mime];
    $filename = $destinationWithoutExtension . "." . $extension;

    if (!move_uploaded_file($file["tmp_name"], $filename)) {
        respond(false, null, "Impossible d'enregistrer une image.", 500);
    }

    return $filename;
}

function deletePhysicalImage($imageUrl, $uploadsDir, $uploadsUrl)
{
    if (!is_string($imageUrl) || $imageUrl === "") {
        return;
    }

    $prefix = $uploadsUrl . "/";

    if (strpos($imageUrl, $prefix) !== 0) {
        return;
    }

    $relativePath = substr($imageUrl, strlen($prefix));
    $fullPath = $uploadsDir . "/" . $relativePath;

    $basePath = realpath($uploadsDir);
    $targetPath = realpath($fullPath);

    if (
        $basePath
        && $targetPath
        && strpos($targetPath, $basePath . DIRECTORY_SEPARATOR) === 0
    ) {
        if (is_file($targetPath)) {
            unlink($targetPath);
        }
    }
}

function getNextImageNumber($images, $productId)
{
    $max = 1;

    foreach ($images as $image) {
        if (!empty($image["url"])) {
            $filename = basename($image["url"]);

            if (preg_match('/^image-(\d+)\./', $filename, $matches)) {
                $number = (int) $matches[1];

                if ($number > $max) {
                    $max = $number;
                }
            }
        }
    }

    return $max + 1;
}

function buildImageObject($productId, $url, $altText, $isMain, $position)
{
    $suffix = $isMain ? "image-1" : "image-" . ($position + 1);

    return [
        "id" => $productId . "-" . $suffix,
        "url" => $url,
        "altText" => $altText !== "" ? $altText : $productId,
        "isMain" => $isMain,
        "position" => $position
    ];
}

// =====================================================
// ROUTAGE
// =====================================================

$method = $_SERVER["REQUEST_METHOD"];
$id = $_GET["id"] ?? null;

$data = readMotorcycles($jsonFile);

// =====================================================
// GET
// =====================================================

if ($method === "GET") {
    if ($id !== null) {
        foreach ($data["products"] as $product) {
            if (($product["id"] ?? null) === $id) {
                respond(true, $product, "Moto trouvée.");
            }
        }

        respond(false, null, "Moto introuvable.", 404);
    }

    respond(true, $data["products"], "Liste des motos récupérée.");
}

// =====================================================
// POST
// =====================================================

if ($method === "POST") {
    $product = readProductInput();

    validateHomepageFields($product);

    // Les trois sélections sont désactivées par défaut.
    applyHomepageDefaults($product);

    if (empty($product["name"])) {
        respond(false, null, "Le nom de la moto est obligatoire.", 400);
    }

    $baseId = createSlug($product["id"] ?? $product["name"]);

    if ($baseId === "") {
        respond(false, null, "Identifiant de la moto invalide.", 400);
    }

    $newId = $baseId;
    $counter = 2;

    while (true) {
        $exists = false;

        foreach ($data["products"] as $existing) {
            if (($existing["id"] ?? null) === $newId) {
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

    $productDir = $uploadsDir . "/" . $newId;

    if (!is_dir($productDir)) {
        mkdir($productDir, 0755, true);
    }

    if (!isset($product["images"]) || !is_array($product["images"])) {
        $product["images"] = [];
    }

    $product["categoryId"] = $product["categoryId"] ?? "motos";
    $product["status"] = $product["status"] ?? "PUBLISHED";
    $product["specifications"] = $product["specifications"] ?? [];
    $product["tags"] = $product["tags"] ?? [];
    $product["createdAt"] = $product["createdAt"] ?? date("c");
    $product["updatedAt"] = date("c");

    // Photo principale
    if (isset($_FILES["mainImage"])) {
        $savedFile = saveUploadedImage(
            $_FILES["mainImage"],
            $productDir . "/main"
        );
        $fileName = basename($savedFile);

        $product["images"][] = buildImageObject(
            $newId,
            $uploadsUrl . "/" . $newId . "/" . $fileName,
            $product["name"] . " – vue principale",
            true,
            0
        );
    }

    // Galerie
    if (isset($_FILES["gallery"])) {
        $galleryFiles = $_FILES["gallery"];
        $position = count($product["images"]);
        $imageNumber = getNextImageNumber($product["images"], $newId);

        foreach ($galleryFiles["name"] as $index => $name) {
            if ($galleryFiles["error"][$index] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $file = [
                "name" => $galleryFiles["name"][$index],
                "type" => $galleryFiles["type"][$index],
                "tmp_name" => $galleryFiles["tmp_name"][$index],
                "error" => $galleryFiles["error"][$index],
                "size" => $galleryFiles["size"][$index]
            ];

            $savedFile = saveUploadedImage(
                $file,
                $productDir . "/image-" . $imageNumber
            );
            $fileName = basename($savedFile);

            $product["images"][] = buildImageObject(
                $newId,
                $uploadsUrl . "/" . $newId . "/" . $fileName,
                $product["name"] . " – vue " . $imageNumber,
                false,
                $position
            );

            $position++;
            $imageNumber++;
        }
    }

    // Réordonner les images
    usort($product["images"], function ($a, $b) {
        return ($a["position"] ?? 0) <=> ($b["position"] ?? 0);
    });

    foreach ($product["images"] as $index => $image) {
        $product["images"][$index]["position"] = $index;
        $product["images"][$index]["isMain"] = $index === 0;

        $suffix = $index === 0 ? "image-1" : "image-" . ($index + 1);
        $product["images"][$index]["id"] = $newId . "-" . $suffix;
    }

    $data["products"][] = $product;

    saveMotorcycles($jsonFile, $data);

    respond(true, $product, "Moto ajoutée avec succès.", 201);
}

// =====================================================
// PUT
// =====================================================

if ($method === "PUT") {
    if (!$id) {
        respond(false, null, "Identifiant de la moto obligatoire.", 400);
    }

    $productInput = readProductInput();

    validateHomepageFields($productInput);

    $found = false;

    foreach ($data["products"] as $index => $existing) {
        if (($existing["id"] ?? null) !== $id) {
            continue;
        }

        $found = true;

        $product = array_merge($existing, $productInput);
        $product["id"] = $id;

        // Les valeurs existantes sont conservées si elles ne sont pas envoyées.
        // Les champs absents sont initialisés à false.
        applyHomepageDefaults($product);

        if (!isset($product["images"]) || !is_array($product["images"])) {
            $product["images"] = [];
        }

        $product["categoryId"] = "motos";
        $product["specifications"] = $product["specifications"] ?? [];
        $product["tags"] = $product["tags"] ?? [];
        $product["updatedAt"] = date("c");

        $productDir = $uploadsDir . "/" . $id;

        if (!is_dir($productDir)) {
            mkdir($productDir, 0755, true);
        }

        // Remplacer la photo principale
        if (isset($_FILES["mainImage"])) {
            foreach ($product["images"] as $image) {
                if (!empty($image["isMain"])) {
                    deletePhysicalImage(
                        $image["url"],
                        $uploadsDir,
                        $uploadsUrl
                    );
                }
            }

            $product["images"] = array_values(
                array_filter($product["images"], function ($image) {
                    return empty($image["isMain"]);
                })
            );

            $savedFile = saveUploadedImage(
                $_FILES["mainImage"],
                $productDir . "/main"
            );
            $fileName = basename($savedFile);

            array_unshift(
                $product["images"],
                buildImageObject(
                    $id,
                    $uploadsUrl . "/" . $id . "/" . $fileName,
                    $product["name"] . " – vue principale",
                    true,
                    0
                )
            );
        }

        // Ajouter des photos à la galerie
        if (isset($_FILES["gallery"])) {
            $galleryFiles = $_FILES["gallery"];
            $position = count($product["images"]);
            $imageNumber = getNextImageNumber($product["images"], $id);

            foreach ($galleryFiles["name"] as $fileIndex => $name) {
                if ($galleryFiles["error"][$fileIndex] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                $file = [
                    "name" => $galleryFiles["name"][$fileIndex],
                    "type" => $galleryFiles["type"][$fileIndex],
                    "tmp_name" => $galleryFiles["tmp_name"][$fileIndex],
                    "error" => $galleryFiles["error"][$fileIndex],
                    "size" => $galleryFiles["size"][$fileIndex]
                ];

                $savedFile = saveUploadedImage(
                    $file,
                    $productDir . "/image-" . $imageNumber
                );
                $fileName = basename($savedFile);

                $product["images"][] = buildImageObject(
                    $id,
                    $uploadsUrl . "/" . $id . "/" . $fileName,
                    $product["name"] . " – vue " . $imageNumber,
                    false,
                    $position
                );

                $position++;
                $imageNumber++;
            }
        }

        usort($product["images"], function ($a, $b) {
            return ($a["position"] ?? 0) <=> ($b["position"] ?? 0);
        });

        foreach ($product["images"] as $imageIndex => $image) {
            $product["images"][$imageIndex]["position"] = $imageIndex;
            $product["images"][$imageIndex]["isMain"] = $imageIndex === 0;

            $suffix = $imageIndex === 0
                ? "image-1"
                : "image-" . ($imageIndex + 1);

            $product["images"][$imageIndex]["id"] = $id . "-" . $suffix;

            if (empty($product["images"][$imageIndex]["altText"])) {
                $product["images"][$imageIndex]["altText"] = $product["name"];
            }
        }

        $data["products"][$index] = $product;
        break;
    }

    if (!$found) {
        respond(false, null, "Moto introuvable.", 404);
    }

    saveMotorcycles($jsonFile, $data);

    respond(true, $product, "Moto modifiée avec succès.");
}

// =====================================================
// DELETE
// =====================================================

if ($method === "DELETE") {
    if (!$id) {
        respond(false, null, "Identifiant de la moto obligatoire.", 400);
    }

    $found = false;

    foreach ($data["products"] as $index => $product) {
        if (($product["id"] ?? null) === $id) {
            $found = true;
            unset($data["products"][$index]);
            break;
        }
    }

    if (!$found) {
        respond(false, null, "Moto introuvable.", 404);
    }

    $data["products"] = array_values($data["products"]);
    saveMotorcycles($jsonFile, $data);

    $productDir = $uploadsDir . "/" . basename($id);

    if (is_dir($productDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $productDir,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());
            } else {
                unlink($fileInfo->getPathname());
            }
        }

        rmdir($productDir);
    }

    respond(true, ["id" => $id], "Moto supprimée avec succès.");
}

// =====================================================
// MÉTHODE NON AUTORISÉE
// =====================================================

respond(false, null, "Méthode non autorisée.", 405);