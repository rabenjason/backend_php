<?php

// Récupération d'urgence du seul superadmin :
// php scripts/reset-admin-password.php nom_superadmin

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . "/api/_lib/user-store.php";

$username = $argv[1] ?? "";

if ($username === "") {
    fwrite(STDERR, "Usage : php scripts/reset-admin-password.php nom_superadmin\n");
    exit(1);
}

$configuredPassword = getenv("FUNBIKE_TEMP_PASSWORD");
$generated = $configuredPassword === false || $configuredPassword === "";
$temporaryPassword = $generated
    ? FunbikeUserStore::generateTemporaryPassword()
    : $configuredPassword;

try {
    $store = new FunbikeUserStore();
    $user = $store->resetSuperAdminPassword($username, $temporaryPassword);
} catch (Throwable $error) {
    fwrite(STDERR, "Erreur : " . $error->getMessage() . "\n");
    exit(1);
}

if (!$user) {
    fwrite(STDERR, "Erreur : ce superadmin n'existe pas.\n");
    exit(1);
}

fwrite(STDOUT, "Mot de passe du superadmin réinitialisé pour : " . $user["username"] . "\n");

if ($generated) {
    fwrite(STDOUT, "Nouveau mot de passe temporaire : " . $temporaryPassword . "\n");
}

fwrite(STDOUT, "Le changement sera obligatoire à la prochaine connexion et les sessions existantes sont révoquées.\n");
