<?php

// Usage unique : php scripts/create-admin.php nom_superadmin
// Crée le seul superadmin, uniquement si users.json ne contient aucun compte.

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . "/api/_lib/user-store.php";

$username = $argv[1] ?? "";

if ($username === "") {
    fwrite(STDERR, "Usage : php scripts/create-admin.php nom_superadmin\n");
    exit(1);
}

$configuredPassword = getenv("FUNBIKE_TEMP_PASSWORD");
$generated = $configuredPassword === false || $configuredPassword === "";
$temporaryPassword = $generated
    ? FunbikeUserStore::generateTemporaryPassword()
    : $configuredPassword;

try {
    $store = new FunbikeUserStore();
    $user = $store->createInitialSuperAdmin($username, $temporaryPassword);
} catch (Throwable $error) {
    fwrite(STDERR, "Erreur : " . $error->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "Superadministrateur initial créé : " . $user["username"] . "\n");

if ($generated) {
    fwrite(STDOUT, "Mot de passe temporaire : " . $temporaryPassword . "\n");
}

fwrite(STDOUT, "Il devra changer ce mot de passe à la première connexion.\n");
fwrite(STDOUT, "Les prochains admins devront être créés par ce superadmin, pas par cette commande.\n");
