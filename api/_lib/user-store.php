<?php

// =====================================================
// FUNBIKE - STOCKAGE PRIVÉ DES UTILISATEURS
// =====================================================

class FunbikeDuplicateUsernameException extends RuntimeException
{
}

/**
 * Un seul superadmin peut exister. Les autres comptes sont forcément des
 * admins et seul le superadmin authentifié peut les gérer via l'API.
 * Les mots de passe en clair ne sont jamais écrits dans le JSON.
 */
class FunbikeUserStore
{
    private $file;

    public function __construct($file = null)
    {
        $configuredFile = getenv("FUNBIKE_USERS_FILE");

        $this->file = $file
            ?: ($configuredFile !== false && trim($configuredFile) !== ""
                ? $configuredFile
                : dirname(__DIR__, 2) . "/storage/private/users.json");
    }

    public static function normalizeUsername($username)
    {
        return strtolower(trim((string) $username));
    }

    public static function validateUsername($username)
    {
        $username = self::normalizeUsername($username);

        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,31}$/', $username)) {
            return "Le nom d'utilisateur doit contenir 3 à 32 caractères : lettres minuscules, chiffres, point, tiret ou underscore.";
        }

        return null;
    }

    public static function validatePassword($password)
    {
        if (!is_string($password)) {
            return "Le mot de passe doit être une chaîne de caractères.";
        }

        $length = strlen($password);

        if ($length < 12) {
            return "Le mot de passe doit contenir au moins 12 caractères.";
        }

        if ($length > 72) {
            return "Le mot de passe ne peut pas dépasser 72 octets.";
        }

        return null;
    }

    public static function generateTemporaryPassword()
    {
        return bin2hex(random_bytes(12));
    }

    public function listUsers()
    {
        $data = $this->readData();

        return $data["users"];
    }

    public function findByUsername($username)
    {
        $username = self::normalizeUsername($username);
        $data = $this->readData();

        foreach ($data["users"] as $user) {
            if (
                isset($user["username"]) &&
                hash_equals((string) $user["username"], $username)
            ) {
                return $user;
            }
        }

        return null;
    }

    public function findById($id)
    {
        $data = $this->readData();

        foreach ($data["users"] as $user) {
            if (isset($user["id"]) && hash_equals((string) $user["id"], (string) $id)) {
                return $user;
            }
        }

        return null;
    }

    /** Commande de bootstrap : autorisée uniquement avec zéro compte existant. */
    public function createInitialSuperAdmin($username, $temporaryPassword)
    {
        return $this->createUser($username, $temporaryPassword, "superadmin", true);
    }

    /** Utilisé par l'API superadmin : impossible de choisir un autre rôle. */
    public function createAdmin($username, $temporaryPassword)
    {
        return $this->createUser($username, $temporaryPassword, "admin", false);
    }

    private function createUser($username, $temporaryPassword, $role, $requireEmptyStore)
    {
        $username = self::normalizeUsername($username);
        $usernameError = self::validateUsername($username);

        if ($usernameError !== null) {
            throw new InvalidArgumentException($usernameError);
        }

        $passwordError = self::validatePassword($temporaryPassword);

        if ($passwordError !== null) {
            throw new InvalidArgumentException($passwordError);
        }

        return $this->mutateData(function (&$data) use (
            $username,
            $temporaryPassword,
            $role,
            $requireEmptyStore
        ) {
            if ($requireEmptyStore && count($data["users"]) > 0) {
                throw new RuntimeException(
                    "Création initiale refusée : des comptes existent déjà. " .
                    "Les nouveaux admins doivent être créés par le superadmin. " .
                    "Pour un ancien fichier sans superadmin, utilisez scripts/promote-superadmin.php."
                );
            }

            foreach ($data["users"] as $user) {
                if (($user["username"] ?? null) === $username) {
                    throw new FunbikeDuplicateUsernameException("Ce nom d'utilisateur existe déjà.");
                }
            }

            $now = gmdate("c");
            $user = [
                "id" => bin2hex(random_bytes(16)),
                "username" => $username,
                "passwordHash" => $this->hashPassword($temporaryPassword),
                "role" => $role,
                "mustChangePassword" => true,
                "authVersion" => 1,
                "createdAt" => $now,
                "updatedAt" => $now
            ];

            $data["users"][] = $user;

            return $user;
        });
    }

    /**
     * Migration unique des anciennes installations : transforme un compte
     * existant en superadmin, mais seulement s'il n'y en a aucun autre.
     */
    public function promoteSuperAdmin($username)
    {
        $username = self::normalizeUsername($username);

        return $this->mutateData(function (&$data) use ($username) {
            $targetIndex = -1;
            $existingSuperAdmin = null;

            foreach ($data["users"] as $index => $user) {
                if (($user["username"] ?? null) === $username) {
                    $targetIndex = $index;
                }

                if (($user["role"] ?? "admin") === "superadmin") {
                    $existingSuperAdmin = $user;
                }
            }

            if ($targetIndex === -1) {
                return null;
            }

            if (
                $existingSuperAdmin !== null &&
                ($existingSuperAdmin["username"] ?? null) !== $username
            ) {
                throw new RuntimeException("Un autre superadmin existe déjà : " . $existingSuperAdmin["username"]);
            }

            // Même si le compte était déjà superadmin, relancer cette commande
            // révoque ses sessions comme indiqué par le script CLI.
            $data["users"][$targetIndex]["role"] = "superadmin";
            $data["users"][$targetIndex]["authVersion"] =
                (int) ($data["users"][$targetIndex]["authVersion"] ?? 1) + 1;
            $data["users"][$targetIndex]["updatedAt"] = gmdate("c");

            return $data["users"][$targetIndex];
        });
    }

    public function changePassword($id, $newPassword)
    {
        $passwordError = self::validatePassword($newPassword);

        if ($passwordError !== null) {
            throw new InvalidArgumentException($passwordError);
        }

        return $this->mutateData(function (&$data) use ($id, $newPassword) {
            foreach ($data["users"] as $index => $user) {
                if (($user["id"] ?? null) !== $id) {
                    continue;
                }

                $this->applyPassword($data["users"][$index], $newPassword, false);

                return $data["users"][$index];
            }

            return null;
        });
    }

    /** Récupération d'urgence CLI réservée au superadmin. */
    public function resetSuperAdminPassword($username, $temporaryPassword)
    {
        $username = self::normalizeUsername($username);
        $passwordError = self::validatePassword($temporaryPassword);

        if ($passwordError !== null) {
            throw new InvalidArgumentException($passwordError);
        }

        return $this->mutateData(function (&$data) use ($username, $temporaryPassword) {
            foreach ($data["users"] as $index => $user) {
                if (($user["username"] ?? null) !== $username) {
                    continue;
                }

                if (($user["role"] ?? "admin") !== "superadmin") {
                    throw new RuntimeException(
                        "La récupération CLI est réservée au superadmin. " .
                        "Un admin doit être réinitialisé par le superadmin via l'API."
                    );
                }

                $this->applyPassword($data["users"][$index], $temporaryPassword, true);

                return $data["users"][$index];
            }

            return null;
        });
    }

    /** Réinitialisation d'un admin par le superadmin, avec révocation des sessions. */
    public function resetAdminPasswordById($id, $temporaryPassword)
    {
        $passwordError = self::validatePassword($temporaryPassword);

        if ($passwordError !== null) {
            throw new InvalidArgumentException($passwordError);
        }

        return $this->mutateData(function (&$data) use ($id, $temporaryPassword) {
            foreach ($data["users"] as $index => $user) {
                if (($user["id"] ?? null) !== $id) {
                    continue;
                }

                if (($user["role"] ?? "admin") !== "admin") {
                    throw new RuntimeException("Seul le mot de passe d'un admin peut être réinitialisé ici.");
                }

                $this->applyPassword($data["users"][$index], $temporaryPassword, true);

                return $data["users"][$index];
            }

            return null;
        });
    }

    /** Un superadmin ne peut jamais être supprimé par cette méthode. */
    public function deleteAdminById($id)
    {
        return $this->mutateData(function (&$data) use ($id) {
            foreach ($data["users"] as $index => $user) {
                if (($user["id"] ?? null) !== $id) {
                    continue;
                }

                if (($user["role"] ?? "admin") !== "admin") {
                    throw new RuntimeException("Le superadmin ne peut pas être supprimé.");
                }

                unset($data["users"][$index]);
                $data["users"] = array_values($data["users"]);

                return $user;
            }

            return null;
        });
    }

    private function applyPassword(&$user, $password, $mustChangePassword)
    {
        $user["passwordHash"] = $this->hashPassword($password);
        $user["mustChangePassword"] = $mustChangePassword;
        $user["authVersion"] = (int) ($user["authVersion"] ?? 1) + 1;
        $user["updatedAt"] = gmdate("c");

        if ($mustChangePassword) {
            unset($user["passwordChangedAt"]);
        } else {
            $user["passwordChangedAt"] = gmdate("c");
        }
    }

    private function hashPassword($password)
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        if (!is_string($hash) || $hash === "") {
            throw new RuntimeException("Impossible de hacher le mot de passe.");
        }

        return $hash;
    }

    private function readData()
    {
        if (!file_exists($this->file)) {
            return ["users" => []];
        }

        $content = file_get_contents($this->file);

        if ($content === false) {
            throw new RuntimeException("Impossible de lire le fichier privé des utilisateurs.");
        }

        $data = json_decode($content, true);

        if (
            !is_array($data) ||
            !isset($data["users"]) ||
            !is_array($data["users"])
        ) {
            throw new RuntimeException("Le fichier privé des utilisateurs est invalide.");
        }

        return $data;
    }

    private function mutateData($callback)
    {
        $directory = dirname($this->file);

        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException("Impossible de créer le dossier privé des utilisateurs.");
        }

        $lock = fopen($this->file . ".lock", "c");

        if ($lock === false) {
            throw new RuntimeException("Impossible d'ouvrir le verrou des utilisateurs.");
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException("Impossible de verrouiller les utilisateurs.");
            }

            $data = $this->readData();
            $result = call_user_func_array($callback, [&$data]);
            $this->writeData($data);

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function writeData($data)
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException("Impossible d'encoder les utilisateurs.");
        }

        $directory = dirname($this->file);
        $temporaryFile = tempnam($directory, ".users-");

        if ($temporaryFile === false) {
            throw new RuntimeException("Impossible de créer le fichier temporaire.");
        }

        try {
            if (file_put_contents($temporaryFile, $json, LOCK_EX) === false) {
                throw new RuntimeException("Impossible d'écrire le fichier des utilisateurs.");
            }

            @chmod($temporaryFile, 0600);

            if (!rename($temporaryFile, $this->file)) {
                throw new RuntimeException("Impossible de remplacer le fichier des utilisateurs.");
            }
        } finally {
            if (file_exists($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }
}

/** Informations non sensibles renvoyées pour le compte connecté. */
function funbikePublicUser($user)
{
    return [
        "id" => (string) $user["id"],
        "username" => (string) $user["username"],
        "role" => (string) ($user["role"] ?? "admin"),
        "mustChangePassword" => (bool) ($user["mustChangePassword"] ?? false)
    ];
}

/** Liste de gestion réservée au superadmin : jamais de passwordHash. */
function funbikeManagedUser($user)
{
    return array_merge(funbikePublicUser($user), [
        "createdAt" => (string) ($user["createdAt"] ?? ""),
        "updatedAt" => (string) ($user["updatedAt"] ?? "")
    ]);
}
