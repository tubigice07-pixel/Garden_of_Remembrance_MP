<?php
session_start();
include 'connection.php';

if (!isset($_SESSION['email']) || strtoupper(trim((string)($_SESSION['role'] ?? ''))) !== 'ADMIN') {
    header("Location: admin_login.php");
    exit();
}

$section = isset($_GET['section']) ? $_GET['section'] : '';

$sql = "SELECT * FROM tbl_deceased WHERE section = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $section);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Deceased in Section <?= htmlspecialchars($section) ?></title>
    <style>
        body { font-family: Arial; background: #f5f5f5; padding: 20px; }
        h2 { color: #333; }
        table { width: 100%; border-collapse: collapse; background: white; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        th, td { padding: 12px; border: 1px solid #ccc; text-align: left; }
        th { background-color: #4CAF50; color: white; }
        tr:hover { background-color: #f1f1f1; }
        .actions a {
            padding: 5px 10px;
            margin-right: 5px;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        .edit { background: #2196F3; }
        .delete { background: #f44336; }

        /* Mobile Responsive Styles */
        @media (max-width: 768px) {
            body {
                padding: 10px;
                font-size: 14px;
            }
            
            h2 {
                font-size: 1.25rem;
                margin-bottom: 1rem;
            }
            
            table {
                font-size: 0.75rem;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            
            th, td {
                padding: 8px 6px;
                font-size: 0.75rem;
            }
            
            th {
                font-size: 0.8rem;
            }
            
            .actions a {
                padding: 4px 8px;
                margin-right: 3px;
                font-size: 0.7rem;
                display: inline-block;
                margin-bottom: 2px;
            }
        }
        
        @media (max-width: 480px) {
            body {
                padding: 5px;
                font-size: 13px;
            }
            
            h2 {
                font-size: 1.1rem;
            }
            
            table {
                font-size: 0.7rem;
            }
            
            th, td {
                padding: 6px 4px;
                font-size: 0.7rem;
            }
            
            th {
                font-size: 0.75rem;
            }
            
            .actions a {
                padding: 3px 6px;
                font-size: 0.65rem;
                margin-right: 2px;
            }
            
            .actions {
                white-space: nowrap;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
        }
    </style>
</head>
<body>

<h2>Burials in Section <?= htmlspecialchars($section) ?></h2>

<table>
    <tr>
        <th>Full Name</th>
        <th>Plot X</th>
        <th>Plot Y</th>
        <th>Actions</th>
    </tr>

    <?php while ($row = $result->fetch_assoc()) : ?>
    <tr>
        <td><?= strtoupper($row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name']) ?></td>
        <td><?= $row['plot_x'] ?></td>
        <td><?= $row['plot_y'] ?></td>
        <td class="actions">
            <a href="sections/popup.php?section=<?= urlencode($section) ?>&plot_x=<?= urlencode($row['plot_x']) ?>&plot_y=<?= urlencode($row['plot_y']) ?>" class="edit">View / Manage</a>
        </td>
    </tr>
    <?php endwhile; ?>
</table>

</body>
</html>
