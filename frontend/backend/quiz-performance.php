<?php
/**
 * quiz-performance.php
 * -----------------------------------------------------------
 * Module Performance des Quiz pour MoodQ.
 *
 * Endpoints :
 * - GET /quiz-performance.php?action=courses
 *       Liste des cours accessibles à l'utilisateur
 * - GET /quiz-performance.php?course=CS101
 *       Données complètes du tableau de bord (résumé, étudiants, quiz, graphique)
 * - GET /quiz-performance.php?course=CS101&student=23
 *       Détail individuel des quiz d'un étudiant
 * -----------------------------------------------------------
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['error' => 'Méthode non autorisée. Utilisez GET.']);
}

$currentUser = require_login();

function moodle_table(string $table): string
{
    global $CFG;
    $prefix = defined('DB_PREFIX') ? DB_PREFIX : ($CFG->prefix ?? 'mdl_');
    return $prefix . $table;
}

try {
    $pdo = moodq_pdo();

    $courseTable     = moodle_table('course');
    $quizTable       = moodle_table('quiz');
    $attemptsTable   = moodle_table('quiz_attempts');
    $gradesTable     = moodle_table('quiz_grades');
    $userTable       = moodle_table('user');
    $enrolTable      = moodle_table('enrol');
    $userEnrolTable  = moodle_table('user_enrolments');
    $roleAssignTable = moodle_table('role_assignments');
    $roleTable       = moodle_table('role');

    // 1. Détermination des cours accessibles selon le rôle (Admin vs Enseignant vs Étudiant)
    $isAdmin = ($currentUser['role'] === 'Administrateur');
    $accessibleCourseIds = [];

    if ($isAdmin) {
        $stmtCourses = $pdo->query("SELECT id, shortname, fullname FROM `{$courseTable}` WHERE id > 1 AND category > 0 ORDER BY id");
        $courses = $stmtCourses->fetchAll();
    } else {
        // Enseignant : cours où l'utilisateur a un rôle enseignant / manager
        $stmtTeacher = $pdo->prepare("
            SELECT DISTINCT c.id, c.shortname, c.fullname
            FROM `{$courseTable}` c
            JOIN `{$enrolTable}` e ON e.courseid = c.id
            JOIN `{$userEnrolTable}` ue ON ue.enrolid = e.id AND ue.userid = :userId
            WHERE c.id > 1 AND c.category > 0
            ORDER BY c.id
        ");
        $stmtTeacher->execute([':userId' => $currentUser['id']]);
        $courses = $stmtTeacher->fetchAll();

        // Si aucun cours par inscription directe, vérifier via les affectations de rôles
        if (empty($courses)) {
            $stmtRole = $pdo->prepare("
                SELECT DISTINCT c.id, c.shortname, c.fullname
                FROM `{$courseTable}` c
                JOIN `{$roleAssignTable}` ra ON ra.userid = :userId
                JOIN `{$roleTable}` r ON r.id = ra.roleid AND r.shortname IN ('editingteacher', 'teacher', 'manager')
                WHERE c.id > 1 AND c.category > 0
                ORDER BY c.id
            ");
            $stmtRole->execute([':userId' => $currentUser['id']]);
            $courses = $stmtRole->fetchAll();
        }
    }

    $action = $_GET['action'] ?? '';
    if ($action === 'courses' || empty($_GET['course'])) {
        respond(200, ['courses' => $courses]);
    }

    // 2. Résolution du cours demandé (par shortname ou ID numérique)
    $courseParam = trim((string) $_GET['course']);
    $courseId = null;
    $currentCourse = null;

    foreach ($courses as $c) {
        if ((string)$c['id'] === $courseParam || strcasecmp($c['shortname'], $courseParam) === 0) {
            $courseId = (int) $c['id'];
            $currentCourse = $c;
            break;
        }
    }

    if (!$courseId) {
        // Si admin, autoriser n'importe quel cours valide même non présent dans le cache
        if ($isAdmin) {
            $stmtFind = $pdo->prepare("SELECT id, shortname, fullname FROM `{$courseTable}` WHERE (id = :p OR shortname = :p2) AND id > 1 LIMIT 1");
            $stmtFind->execute([':p' => $courseParam, ':p2' => $courseParam]);
            $found = $stmtFind->fetch();
            if ($found) {
                $courseId = (int) $found['id'];
                $currentCourse = $found;
            }
        }
    }

    if (!$courseId) {
        respond(404, ['error' => "Cours '{$courseParam}' introuvable ou non autorisé."]);
    }

    // 3. Détail individuel d'un étudiant si student=...
    if (!empty($_GET['student'])) {
        $studentId = (int) $_GET['student'];

        // Informations de l'étudiant
        $stmtStudent = $pdo->prepare("
            SELECT u.id, CONCAT(u.firstname, ' ', u.lastname) AS name, u.email
            FROM `{$userTable}` u
            WHERE u.id = :studentId AND u.deleted = 0
        ");
        $stmtStudent->execute([':studentId' => $studentId]);
        $studentInfo = $stmtStudent->fetch();

        if (!$studentInfo) {
            respond(404, ['error' => "Étudiant introuvable."]);
        }

        // Récupérer tous les quiz du cours avec la tentative et la note de cet étudiant
        $stmtAttempts = $pdo->prepare("
            SELECT 
                q.id AS quiz_id,
                q.name AS quiz_name,
                COALESCE(NULLIF(q.grade, 0), 20.0) AS max_grade,
                qg.grade AS user_grade,
                qg.timemodified AS grade_time,
                qa.timestart,
                qa.timefinish,
                qa.state
            FROM `{$quizTable}` q
            LEFT JOIN `{$gradesTable}` qg ON qg.quiz = q.id AND qg.userid = :userId
            LEFT JOIN (
                SELECT qa1.quiz, qa1.userid, qa1.timestart, qa1.timefinish, qa1.state
                FROM `{$attemptsTable}` qa1
                WHERE qa1.userid = :userId2
            ) qa ON qa.quiz = q.id
            WHERE q.course = :courseId
            ORDER BY q.id ASC
        ");
        $stmtAttempts->execute([
            ':userId'   => $studentId,
            ':userId2'  => $studentId,
            ':courseId' => $courseId,
        ]);
        $attemptsRows = $stmtAttempts->fetchAll();

        $completedCount = 0;
        $totalQuizzes = count($attemptsRows);
        $sumGrades20 = 0.0;
        $passedCount = 0;
        $attemptsList = [];

        foreach ($attemptsRows as $att) {
            $hasAttempt = ($att['user_grade'] !== null);
            $grade20 = null;
            $gradeDisplay = '—';
            $dateDisplay = '—';
            $result = 'Non réalisé';

            if ($hasAttempt) {
                $completedCount++;
                $maxG = (float) $att['max_grade'];
                $rawG = (float) $att['user_grade'];
                $grade20 = round(($rawG * 20.0) / $maxG, 1);
                $sumGrades20 += $grade20;

                $gradeDisplay = number_format($grade20, 1, '.', '') . '/20';
                $isPass = ($grade20 >= 10.0);
                if ($isPass) $passedCount++;
                $result = $isPass ? 'Succès' : 'Échec';

                $time = $att['timefinish'] ?: $att['grade_time'];
                if ($time) {
                    $dateDisplay = date('d/m/Y H:i', (int) $time);
                }
            }

            $attemptsList[] = [
                'quizId'       => (int) $att['quiz_id'],
                'quizName'     => $att['quiz_name'],
                'maxGrade'     => (float) $att['max_grade'],
                'userGrade'    => $hasAttempt ? (float) $att['user_grade'] : null,
                'grade20'      => $grade20,
                'gradeDisplay' => $gradeDisplay,
                'date'         => $dateDisplay,
                'result'       => $result,
                'state'        => $hasAttempt ? ($att['state'] ?: 'finished') : 'not_attempted',
            ];
        }

        $avg20 = ($completedCount > 0) ? round($sumGrades20 / $completedCount, 1) : null;
        $successRate = ($completedCount > 0) ? round(($passedCount / $completedCount) * 100) : null;

        $status = 'non_participant';
        $statusLabel = '🔴 Non participant';
        if ($completedCount > 0) {
            if ($avg20 >= 12.0) {
                $status = 'active';
                $statusLabel = '🟢 Actif';
            } else {
                $status = 'warning';
                $statusLabel = '🟠 À surveiller';
            }
        }

        respond(200, [
            'student' => [
                'id'                 => (int) $studentInfo['id'],
                'name'               => $studentInfo['name'],
                'email'              => $studentInfo['email'],
                'course'             => $currentCourse['fullname'],
                'courseShortname'    => $currentCourse['shortname'],
                'quizzesCompleted'   => $completedCount,
                'totalQuizzes'       => $totalQuizzes,
                'quizzesRatio'       => "{$completedCount}/{$totalQuizzes}",
                'averageGrade'       => $avg20,
                'averageGradeDisplay'=> ($avg20 !== null) ? number_format($avg20, 1, '.', '') . '/20' : '—',
                'successRate'        => $successRate,
                'successRateDisplay' => ($successRate !== null) ? "{$successRate}%" : '—',
                'status'             => $status,
                'statusLabel'        => $statusLabel,
            ],
            'attempts' => $attemptsList,
        ]);
    }

    // 4. Synthèse globale pour le cours sélectionné

    // A. Total étudiants inscrits au cours
    $stmtEnrolled = $pdo->prepare("
        SELECT COUNT(DISTINCT ue.userid)
        FROM `{$userEnrolTable}` ue
        JOIN `{$enrolTable}` e ON e.id = ue.enrolid
        JOIN `{$userTable}` u ON u.id = ue.userid AND u.deleted = 0
        WHERE e.courseid = :courseId AND ue.status = 0
    ");
    $stmtEnrolled->execute([':courseId' => $courseId]);
    $totalStudents = (int) $stmtEnrolled->fetchColumn();

    // B. Total des quiz du cours
    $stmtQuizzesCount = $pdo->prepare("SELECT COUNT(*) FROM `{$quizTable}` WHERE course = :courseId");
    $stmtQuizzesCount->execute([':courseId' => $courseId]);
    $totalQuizzes = (int) $stmtQuizzesCount->fetchColumn();

    // C. Tableau des étudiants (LEFT JOIN pour inclure les non-participants)
    // Point clé demandé : les étudiants inscrits sans aucune tentative doivent apparaître avec 0/N, moyenne "—" et statut Non participant.
    $stmtStudentsList = $pdo->prepare("
        SELECT 
            u.id AS student_id,
            CONCAT(u.firstname, ' ', u.lastname) AS name,
            u.email,
            COUNT(DISTINCT qg.quiz) AS quizzes_completed,
            AVG(CASE WHEN qg.grade IS NOT NULL THEN (qg.grade * 20.0 / NULLIF(q.grade, 0)) END) AS avg_grade_20,
            SUM(CASE WHEN qg.grade IS NOT NULL AND (qg.grade * 20.0 / NULLIF(q.grade, 0)) >= 10.0 THEN 1 ELSE 0 END) AS passed_count
        FROM `{$userEnrolTable}` ue
        JOIN `{$enrolTable}` e ON e.id = ue.enrolid AND e.courseid = :courseId AND ue.status = 0
        JOIN `{$userTable}` u ON u.id = ue.userid AND u.deleted = 0
        LEFT JOIN `{$quizTable}` q ON q.course = :courseId2
        LEFT JOIN `{$gradesTable}` qg ON qg.quiz = q.id AND qg.userid = u.id
        GROUP BY u.id, u.firstname, u.lastname, u.email
        ORDER BY quizzes_completed DESC, avg_grade_20 DESC, name ASC
    ");
    $stmtStudentsList->execute([
        ':courseId'  => $courseId,
        ':courseId2' => $courseId,
    ]);
    $studentsRaw = $stmtStudentsList->fetchAll();

    $studentsList = [];
    $allGradesSum = 0.0;
    $allGradesCount = 0;
    $allPassedCount = 0;

    foreach ($studentsRaw as $row) {
        $completed = (int) $row['quizzes_completed'];
        $hasAttempts = ($completed > 0 && $row['avg_grade_20'] !== null);

        if ($hasAttempts) {
            $avg20 = round((float) $row['avg_grade_20'], 1);
            $passed = (int) $row['passed_count'];
            $succRate = round(($passed / $completed) * 100);

            $allGradesSum += $avg20;
            $allGradesCount++;
            if ($avg20 >= 10.0) {
                $allPassedCount++;
            }

            if ($avg20 >= 12.0) {
                $status = 'active';
                $statusLabel = '🟢 Actif';
            } else {
                $status = 'warning';
                $statusLabel = '🟠 À surveiller';
            }

            $studentsList[] = [
                'id'                  => (int) $row['student_id'],
                'name'                => $row['name'],
                'email'               => $row['email'],
                'quizzesCompleted'    => $completed,
                'totalQuizzes'        => $totalQuizzes,
                'quizzesRatio'        => "{$completed}/{$totalQuizzes}",
                'averageGrade'        => $avg20,
                'averageGradeDisplay' => number_format($avg20, 1, '.', '') . '/20',
                'successRate'         => $succRate,
                'successRateDisplay'  => "{$succRate}%",
                'status'              => $status,
                'statusLabel'         => $statusLabel,
                'isNonParticipant'    => false,
            ];
        } else {
            // Étudiant non-participant : aucun quiz réalisé
            $studentsList[] = [
                'id'                  => (int) $row['student_id'],
                'name'                => $row['name'],
                'email'               => $row['email'],
                'quizzesCompleted'    => 0,
                'totalQuizzes'        => $totalQuizzes,
                'quizzesRatio'        => "0/{$totalQuizzes}",
                'averageGrade'        => null,
                'averageGradeDisplay' => '—',
                'successRate'         => null,
                'successRateDisplay'  => '—',
                'status'              => 'non_participant',
                'statusLabel'         => '🔴 Non participant',
                'isNonParticipant'    => true,
            ];
        }
    }

    // Calcul des métriques globales du cours
    $overallAverage = ($allGradesCount > 0) ? round($allGradesSum / $allGradesCount, 1) : null;
    $overallSuccessRate = ($allGradesCount > 0) ? round(($allPassedCount / $allGradesCount) * 100) : 0;

    // D. Performance par quiz
    $stmtQuizzesList = $pdo->prepare("
        SELECT 
            q.id,
            q.name,
            COALESCE(NULLIF(q.grade, 0), 20.0) AS max_grade,
            COUNT(DISTINCT qg.userid) AS participants,
            AVG(CASE WHEN qg.grade IS NOT NULL THEN (qg.grade * 20.0 / NULLIF(q.grade, 0)) END) AS avg_grade_20,
            SUM(CASE WHEN qg.grade IS NOT NULL AND (qg.grade * 20.0 / NULLIF(q.grade, 0)) >= 10.0 THEN 1 ELSE 0 END) AS passed_count
        FROM `{$quizTable}` q
        LEFT JOIN `{$gradesTable}` qg ON qg.quiz = q.id
        WHERE q.course = :courseId
        GROUP BY q.id, q.name, q.grade
        ORDER BY q.id ASC
    ");
    $stmtQuizzesList->execute([':courseId' => $courseId]);
    $quizzesRaw = $stmtQuizzesList->fetchAll();

    $quizzesList = [];
    $chartLabels = [];
    $chartValues = [];

    foreach ($quizzesRaw as $q) {
        $participants = (int) $q['participants'];
        $avg20 = ($participants > 0 && $q['avg_grade_20'] !== null) ? round((float) $q['avg_grade_20'], 1) : null;
        $passed = (int) $q['passed_count'];
        $successRate = ($participants > 0) ? round(($passed / $participants) * 100) : 0;

        $quizzesList[] = [
            'id'                 => (int) $q['id'],
            'name'               => $q['name'],
            'maxGrade'           => (float) $q['max_grade'],
            'participants'       => $participants,
            'participantsTotal'  => $totalStudents,
            'participantsRatio'  => "{$participants}/{$totalStudents}",
            'average'            => $avg20,
            'averageDisplay'     => ($avg20 !== null) ? number_format($avg20, 1, '.', '') . '/20' : '—',
            'successRate'        => $successRate,
            'successRateDisplay' => ($participants > 0) ? "{$successRate}%" : '—',
            'chartValue'         => ($avg20 !== null) ? $avg20 : 0.0,
        ];

        $chartLabels[] = $q['name'];
        $chartValues[] = ($avg20 !== null) ? $avg20 : 0.0;
    }

    respond(200, [
        'course' => [
            'id'        => $currentCourse['id'],
            'shortname' => $currentCourse['shortname'],
            'fullname'  => $currentCourse['fullname'],
        ],
        'courses' => $courses,
        'summary' => [
            'totalStudents'       => $totalStudents,
            'totalQuizzes'        => $totalQuizzes,
            'overallAverage'      => $overallAverage,
            'overallAverageDisplay' => ($overallAverage !== null) ? number_format($overallAverage, 1, '.', '') . '/20' : '—',
            'successRate'         => $overallSuccessRate,
            'successRateDisplay'  => "{$overallSuccessRate}%",
        ],
        'students' => $studentsList,
        'quizzes'  => $quizzesList,
        'chart'    => [
            'labels' => $chartLabels,
            'values' => $chartValues,
        ],
    ]);

} catch (Throwable $e) {
    error_log('[MoodQ quiz-performance.php] ' . $e->getMessage());
    respond(500, ['error' => 'Une erreur interne est survenue : ' . $e->getMessage()]);
}
