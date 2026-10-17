<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'Méthode non autorisée. Utilisez POST.']);
}

$user = require_login();
if (($user['role'] ?? '') !== 'Administrateur') {
    respond(403, ['error' => 'Seul un administrateur peut créer un étudiant.']);
}

$body = read_json_body();
$firstName = trim((string) ($body['firstName'] ?? ''));
$lastName = trim((string) ($body['lastName'] ?? ''));
$email = trim((string) ($body['email'] ?? ''));
$username = strtolower(trim((string) ($body['username'] ?? '')));
$password = (string) ($body['password'] ?? '');
$courses = array_values(array_unique(array_filter(array_map('strval', (array) ($body['courses'] ?? [])))));

if ($firstName === '' || $lastName === '' || $email === '' || $username === '' || $password === '') {
    respond(400, ['error' => 'Prénom, nom, email, identifiant et mot de passe sont requis.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(400, ['error' => 'Adresse email invalide.']);
}
if (strlen($password) < 8) {
    respond(400, ['error' => 'Le mot de passe doit contenir au moins 8 caractères.']);
}
if (!preg_match('/^[a-z0-9._-]+$/', $username)) {
    respond(400, ['error' => 'L’identifiant doit contenir uniquement des lettres minuscules, chiffres, points, tirets ou underscores.']);
}

try {
    $pdo = moodq_pdo();
    $pdo->beginTransaction();
    $now = time();

    $exists = $pdo->prepare('SELECT id, username, email FROM mdl_user WHERE username = :username OR email = :email LIMIT 1');
    $exists->execute([':username' => $username, ':email' => $email]);
    $existing = $exists->fetch();
    if ($existing !== false) {
        if ((string) $existing['username'] !== $username && (string) $existing['email'] === $email) {
            $pdo->rollBack();
            respond(409, ['error' => 'Cette adresse email appartient déjà à un autre username Moodle.']);
        }
        $studentId = (int) $existing['id'];
        $update = $pdo->prepare("
            UPDATE mdl_user
            SET password = :password, firstname = :firstname, lastname = :lastname,
                email = :email, auth = 'manual', confirmed = 1, suspended = 0,
                deleted = 0, timemodified = :now
            WHERE id = :id
        ");
        $update->execute([
            ':password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]),
            ':firstname' => $firstName,
            ':lastname' => $lastName,
            ':email' => $email,
            ':now' => $now,
            ':id' => $studentId,
        ]);
    } else {
        $studentId = 0;
    }

    if ($studentId === 0) {
      $insert = $pdo->prepare("
        INSERT INTO mdl_user
            (auth, confirmed, policyagreed, deleted, suspended, mnethostid,
             username, password, firstname, lastname, email, emailstop,
             lang, calendartype, timezone, timecreated, timemodified)
        VALUES
            ('manual', 1, 0, 0, 0, 1,
             :username, :password, :firstname, :lastname, :email, 0,
             'en', 'gregorian', '99', :now, :now)
      ");
      $insert->execute([
        ':username' => $username,
        // Moodle 4.5 uses bcrypt-compatible crypt hashes for manual accounts.
        ':password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]),
        ':firstname' => $firstName,
        ':lastname' => $lastName,
        ':email' => $email,
        ':now' => $now,
      ]);
      $studentId = (int) $pdo->lastInsertId();
    }

    $enrolled = [];
    foreach ($courses as $shortname) {
        $course = $pdo->prepare('SELECT id FROM mdl_course WHERE shortname = :shortname AND visible = 1 LIMIT 1');
        $course->execute([':shortname' => $shortname]);
        $courseId = $course->fetchColumn();
        if ($courseId === false) {
            continue;
        }

        $enrol = $pdo->prepare("SELECT id FROM mdl_enrol WHERE courseid = :courseid AND enrol = 'manual' AND status = 0 ORDER BY sortorder, id LIMIT 1");
        $enrol->execute([':courseid' => $courseId]);
        $enrolId = $enrol->fetchColumn();
        if ($enrolId === false) {
            continue;
        }

        $membership = $pdo->prepare('SELECT id FROM mdl_user_enrolments WHERE enrolid = :enrolid AND userid = :userid LIMIT 1');
        $membership->execute([':enrolid' => $enrolId, ':userid' => $studentId]);
        if ($membership->fetchColumn() === false) {
            $add = $pdo->prepare('
                INSERT INTO mdl_user_enrolments
                    (status, enrolid, userid, timestart, timeend, modifierid, timecreated, timemodified)
                VALUES (0, :enrolid, :userid, :now, 0, :modifierid, :now, :now)
            ');
            $add->execute([
                ':enrolid' => $enrolId,
                ':userid' => $studentId,
                ':now' => $now,
                ':modifierid' => (int) $user['id'],
            ]);
            $enrolled[] = $shortname;
        }
    }

    $pdo->commit();

    try {
        $coursesListStr = empty($enrolled) ? 'aucun cours' : implode(', ', $enrolled);
        $notifStmt = $pdo->prepare("
            INSERT INTO moodq_notifications (type, title, message, ref_key)
            VALUES ('enrollment', :title, :message, :ref_key)
        ");
        $notifStmt->execute([
            ':title' => 'Nouvel étudiant ajouté',
            ':message' => "L'étudiant $firstName $lastName a été créé et inscrit à : $coursesListStr.",
            ':ref_key' => 'student_created:' . $studentId . ':' . time(),
        ]);
    } catch (Throwable $ignore) {
        // Best-effort
    }

    respond(201, [
        'message' => 'Étudiant créé dans Moodle.',
        'student' => [
            'id' => $studentId,
            'username' => $username,
            'name' => "$firstName $lastName",
            'email' => $email,
            'courses' => $enrolled,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[MoodQ students-create.php] ' . $e->getMessage());
    respond(500, ['error' => 'Création Moodle impossible : ' . $e->getMessage()]);
}
