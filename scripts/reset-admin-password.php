<?php

// Usage : php scripts/reset-admin-password.php nom_utilisateur
// Réinitialise le mot de passe et révoque les sessions existantes.

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . "/api/_lib/user-store.php";

$username = $argv[1] ?? "";

if ($username === "") {
    fwrite(STDERR, "Usage : php scripts/reset-admin-password.php nom_utilisateur\n");
    exit(1);
}

$configuredPassword = getenv("FUNBIKE_TEMP_PASSWORD");
$generated = $configuredPassword === false || $configuredPassword === "";
$temporaryPassword = $generated
    ? bin2hex(random_bytes(12))
    : $configuredPassword;

try {
    $store = new FunbikeUserStore();
    $user = $store->resetPassword($username, $temporaryPassword);
} catch (Throwable $error) {
    fwrite(STDERR, "Erreur : " . $error->getMessage() . "\n");
    exit(1);
}

if (!$user) {
    fwrite(STDERR, "Erreur : cet administrateur n'existe pas.\n");
    exit(1);
}

fwrite(STDOUT, "Mot de passe réinitialisé pour : " . $user["username"] . "\n");

if ($generated) {
    fwrite(STDOUT, "Nouveau mot de passe temporaire : " . $temporaryPassword . "\n");
}

fwrite(STDOUT, "Le changement de mot de passe sera obligatoire à la prochaine connexion.\n");
