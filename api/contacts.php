<?php
// ==========================================
// FUNBIKE - CONTACT API
// server/api/contacts.php
// ==========================================

require_once __DIR__ . "/_lib/auth.php";
funbikeBootstrapApi();

// ==========================================
// 1. CHEMIN
// ==========================================

$dataFile = dirname(__DIR__) . "/storage/data/contacts.json";

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

// Lire les données envoyées
function getRequestData() {
    $raw = file_get_contents("php://input");

    if (empty($raw)) {
        return [];
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        response(400, [
            "error" => "Format JSON invalide."
        ]);
    }

    // Accepter aussi { "contact": { ... } }
    if (isset($data["contact"]) && is_array($data["contact"])) {
        return $data["contact"];
    }

    return $data;
}

// Lire les coordonnées
function readContact($file) {
    if (!file_exists($file)) {
        response(500, [
            "error" => "Le fichier contacts.json est introuvable."
        ]);
    }

    $content = file_get_contents($file);

    if ($content === false) {
        response(500, [
            "error" => "Impossible de lire contacts.json."
        ]);
    }

    $data = json_decode($content, true);

    if (
        !is_array($data) ||
        !isset($data["contact"]) ||
        !is_array($data["contact"])
    ) {
        response(500, [
            "error" => "Structure contacts.json invalide."
        ]);
    }

    return $data["contact"];
}

// Nettoyer les données
function cleanContact($contact) {
    return [
        "email" => (string) $contact["email"],
        "phone" => (string) $contact["phone"],
        "phoneHref" => (string) $contact["phoneHref"],
        "address" => (string) $contact["address"],
        "hours" => (string) $contact["hours"],
        "whatsapp" => $contact["whatsapp"],
        "socialLinks" => array_values(
            $contact["socialLinks"]
        )
    ];
}

// Vérifier les données
function validateContact($contact) {

    $required = [
        "email",
        "phone",
        "phoneHref",
        "address",
        "hours",
        "whatsapp",
        "socialLinks"
    ];

    foreach ($required as $field) {
        if (!array_key_exists($field, $contact)) {
            return "Le champ " . $field . " est obligatoire.";
        }
    }

    // Email
    if (
        !is_string($contact["email"]) ||
        !filter_var($contact["email"], FILTER_VALIDATE_EMAIL)
    ) {
        return "Adresse e-mail invalide.";
    }

    // Champs texte
    $textFields = [
        "phone",
        "phoneHref",
        "address",
        "hours"
    ];

    foreach ($textFields as $field) {
        if (
            !is_string($contact[$field]) ||
            trim($contact[$field]) === ""
        ) {
            return "Le champ " . $field . " doit être un texte non vide.";
        }
    }

    // WhatsApp : texte ou null
    if (
        $contact["whatsapp"] !== null &&
        (
            !is_string($contact["whatsapp"]) ||
            trim($contact["whatsapp"]) === ""
        )
    ) {
        return "WhatsApp doit être un numéro ou null.";
    }

    // Réseaux sociaux
    if (!is_array($contact["socialLinks"])) {
        return "Les réseaux sociaux doivent être un tableau.";
    }

    foreach ($contact["socialLinks"] as $social) {
        if (
            !is_array($social) ||
            !isset($social["label"], $social["url"]) ||
            !is_string($social["label"]) ||
            !is_string($social["url"]) ||
            trim($social["label"]) === "" ||
            !filter_var($social["url"], FILTER_VALIDATE_URL)
        ) {
            return "Un lien de réseau social est invalide.";
        }
    }

    return null;
}

// Enregistrer les coordonnées
function saveContact($file, $contact) {
    $directory = dirname($file);

    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $data = [
        "contact" => cleanContact($contact)
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

// ==========================================
// 3. GET - AFFICHER
// ==========================================

$method = strtoupper($_SERVER["REQUEST_METHOD"]);
funbikeProtectWriteMethods($method);

if ($method === "GET") {

    $contact = readContact($dataFile);

    response(200, [
        "contact" => cleanContact($contact)
    ]);
}

// ==========================================
// 4. PUT - MODIFIER
// ==========================================

if ($method === "PUT") {

    $body = getRequestData();

    $currentContact = readContact($dataFile);

    // Mise à jour des propriétés envoyées
    $updatedContact = array_merge(
        $currentContact,
        $body
    );

    $error = validateContact($updatedContact);

    if ($error !== null) {
        response(400, [
            "error" => $error
        ]);
    }

    if (!saveContact($dataFile, $updatedContact)) {
        response(500, [
            "error" => "Erreur lors de l'enregistrement."
        ]);
    }

    response(200, [
        "message" => "Coordonnées FUNBIKE mises à jour.",
        "contact" => cleanContact($updatedContact)
    ]);
}

// ==========================================
// 5. MÉTHODE NON AUTORISÉE
// ==========================================

response(405, [
    "error" => "Méthode non autorisée."
]);
