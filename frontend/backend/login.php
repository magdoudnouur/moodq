<?php
/**
 * login.php
 * -----------------------------------------------------------
 * POST /login.php  Body: { "username": "...", "password": "..." }
 *
 * Vérifie les identifiants contre la vraie table mdl_user de
 * Moodle (hash crypt(), compatible password_verify()), puis
 * vérifie que l'utilisateur a un rôle enseignant/manager ou est
 * admin du site. Démarre une session PHP si tout est valide.
 * -----------------------------------------------------------
 */
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'Méthode non autorisée. Utilisez POST.']);
}

$body = read_json_body();
$username = strtolower(trim((string) ($body['username'] ?? '')));
$password = (string) ($body['password'] ?? '');

if ($username === '' || $password === '') {
    respond(400, ['error' => 'Identifiant et mot de passe requis.']);
}

$pdo = moodq_pdo();

$stmt = $pdo->prepare("
    SELECT id, username, firstname, lastname, password
    FROM mdl_user
    WHERE (LOWER(username) = :username OR LOWER(email) = :username) AND deleted = 0 AND suspended = 0
");
$stmt->execute([':username' => $username]);
$user = $stmt->fetch();

// Moodle stores manual passwords as crypt-compatible bcrypt hashes. The
// crypt fallback also supports hashes created by older Moodle installations.
$passwordMatches = $user
    && (password_verify($password, (string) $user['password'])
        || hash_equals((string) $user['password'], crypt($password, (string) $user['password'])));
if (!$passwordMatches) {
    respond(401, ['error' => 'Identifiant ou mot de passe incorrect.']);
}

// Vérifie que l'utilisateur est admin du site OU a un rôle
// enseignant/manager sur au moins un cours.
$isSiteAdmin = false;
$configStmt = $pdo->prepare("SELECT value FROM mdl_config WHERE name = 'siteadmins'");
$configStmt->execute();
$siteAdminIds = explode(',', (string) $configStmt->fetchColumn());
if (in_array((string) $user['id'], $siteAdminIds, true)) {
    $isSiteAdmin = true;
}

$isTeacher = false;
if (!$isSiteAdmin) {
    $roleStmt = $pdo->prepare("
        SELECT COUNT(*) FROM mdl_role_assignments ra
        JOIN mdl_role r ON r.id = ra.roleid
        WHERE ra.userid = :userId AND r.shortname IN ('editingteacher', 'teacher', 'manager')
    ");
    $roleStmt->execute([':userId' => $user['id']]);
    $isTeacher = ((int) $roleStmt->fetchColumn()) > 0;
}

if (!$isSiteAdmin && !$isTeacher) {
    $studentStmt = $pdo->prepare('SELECT COUNT(*) FROM mdl_user_enrolments WHERE userid = :userid');
    $studentStmt->execute([':userid' => $user['id']]);
    if ((int) $studentStmt->fetchColumn() === 0) {
        respond(403, ['error' => 'Accès réservé aux utilisateurs Moodle inscrits à un cours.']);
    }
}

session_regenerate_id(true);
$_SESSION['moodq_user'] = [
    'id' => (int) $user['id'],
    'username' => $user['username'],
    'name' => $user['firstname'] . ' ' . $user['lastname'],
    'initials' => mb_strtoupper(mb_substr($user['firstname'], 0, 1) . mb_substr($user['lastname'], 0, 1)),
    'role' => $isSiteAdmin ? 'Administrateur' : ($isTeacher ? 'Enseignant' : 'Étudiant'),
];

respond(200, ['user' => $_SESSION['moodq_user']]);