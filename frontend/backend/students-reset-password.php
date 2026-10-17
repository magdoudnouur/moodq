<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'Méthode non autorisée. Utilisez POST.']);
}

$admin = require_login();
if (($admin['role'] ?? '') !== 'Administrateur') {
    respond(403, ['error' => 'Seul un administrateur peut réinitialiser un mot de passe.']);
}

$body = read_json_body();
$username = strtolower(trim((string) ($body['username'] ?? '')));
$email = trim((string) ($body['email'] ?? ''));
$firstName = trim((string) ($body['firstName'] ?? ''));
$lastName = trim((string) ($body['lastName'] ?? ''));
$password = (string) ($body['password'] ?? '');
if (($username === '' && $email === '' && ($firstName === '' || $lastName === '')) || strlen($password) < 8) {
    respond(400, ['error' => 'Username, email ou prénom + nom, et un mot de passe de 8 caractères minimum sont requis.']);
}

$pdo = moodq_pdo();
$lookup = $pdo->prepare("
    SELECT id, username
    FROM mdl_user
    WHERE deleted = 0
      AND (
        (:username <> '' AND username = :username)
        OR (:email <> '' AND email = :email)
        OR (:firstName <> '' AND :lastName <> '' AND firstname = :firstName AND lastname = :lastName)
      )
    LIMIT 1
");
$lookup->execute([
    ':username' => $username,
    ':email' => $email,
    ':firstName' => $firstName,
    ':lastName' => $lastName,
]);
$account = $lookup->fetch();
if (!$account) {
    respond(404, ['error' => 'Compte Moodle introuvable. Vérifiez username, email, prénom et nom.']);
}

$stmt = $pdo->prepare("
    UPDATE mdl_user
    SET password = :password, auth = 'manual', suspended = 0, deleted = 0, timemodified = :now
    WHERE id = :id
");
$stmt->execute([
    ':password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]),
    ':now' => time(),
    ':id' => (int) $account['id'],
]);

respond(200, ['message' => 'Mot de passe Moodle réinitialisé.', 'username' => $account['username']]);
