<?php

// Usage : php scripts/create-admin.php nom_utilisateur
// Le mot de passe temporaire est affiché une seule fois dans le terminal.

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . "/api/_lib/user-store.php";

$username = $argv[1] ?? "";

if ($username === "") {
    fwrite(STDERR, "Usage : php scripts/create-admin.php nom_utilisateur\n");
    exit(1);
}

// Une variable d'environnement peut être utilisée par un outil de déploiement.
// Sinon, un mot de passe aléatoire est généré sans jamais passer dans l'historique shell.
$configuredPassword = getenv("FUNBIKE_TEMP_PASSWORD");
$generated = $configuredPassword === false || $configuredPassword === "";
$temporaryPassword = $generated
    ? bin2hex(random_bytes(12))
    : $configuredPassword;

try {
    $store = new FunbikeUserStore();
    $user = $store->createAdmin($username, $temporaryPassword);
} catch (Throwable $error) {
    fwrite(STDERR, "Erreur : " . $error->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "Administrateur créé : " . $user["username"] . "\n");

if ($generated) {
    fwrite(STDOUT, "Mot de passe temporaire : " . $temporaryPassword . "\n");
}

fwrite(STDOUT, "Il devra obligatoirement changer ce mot de passe à la première connexion.\n");
