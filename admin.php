<?php
header('Content-Type: application/json');
ini_set('display_errors', 0); // Prevent warnings in output

include 'db.php';
include 'auth.php'; // verifyToken()

require 'vendor/autoload.php';

use Dompdf\Dompdf;
use Shuchkin\SimpleXLSX;

// AUTHENTICATION + AUTHORIZATION
// Only Admin Can Access All APIs in This File

$user = verifyToken(['admin']);   // ⬅ ONLY ADMIN CAN ACCESS

//  VALIDATE ACTION

$action = $_GET['action'] ?? null;

if (!$action) {
    echo json_encode([
        "status" => "error",
        "message" => "No action specified"
    ]);
    exit;
}

// router
switch ($action) {

//  1. UPLOAD CSV/XLSX & AUTO-PROCESS RESULTS

case "upload_excel":

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(["status" => "error", "message" => "File missing or upload error"]);
        exit;
    }

    $filePath = $_FILES['file']['tmp_name'];
    $fileName = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $rows = [];

    /* ---- CSV ---- */
    if ($ext === "csv") {
        if (($handle = fopen($filePath, "r")) !== false) {
            while (($row = fgetcsv($handle, 10000, ",")) !== false) {
                $rows[] = $row;
            }
            fclose($handle);
        }
    }

    /* ---- XLSX ---- */
    else if ($ext === "xlsx") {
        if ($xlsx = SimpleXLSX::parse($filePath)) {
            $rows = $xlsx->rows();
        } else {
            echo json_encode(["status" => "error", "message" => SimpleXLSX::parseError()]);
            exit;
        }
    }

    else {
        echo json_encode(["status" => "error", "message" => "Only CSV or XLSX allowed"]);
        exit;
    }


    /* ---- PROCESS FILE ROWS ---- */
    $processed = 0;
    $errors = 0;

    foreach ($rows as $index => $row) {

        if ($index == 0) continue; // Skip header

        $roll    = trim($row[0] ?? "");
        $subject = trim($row[1] ?? "");
        $marks   = trim($row[2] ?? "");

        if ($roll === "" || $subject === "" || $marks === "" ) {
            $errors++;
            continue;
        }

        // find student
        $stmt = $conn->prepare("SELECT id FROM student_profile WHERE roll_number = :roll LIMIT 1");
        $stmt->execute(['roll' => $roll]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            $errors++;
            continue;
        }

        $student_id = $student['id'];

        // insert subject if not exists
        $stmt = $conn->prepare("SELECT id FROM subjects WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => $subject]);
        $sub = $stmt->fetch(PDO::FETCH_ASSOC);

        $subject_id = $sub['id'] ?? null;

        if (!$subject_id) {
            $stmt = $conn->prepare("INSERT INTO subjects (name) VALUES (:name)");
            $stmt->execute(['name' => $subject]);
            $subject_id = $conn->lastInsertId();
        }

        // insert result
        $stmt = $conn->prepare("
            INSERT INTO results (exam_id, student_id, subject_id, marks)
            VALUES (1, :student_id, :subject_id, :marks)
        ");

        $stmt->execute([
            'student_id' => $student_id,
            'subject_id' => $subject_id,
            'marks'      => $marks
        ]);

        $processed++;
    }

    echo json_encode([
        "status"     => "success",
        "processed"  => $processed,
        "errors"     => $errors
    ]);
    exit;


break;

//    2. FETCH STUDENTS LIST


case "students":

    $stmt = $conn->prepare("
        SELECT 
            u.id, u.name, u.email,
            sp.roll_number, sp.class, sp.section
        FROM users u
        INNER JOIN student_profile sp ON u.id = sp.id
        WHERE u.role = 'student'
        ORDER BY u.id DESC
    ");

    $stmt->execute();
    echo json_encode([
        "status"   => "success",
        "students" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit;


break;

//    3. VIEW ANALYTICS


case "analytics":

    // total students
    $stmt = $conn->prepare("SELECT COUNT(*) AS total_students FROM users WHERE role = 'student'");
    $stmt->execute();
    $total_students = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total_students'];

    // average marks
    $stmt = $conn->prepare("SELECT AVG(marks) AS avg_marks FROM results");
    $stmt->execute();
    $average = round((float)$stmt->fetch(PDO::FETCH_ASSOC)['avg_marks'], 2);

    echo json_encode([
        "status" => "success",
        "analytics" => [
            "total_students" => $total_students,
            "average_marks"  => $average
        ]
    ]);
    exit;


break;

//    4. DOWNLOAD PDF REPORT

case "pdf":

    $student_id = $_GET['student_id'] ?? null;

    if (!$student_id || !is_numeric($student_id)) {
        echo json_encode(["status" => "error", "message" => "Valid student_id required"]);
        exit;
    }

    // fetch student
    $stmt = $conn->prepare("SELECT name FROM users WHERE id = :id AND role = 'student'");
    $stmt->execute(['id' => $student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo json_encode(["status" => "error", "message" => "Student not found"]);
        exit;
    }

    // fetch marks
    $stmt = $conn->prepare("
        SELECT subjects.name AS subject, results.marks
        FROM results
        INNER JOIN subjects ON results.subject_id = subjects.id
        WHERE student_id = :id
    ");
    $stmt->execute(['id' => $student_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // html for pdf
    $html = "<h2>Student Result Report</h2>";
    $html .= "<p><b>Name:</b> {$student['name']}</p>";
    $html .= "<table border='1' cellpadding='5'><tr><th>Subject</th><th>Marks</th></tr>";

    foreach ($results as $r) {
        $html .= "<tr><td>{$r['subject']}</td><td>{$r['marks']}</td></tr>";
    }

    $html .= "</table>";

    // generate pdf
    $pdf = new Dompdf();
    $pdf->loadHtml($html);
    $pdf->render();

    header("Content-Type: application/pdf");
    header("Content-Disposition: attachment; filename=Result_{$student['name']}.pdf");

    echo $pdf->output();
    exit;


break;

//    5. VIEW LOGS

case "logs":

    $stmt = $conn->prepare("SELECT * FROM audit_logs ORDER BY id DESC");
    $stmt->execute();

    echo json_encode([
        "status" => "success",
        "logs"   => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit;


break;

//    INVALID ACTION

default:
    echo json_encode([
        "status" => "error",
        "message" => "Invalid admin action"
    ]);
    exit;

}
?>
