<?php

// Migration des versions précédentes :
// php scripts/promote-superadmin.php nom_utilisateur_existant

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . "/api/_lib/user-store.php";

$username = $argv[1] ?? "";

if ($username === "") {
    fwrite(STDERR, "Usage : php scripts/promote-superadmin.php nom_utilisateur_existant\n");
    exit(1);
}

try {
    $store = new FunbikeUserStore();
    $user = $store->promoteSuperAdmin($username);
} catch (Throwable $error) {
    fwrite(STDERR, "Erreur : " . $error->getMessage() . "\n");
    exit(1);
}

if (!$user) {
    fwrite(STDERR, "Erreur : ce compte n'existe pas.\n");
    exit(1);
}

fwrite(STDOUT, "Compte superadmin confirmé : " . $user["username"] . "\n");
fwrite(STDOUT, "Aucun nouveau compte n'a été créé. Ses anciennes sessions ont été révoquées.\n");
