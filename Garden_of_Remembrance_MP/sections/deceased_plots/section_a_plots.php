<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Deceased Info - Section A</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
	<style>
		body { background: #fff; font-family: 'Inter', 'Segoe UI', Arial, sans-serif; }

		/* Mobile Responsive Styles */
		@media (max-width: 768px) {
			body {
				font-size: 14px;
			}
			
			div[style*="max-width:700px"] {
				max-width: 95% !important;
				margin: 1rem auto 0 auto;
				padding: 0 1rem;
			}
			
			h4 {
				font-size: 1.25rem;
				margin-bottom: 1rem;
			}
			
			.card {
				border-width: 1.5px;
				border-radius: 0.75rem;
			}
			
			h5 {
				font-size: 1.1rem;
			}
			
			div[style*="display:grid"] {
				gap: 8px;
				grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
			}
			
			div[style*="border:1.5px solid"] {
				border-width: 1px !important;
				border-radius: 0.5rem;
				padding: 8px;
				min-height: 60px;
				font-size: 0.8rem;
			}
			
			b {
				font-size: 0.9rem;
			}
			
			span {
				font-size: 0.8rem;
			}
			
			.btn {
				font-size: 0.9rem;
				padding: 0.5rem 1rem;
				margin-top: 1rem;
			}
		}
		
		@media (max-width: 480px) {
			div[style*="max-width:700px"] {
				max-width: 98% !important;
				margin: 0.5rem auto 0 auto;
				padding: 0 0.5rem;
			}
			
			h4 {
				font-size: 1.1rem;
			}
			
			.card {
				border-radius: 0.5rem;
			}
			
			h5 {
				font-size: 1rem;
			}
			
			div[style*="display:grid"] {
				gap: 6px;
				grid-template-columns: repeat(auto-fit, minmax(70px, 1fr));
			}
			
			div[style*="border:1.5px solid"] {
				border-radius: 0.4rem;
				padding: 6px;
				min-height: 50px;
				font-size: 0.7rem;
			}
			
			b {
				font-size: 0.8rem;
			}
			
			span {
				font-size: 0.7rem;
			}
			
			.btn {
				font-size: 0.8rem;
				padding: 0.4rem 0.8rem;
			}
		}
	</style>
</head>
<body>
	<div style="max-width:700px;margin:2rem auto 0 auto;">
		<?php
		include '../../connection.php';
		$section = 'A';
		$max_x = 5; $max_y = 5;
		$plot_x = isset($_GET['plot_x']) ? intval($_GET['plot_x']) : null;
		$plot_y = isset($_GET['plot_y']) ? intval($_GET['plot_y']) : null;
		if ($plot_x && $plot_y) {
			// Show info for a specific plot
			$stmt = $conn->prepare("SELECT * FROM tbl_deceased WHERE section = ? AND plot_x = ? AND plot_y = ? LIMIT 1");
			$stmt->bind_param("sii", $section, $plot_x, $plot_y);
			$stmt->execute();
			$result = $stmt->get_result();
			echo '<h4 class="mb-3">Section A - Plot X: ' . $plot_x . ' | Y: ' . $plot_y . '</h4>';
			if ($row = $result->fetch_assoc()) {
				echo '<div class="card p-3 mb-3" style="border:1.5px solid #a03a3c;">';
				echo '<h5 style="color:#a03a3c;">' . htmlspecialchars($row['full_name']) . '</h5>';
				echo '<div><b>Birthday:</b> ' . htmlspecialchars($row['birth_date'] ?? $row['bday'] ?? '') . '</div>';
				echo '<div><b>Date Died:</b> ' . htmlspecialchars($row['death_date'] ?? $row['date_died'] ?? '') . '</div>';
				echo '<div><b>Remarks:</b> ' . htmlspecialchars($row['remarks'] ?? '') . '</div>';
				echo '</div>';
			} else {
				echo '<div class="alert alert-secondary">No deceased info for this plot.</div>';
			}
			echo '<a href="section_a_plots.php" class="btn btn-outline-secondary">Back to Grid</a>';
		} else {
			// Show grid as before
			echo '<h4 class="mb-3">Section A Plot Map</h4>';
			$deceased = [];
			$sql = "SELECT * FROM tbl_deceased WHERE section = '$section'";
			$result = $conn->query($sql);
			if ($result) {
				while ($row = $result->fetch_assoc()) {
					$deceased[$row['plot_y']][$row['plot_x']] = $row;
				}
			}
			echo '<div style="display:grid;grid-template-columns:repeat(' . $max_x . ', 1fr);gap:12px;">';
			for ($y = 1; $y <= $max_y; $y++) {
				for ($x = 1; $x <= $max_x; $x++) {
					echo '<div style="border:1.5px solid #a03a3c;border-radius:8px;padding:12px;min-height:70px;display:flex;align-items:center;justify-content:center;background:#f8f9fa;">';
					if (isset($deceased[$y][$x])) {
						$d = $deceased[$y][$x];
						echo '<b style="color:#a03a3c;">' . htmlspecialchars($d['full_name']) . '</b><br>';
						echo '<span style="font-size:0.9em;color:#555;">Plot X: ' . $x . ' | Y: ' . $y . '</span>';
					} else {
						echo '<span style="color:#bbb;">Empty<br>Plot X: ' . $x . ' | Y: ' . $y . '</span>';
					}
					echo '</div>';
				}
			}
			echo '</div>';
		}
		?>
	</div>
</body>
</html>
