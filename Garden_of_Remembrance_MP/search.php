<?php
include 'connection.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] === 'ADMIN') {
    header("Location: dashboard.php");
    exit;
}

$search_results = [];
$search_query = "";

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search'])) {
    $search_query = $conn->real_escape_string($_GET['search_query']);
    
    if (!empty($search_query)) {
        $sql = "SELECT * FROM tbl_deceased WHERE 
                first_name LIKE '%$search_query%' OR 
                last_name LIKE '%$search_query%' OR 
                section LIKE '%$search_query%' OR 
                CONCAT(first_name, ' ', last_name) LIKE '%$search_query%'
                ORDER BY last_name, first_name LIMIT 50";
        
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $search_results[] = $row;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Records - Garden of Remembrance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #7b1113;
            --secondary-color: #a03a3c;
            --accent-color: #d4a574;
            --text-dark: #333;
            --text-light: #666;
            --bg-light: #f8f9fa;
            --bg-white: #ffffff;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            min-height: 100vh;
            color: var(--text-dark);
        }

        /* Header */
        .search-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 2rem 0;
            box-shadow: 0 4px 20px rgba(123, 17, 19, 0.3);
        }

        .header-content {
            text-align: center;
        }

        .header-content h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .header-content p {
            font-size: 1.1rem;
            opacity: 0.9;
            margin: 0;
        }

        /* Navigation */
        .search-nav {
            background: var(--bg-white);
            padding: 1rem 0;
            border-bottom: 1px solid #dee2e6;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        .nav-links {
            display: flex;
            justify-content: center;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .nav-link {
            color: var(--text-dark);
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-link:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        /* Main Container */
        .search-container {
            max-width: 1000px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        /* Search Form */
        .search-form {
            background: var(--bg-white);
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }

        .search-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            text-align: center;
        }

        .search-input-group {
            display: flex;
            gap: 1rem;
            align-items: stretch;
        }

        .search-input {
            flex: 1;
            border: 2px solid #e9ecef;
            border-radius: 12px 0 0 12px;
            padding: 1rem;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(123, 17, 19, 0.25);
        }

        .search-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            border-radius: 0 12px 12px 0;
            padding: 1rem 2rem;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(123, 17, 19, 0.3);
        }

        /* Search Results */
        .results-section {
            background: var(--bg-white);
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .results-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .results-count {
            background: var(--accent-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
        }

        /* Result Cards */
        .result-grid {
            display: grid;
            gap: 1rem;
        }

        .result-card {
            background: var(--bg-light);
            border-radius: 12px;
            padding: 1.5rem;
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s ease;
        }

        .result-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .result-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .result-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .result-detail {
            display: flex;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .result-detail strong {
            color: var(--text-dark);
        }

        .result-location {
            background: var(--primary-color);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--accent-color);
            margin-bottom: 1rem;
        }

        /* Search Tips */
        .search-tips {
            background: rgba(123, 17, 19, 0.1);
            border: 2px solid var(--primary-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .tips-title {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tips-list {
            list-style: none;
            padding: 0;
        }

        .tips-list li {
            padding: 0.5rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .tips-list li i {
            color: var(--primary-color);
            width: 20px;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .header-content h1 {
                font-size: 2rem;
            }

            .search-container {
                margin: 1rem auto;
            }

            .search-form, .results-section {
                padding: 1.5rem;
            }

            .search-input-group {
                flex-direction: column;
            }

            .search-input {
                border-radius: 12px;
            }

            .search-btn {
                border-radius: 12px;
                justify-content: center;
            }

            .results-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .result-details {
                grid-template-columns: 1fr;
            }
        }

        /* Back Button */
        .back-btn {
            position: fixed;
            bottom: 2rem;
            left: 2rem;
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 1rem 1.5rem;
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
            color: white;
        }

        @media (max-width: 768px) {
            .back-btn {
                bottom: 1rem;
                left: 1rem;
                padding: 0.8rem 1.2rem;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    
    <header class="search-header">
        <div class="container">
            <div class="header-content">
                <h1>Search Records</h1>
                <p>Find deceased records in the memorial park</p>
            </div>
        </div>
    </header>

    
    <nav class="search-nav">
        <div class="nav-container">
            <div class="nav-links">
                <a href="user_dashboard.php" class="nav-link">
                    <i class="fas fa-home"></i>
                    Dashboard
                </a>
                <a href="user_dashboard.php" class="nav-link">
                    <i class="fas fa-calendar-plus"></i>
                    Reserve Service
                </a>
                <a href="search.php" class="nav-link">
                    <i class="fas fa-search"></i>
                    Search Records
                </a>
                <a href="map.php" class="nav-link">
                    <i class="fas fa-map"></i>
                    Cemetery Map
                </a>
                <a href="logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>
            </div>
        </div>
    </nav>

    
    <main class="search-container">
        
        <div class="search-form">
            <h2 class="search-title">Search Deceased Records</h2>
            
            <form method="GET" action="">
                <div class="search-input-group">
                    <input 
                        type="text" 
                        class="search-input" 
                        name="search_query" 
                        value="<?php echo htmlspecialchars($search_query); ?>"
                        placeholder="Search by name, section, or plot..."
                        required
                    >
                    <button type="submit" name="search" class="search-btn">
                        <i class="fas fa-search"></i>
                        Search
                    </button>
                </div>
            </form>
        </div>

        
        <div class="search-tips">
            <h3 class="tips-title">
                <i class="fas fa-lightbulb"></i>
                Search Tips
            </h3>
            <ul class="tips-list">
                <li><i class="fas fa-check"></i> Enter full name (e.g., "Juan Santos")</li>
                <li><i class="fas fa-check"></i> Search by section (e.g., "Section A")</li>
                <li><i class="fas fa-check"></i> Use partial names (e.g., "Juan" for all Juan)</li>
                <li><i class="fas fa-check"></i> Minimum 2 characters required</li>
            </ul>
        </div>

        
        <?php if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search'])): ?>
            <div class="results-section">
                <div class="results-header">
                    <h3 class="results-title">
                        <i class="fas fa-list"></i>
                        Search Results
                    </h3>
                    <span class="results-count"><?php echo count($search_results); ?> found</span>
                </div>

                <?php if (!empty($search_results)): ?>
                    <div class="result-grid">
                        <?php foreach ($search_results as $result): ?>
                            <div class="result-card">
                                <div class="result-name">
                                    <?php 
                                    $full_name = trim($result['first_name'] . ' ' . ($result['middle_name'] ?? '') . ' ' . $result['last_name']);
                                    echo htmlspecialchars($full_name);
                                    ?>
                                </div>
                                
                                <div class="result-details">
                                    <div class="result-detail">
                                        <strong>Born:</strong> <?php echo htmlspecialchars($result['bday'] ?? 'N/A'); ?>
                                    </div>
                                    <div class="result-detail">
                                        <strong>Died:</strong> <?php echo htmlspecialchars($result['date_died'] ?? 'N/A'); ?>
                                    </div>
                                    <div class="result-detail">
                                        <strong>Born in:</strong> <?php echo htmlspecialchars($result['born_in'] ?? 'N/A'); ?>
                                    </div>
                                    <?php if (!empty($result['contact_no'])): ?>
                                        <div class="result-detail">
                                            <strong>Contact:</strong> <?php echo htmlspecialchars($result['contact_no']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                                    <span class="result-location">
                                        <?php echo htmlspecialchars($result['section'] ?? 'N/A'); ?> - 
                                        <?php echo htmlspecialchars($result['plot_x'] ?? '?'); ?>-<?php echo htmlspecialchars($result['plot_y'] ?? '?'); ?>
                                    </span>
                                    
                                    <?php if (!empty($result['funeral_service'])): ?>
                                        <span style="font-size: 0.9rem; color: var(--text-light);">
                                            <?php echo htmlspecialchars($result['funeral_service']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-search"></i>
                        <h3>No Results Found</h3>
                        <p>Try searching with different keywords or check the spelling.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>

    
    <a href="user_dashboard.php" class="back-btn">
        <i class="fas fa-arrow-left"></i>
        Back to Dashboard
    </a>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>