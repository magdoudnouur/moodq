<?php
/**
 * seed-quiz-data.php
 * -----------------------------------------------------------
 * Remplit les tables réelles de quiz Moodle (mdl_quiz, mdl_quiz_attempts,
 * mdl_quiz_grades, mdl_course_modules) avec des quiz et tentatives réalistes
 * si la table mdl_quiz est vide.
 *
 * Idempotent : ne fait rien si des quiz existent déjà.
 * -----------------------------------------------------------
 */
require_once __DIR__ . '/config.php';

function seed_moodle_quiz_data(PDO $pdo): array
{
    global $CFG;
    $prefix = defined('DB_PREFIX') ? DB_PREFIX : ($CFG->prefix ?? 'mdl_');

    $quizTable = $prefix . 'quiz';
    $attemptsTable = $prefix . 'quiz_attempts';
    $gradesTable = $prefix . 'quiz_grades';
    $cmTable = $prefix . 'course_modules';
    $modulesTable = $prefix . 'modules';

    $existingCount = (int) $pdo->query("SELECT COUNT(*) FROM `{$quizTable}`")->fetchColumn();
    if ($existingCount > 0) {
        return ['status' => 'already_seeded', 'count' => $existingCount];
    }

    $quizModuleId = (int) $pdo->query("SELECT id FROM `{$modulesTable}` WHERE name = 'quiz'")->fetchColumn();
    if (!$quizModuleId) {
        $quizModuleId = 17; // standard Moodle ID for quiz module
    }

    $now = time();
    $day = 86400;

    // Définition des quiz par cours
    // CS101 (id 10) : 4 quiz
    // MA101 (id 11) : 3 quiz
    // DA201 (id 12) : 3 quiz
    $quizzesConfig = [
        10 => [
            [
                'name' => 'Quiz 1 — Variables & Types de données',
                'intro' => 'Évaluation formative sur les bases du langage.',
                'grade' => 20.0,
                'timecreated' => $now - 30 * $day,
                'results' => [
                    23 => 17.5, // Ahmed Ziha
                    24 => 18.0, // Sofia Belkacem
                    25 => 14.0, // Youssef Mansouri
                    26 => 15.5, // Maya El Amrani
                    28 => 9.0,  // Karim Gagnon
                    // 27 (Lina Haddad) n'a PAS fait ce quiz -> Non participante
                ]
            ],
            [
                'name' => 'Quiz 2 — Structures de contrôle (If, While, For)',
                'intro' => 'Test sur les boucles et conditions logiques.',
                'grade' => 20.0,
                'timecreated' => $now - 20 * $day,
                'results' => [
                    23 => 16.0,
                    24 => 17.0,
                    25 => 13.5,
                    26 => 14.0,
                    28 => 8.5,
                    // 27 (Lina Haddad) n'a PAS fait ce quiz
                ]
            ],
            [
                'name' => 'Quiz 3 — Fonctions & Portée des variables',
                'intro' => 'Décomposition modulaire et passage de paramètres.',
                'grade' => 20.0,
                'timecreated' => $now - 10 * $day,
                'results' => [
                    23 => 18.5,
                    24 => 16.5,
                    25 => 15.0,
                    26 => 12.0,
                    // 28 n'a pas fait ce quiz
                    // 27 (Lina Haddad) n'a PAS fait ce quiz
                ]
            ],
            [
                'name' => 'Quiz 4 — Algorithmes & Tableaux',
                'intro' => 'Évaluation récapitulative sur les structures de données.',
                'grade' => 20.0,
                'timecreated' => $now - 3 * $day,
                'results' => [
                    23 => 19.0,
                    24 => 17.5,
                    25 => 16.0,
                    // 26, 28, 27 n'ont pas fait ce quiz
                ]
            ],
        ],
        11 => [
            [
                'name' => 'Quiz 1 — Limites et Continuité',
                'intro' => 'Notions de base du calcul différentiel.',
                'grade' => 20.0,
                'timecreated' => $now - 25 * $day,
                'results' => [
                    23 => 15.0, // Ahmed
                    24 => 16.5, // Sofia
                    29 => 14.0, // Nora
                    30 => 11.5, // Thomas
                    // 31 (Ines Lavoie) : Non-participante
                ]
            ],
            [
                'name' => 'Quiz 2 — Dérivées et Applications',
                'intro' => 'Calculs de dérivées et optimisation.',
                'grade' => 20.0,
                'timecreated' => $now - 15 * $day,
                'results' => [
                    23 => 16.0,
                    24 => 17.0,
                    29 => 13.5,
                    30 => 9.0,
                    // 31 (Ines Lavoie) : Non-participante
                ]
            ],
            [
                'name' => 'Quiz 3 — Intégrales définies',
                'intro' => 'Primitives et intégration numérique.',
                'grade' => 20.0,
                'timecreated' => $now - 5 * $day,
                'results' => [
                    23 => 14.5,
                    24 => 18.0,
                    29 => 15.0,
                    // 30 et 31 non faits
                ]
            ],
        ],
        12 => [
            [
                'name' => 'Quiz 1 — NumPy & Manipulation de tableaux',
                'intro' => 'Calcul vectoriel et matrices en Python.',
                'grade' => 20.0,
                'timecreated' => $now - 22 * $day,
                'results' => [
                    23 => 18.0,
                    25 => 15.5,
                    27 => 12.0,
                    29 => 14.0,
                    32 => 13.0,
                    // 33 & 35 : Non-participants
                ]
            ],
            [
                'name' => 'Quiz 2 — Pandas & Séries temporelles',
                'intro' => 'DataFrames, agrégations et filtrage de données.',
                'grade' => 20.0,
                'timecreated' => $now - 12 * $day,
                'results' => [
                    23 => 17.0,
                    25 => 16.0,
                    27 => 11.0,
                    29 => 15.0,
                    32 => 14.5,
                    // 33 & 35 : Non-participants
                ]
            ],
            [
                'name' => 'Quiz 3 — Visualisation avec Matplotlib & Seaborn',
                'intro' => 'Création de visualisations statistiques pertinentes.',
                'grade' => 20.0,
                'timecreated' => $now - 4 * $day,
                'results' => [
                    23 => 19.0,
                    25 => 17.0,
                    27 => 13.5,
                    29 => 16.0,
                    // 32, 33, 35 non faits
                ]
            ],
        ],
    ];

    $insertQuizStmt = $pdo->prepare("
        INSERT INTO `{$quizTable}`
            (course, name, intro, introformat, timeopen, timeclose, timelimit, overduehandling, graceperiod,
             preferredbehaviour, canredoquestions, attempts, attemptonlast, grademethod, decimalpoints,
             questiondecimalpoints, reviewattempt, reviewcorrectness, reviewmarks, questionsperpage,
             navmethod, shuffleanswers, sumgrades, grade, timecreated, timemodified)
        VALUES
            (:course, :name, :intro, 1, :timeopen, :timeclose, 1800, 'autosubmit', 0,
             'deferredfeedback', 0, 0, 0, 1, 2,
             2, 69904, 4368, 4368, 1,
             'free', 1, 20.0, :grade, :timecreated, :timemodified)
    ");

    $insertCmStmt = $pdo->prepare("
        INSERT INTO `{$cmTable}`
            (course, module, instance, section, idnumber, added, score, indent, visible, visibleold, groupmode, groupingid, completion)
        VALUES
            (:course, :module, :instance, 0, '', :added, 0, 0, 1, 1, 0, 0, 1)
    ");

    $insertAttemptStmt = $pdo->prepare("
        INSERT INTO `{$attemptsTable}`
            (quiz, userid, attempt, uniqueid, layout, currentpage, preview, state, timestart, timefinish, timemodified, sumgrades)
        VALUES
            (:quiz, :userid, 1, :uniqueid, '1,0', 0, 0, 'finished', :timestart, :timefinish, :timemodified, :sumgrades)
    ");

    $insertGradeStmt = $pdo->prepare("
        INSERT INTO `{$gradesTable}`
            (quiz, userid, grade, timemodified)
        VALUES
            (:quiz, :userid, :grade, :timemodified)
    ");

    $totalQuizzes = 0;
    $totalAttempts = 0;
    $attemptCounter = 1000;

    $pdo->beginTransaction();
    try {
        foreach ($quizzesConfig as $courseId => $quizzes) {
            foreach ($quizzes as $q) {
                $insertQuizStmt->execute([
                    ':course' => $courseId,
                    ':name' => $q['name'],
                    ':intro' => $q['intro'],
                    ':timeopen' => $q['timecreated'],
                    ':timeclose' => $q['timecreated'] + 14 * $day,
                    ':grade' => $q['grade'],
                    ':timecreated' => $q['timecreated'],
                    ':timemodified' => $q['timecreated'],
                ]);
                $quizId = (int) $pdo->lastInsertId();
                $totalQuizzes++;

                // Lier le module de cours Moodle
                $insertCmStmt->execute([
                    ':course' => $courseId,
                    ':module' => $quizModuleId,
                    ':instance' => $quizId,
                    ':added' => $q['timecreated'],
                ]);

                // Ajouter les tentatives et les notes
                foreach ($q['results'] as $userId => $grade) {
                    $attemptCounter++;
                    $timeStart = $q['timecreated'] + rand(3600, 86400);
                    $timeFinish = $timeStart + rand(600, 1500);

                    $insertAttemptStmt->execute([
                        ':quiz' => $quizId,
                        ':userid' => $userId,
                        ':uniqueid' => $attemptCounter,
                        ':timestart' => $timeStart,
                        ':timefinish' => $timeFinish,
                        ':timemodified' => $timeFinish,
                        ':sumgrades' => $grade,
                    ]);

                    $insertGradeStmt->execute([
                        ':quiz' => $quizId,
                        ':userid' => $userId,
                        ':grade' => $grade,
                        ':timemodified' => $timeFinish,
                    ]);

                    $totalAttempts++;
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'status' => 'seeded',
        'quizzes' => $totalQuizzes,
        'attempts' => $totalAttempts,
    ];
}

if (php_sapi_name() === 'cli' || basename($_SERVER['PHP_SELF'] ?? '') === 'seed-quiz-data.php') {
    try {
        $pdo = moodq_pdo();
        $res = seed_moodle_quiz_data($pdo);
        echo "Result: " . json_encode($res, JSON_PRETTY_PRINT) . PHP_EOL;
    } catch (Throwable $e) {
        echo "Error: " . $e->getMessage() . PHP_EOL;
    }
}
