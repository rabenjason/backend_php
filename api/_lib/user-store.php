<?php

// =====================================================
// FUNBIKE - STOCKAGE PRIVÉ DES ADMINISTRATEURS
// =====================================================

/**
 * Stockage JSON très volontairement limité aux opérations nécessaires :
 * trouver un compte, créer un admin et changer/réinitialiser son mot de passe.
 * Il n'existe aucune méthode HTTP pour lister les utilisateurs.
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

        // PASSWORD_DEFAULT utilise actuellement bcrypt, qui ignore les
        // caractères au-delà de 72 octets. On évite cette ambiguïté.
        if ($length > 72) {
            return "Le mot de passe ne peut pas dépasser 72 octets.";
        }

        return null;
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

    public function createAdmin($username, $temporaryPassword)
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

        return $this->mutateData(function (&$data) use ($username, $temporaryPassword) {
            foreach ($data["users"] as $user) {
                if (($user["username"] ?? null) === $username) {
                    throw new RuntimeException("Ce nom d'utilisateur existe déjà.");
                }
            }

            $now = gmdate("c");
            $user = [
                "id" => bin2hex(random_bytes(16)),
                "username" => $username,
                "passwordHash" => $this->hashPassword($temporaryPassword),
                "role" => "admin",
                "mustChangePassword" => true,
                "authVersion" => 1,
                "createdAt" => $now,
                "updatedAt" => $now
            ];

            $data["users"][] = $user;

            return $user;
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

                $data["users"][$index]["passwordHash"] = $this->hashPassword($newPassword);
                $data["users"][$index]["mustChangePassword"] = false;
                $data["users"][$index]["authVersion"] = (int) ($user["authVersion"] ?? 1) + 1;
                $data["users"][$index]["passwordChangedAt"] = gmdate("c");
                $data["users"][$index]["updatedAt"] = gmdate("c");

                return $data["users"][$index];
            }

            return null;
        });
    }

    public function resetPassword($username, $temporaryPassword)
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

                $data["users"][$index]["passwordHash"] = $this->hashPassword($temporaryPassword);
                $data["users"][$index]["mustChangePassword"] = true;
                $data["users"][$index]["authVersion"] = (int) ($user["authVersion"] ?? 1) + 1;
                $data["users"][$index]["updatedAt"] = gmdate("c");
                unset($data["users"][$index]["passwordChangedAt"]);

                return $data["users"][$index];
            }

            return null;
        });
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

    /**
     * Exécute lecture + modification + écriture sous le même verrou exclusif.
     * Le remplacement atomique évite qu'un lecteur reçoive un JSON partiel.
     */
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

/** Seules ces informations non sensibles peuvent être renvoyées au frontend. */
function funbikePublicUser($user)
{
    return [
        "id" => (string) $user["id"],
        "username" => (string) $user["username"],
        "role" => (string) ($user["role"] ?? "admin"),
        "mustChangePassword" => (bool) ($user["mustChangePassword"] ?? false)
    ];
}
