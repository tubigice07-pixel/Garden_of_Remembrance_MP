<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

@include_once __DIR__ . '/connection.php';

$isLoggedIn = false;
$isAdmin = false;
$isUser = false;
$userName = 'Visitor';
$showPublicAuthNav = true;

if (!empty($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    try {
        if (function_exists('is_db_connected') && is_db_connected()) {
            $chk_stmt = $conn->prepare("SELECT user_id, role, first_name, last_name FROM tbl_user WHERE user_id = ? LIMIT 1");
            if ($chk_stmt) {
                $chk_stmt->bind_param('i', $uid);
                $chk_stmt->execute();
                $chk_res = $chk_stmt->get_result();
                if ($chk_row = $chk_res->fetch_assoc()) {
                    $role = strtoupper(trim((string)($chk_row['role'] ?? $_SESSION['role'] ?? 'USER')));
                    // Tab-aware routing: redirection based on per-tab sessionStorage is handled client-side in <head>
                    if ($role === 'ADMIN') {
                        $isLoggedIn = true;
                        $isAdmin = true;
                        $userName = trim(($chk_row['first_name'] ?? '') . ' ' . ($chk_row['last_name'] ?? '')) ?: 'Administrator';
                    } else {
                        $isLoggedIn = true;
                        $isUser = true;
                        $userName = trim(($chk_row['first_name'] ?? '') . ' ' . ($chk_row['last_name'] ?? '')) ?: 'Customer';
                    }
                } else {

                    $_SESSION = [];
                    if (ini_get("session.use_cookies")) {
                        $p = session_get_cookie_params();
                        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain']);
                    }
                }
                $chk_stmt->close();
            }
        }
    } catch (Throwable $e) {
        error_log('Index user session check error: ' . $e->getMessage());
    }
}

$stats = [
    'total_plots' => 1200,
    'available_plots' => 450,
    'occupied_plots' => 680,
    'sections_count' => 10
];

if (function_exists('is_db_connected') && is_db_connected()) {
    try {
        $statsRes = safe_db_query("SELECT COUNT(*) AS total, 
            SUM(CASE WHEN status = 'Available' OR status = 'vacant' THEN 1 ELSE 0 END) AS available,
            SUM(CASE WHEN status = 'Occupied' THEN 1 ELSE 0 END) AS occupied 
            FROM tbl_plots");
        if ($statsRes && $row = $statsRes->fetch_assoc()) {
            if (!empty($row['total'])) {
                $stats['total_plots'] = (int)$row['total'];
                $stats['available_plots'] = (int)($row['available'] ?? 0);
                $stats['occupied_plots'] = (int)($row['occupied'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        error_log('Index stats query error: ' . $e->getMessage());
    }
}

$sections = [
    ['code' => 'A', 'title' => 'Section A', 'type' => 'Standard Lawn', 'desc' => 'Near entrance walkway, peaceful tree-lined avenue.'],
    ['code' => 'B', 'title' => 'Section B', 'type' => 'Memorial Gardens', 'desc' => 'Central garden pathway, landscaped surroundings.'],
    ['code' => 'C', 'title' => 'Section C', 'type' => 'Quiet Meadow', 'desc' => 'East lawn sanctuary, scenic open memorial spaces.'],
    ['code' => 'D', 'title' => 'Section D', 'type' => 'Heritage Lawn', 'desc' => 'Established memorial field, shaded pathways.'],
    ['code' => 'E', 'title' => 'Section E', 'type' => 'Corner & Prime Lawn', 'desc' => 'Wide walkways with prominent corner plot accessibility.'],
    ['code' => 'F', 'title' => 'Section F', 'type' => 'Serenity Gardens', 'desc' => 'Quiet northern sector, tranquil reflection areas.'],
    ['code' => 'G', 'title' => 'Section G', 'type' => 'Upper Terrace', 'desc' => 'Gentle slope overlooking the memorial park grounds.'],
    ['code' => 'H', 'title' => 'Section H', 'type' => 'Courtyard Lawn', 'desc' => 'Courtyard setting adjacent to main cemetery walkway.'],
    ['code' => 'I', 'title' => 'Section I', 'type' => 'Vista Grove', 'desc' => 'Well-lit upper grounds with spacious plot avenues.'],
    ['code' => 'J', 'title' => 'Section J', 'type' => 'Memorial Pines', 'desc' => 'North-eastern boundary, quiet natural atmosphere.']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
    (function () {
        try {
            // Anchor this tab strictly to index/guest view so opening a new tab
            // or visiting the site root never bounces the user back to user_dashboard.
            if (window.sessionStorage) {
                sessionStorage.setItem('gor_tab_home', 'index');
            }
        } catch (e) {}
    })();
    </script>
    <title>Garden of Remembrance Memorial Park</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #7b1113;
            --primary-dark: #580a0c;
            --primary-light: #9a1c1f;
            --primary-rgb: 123, 17, 19;
            --gold: #c59b27;
            --gold-light: #fef9ec;
            --gold-dark: #967417;
            --dark: #141110;
            --light-bg: #faf7f4;
            --card-bg: rgba(255, 255, 255, 0.98);
            --border-subtle: rgba(123, 17, 19, 0.1);
        }

        body {
            margin: 0;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--light-bg);
            color: #2b2523;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        /* Navbar */
        .site-navbar {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(123, 17, 19, 0.08);
            position: sticky;
            top: 0;
            z-index: 1040;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            transition: all 0.3s ease;
        }

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 800;
            color: var(--primary) !important;
            font-size: 1.18rem;
            letter-spacing: -0.01em;
        }

        .navbar-brand img {
            height: 42px;
            width: auto;
            transition: transform 0.2s ease;
        }

        .navbar-brand:hover img {
            transform: scale(1.04);
        }

        .site-nav-list {
            position: relative;
        }

        .nav-link {
            color: #4a423f !important;
            font-weight: 500;
            font-size: 0.94rem;
            padding: 0.5rem 0.95rem !important;
            border-radius: 8px;
            transition: all 0.2s ease;
            position: relative;
        }

        .nav-link:hover {
            color: var(--primary) !important;
            background: rgba(123, 17, 19, 0.05);
        }

        .nav-link.active {
            color: var(--primary) !important;
            font-weight: 600;
        }

        .nav-active-indicator {
            position: absolute;
            bottom: 0px;
            height: 3px;
            background: linear-gradient(90deg, var(--primary) 0%, var(--primary-light) 50%, var(--gold) 100%);
            border-radius: 3px;
            transition: left 0.32s cubic-bezier(0.25, 1, 0.5, 1), width 0.32s cubic-bezier(0.25, 1, 0.5, 1), opacity 0.2s ease;
            pointer-events: none;
            z-index: 5;
            box-shadow: 0 2px 8px rgba(123, 17, 19, 0.35);
            opacity: 0;
            width: 0;
            left: 0;
        }

        @media (max-width: 991.98px) {
            .nav-active-indicator {
                display: none !important;
            }
            .site-nav-list .nav-link.active {
                background: rgba(123, 17, 19, 0.08) !important;
                color: var(--primary) !important;
                border-left: 3px solid var(--primary);
                padding-left: 1rem !important;
                border-radius: 4px 8px 8px 4px;
            }
        }

        /* Header Auth Buttons */
        .btn-auth-header {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0.45rem 1.25rem;
            font-size: 0.88rem;
            font-weight: 600;
            border-radius: 10px;
            line-height: 1;
            text-decoration: none;
            transition: all 0.2s ease;
            box-sizing: border-box;
        }

        .btn-auth-signin {
            background-color: var(--primary);
            border: 1.5px solid var(--primary-light);
            color: #fff !important;
            box-shadow: 0 4px 12px rgba(var(--primary-rgb), 0.25);
        }

        .btn-auth-signin:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
            color: #fff !important;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(var(--primary-rgb), 0.35);
        }

        .btn-auth-register {
            background-color: transparent;
            border: 1.5px solid #a39994;
            color: #443c39 !important;
        }

        .btn-auth-register:hover {
            background-color: #f7f2ed;
            border-color: var(--primary);
            color: var(--primary) !important;
            transform: translateY(-1px);
        }

        /* Hero Section */
        .hero-section {
            position: relative;
            background: url('garden of remembrance background.webp') center/cover no-repeat;
            padding: 5.5rem 1.5rem 6.5rem;
            color: #fff;
            overflow: hidden;
        }

        .hero-section::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(14, 10, 10, 0.86) 0%, rgba(42, 12, 15, 0.78) 100%);
            backdrop-filter: blur(5px);
            z-index: 0;
        }

        .hero-content {
            position: relative;
            z-index: 1;
            max-width: 940px;
            margin: 0 auto;
            text-align: center;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(10px);
            padding: 0.45rem 1.15rem;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.22);
            color: var(--gold-light);
        }

        .hero-title {
            font-size: clamp(2.2rem, 5.5vw, 3.6rem);
            font-weight: 800;
            line-height: 1.15;
            margin-bottom: 1.35rem;
            letter-spacing: -0.02em;
        }

        .hero-title-accent {
            background: linear-gradient(135deg, #fff 40%, var(--gold-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-subtitle {
            font-size: clamp(1.05rem, 2.2vw, 1.25rem);
            color: rgba(255, 255, 255, 0.92);
            max-width: 720px;
            margin: 0 auto 2.4rem;
            font-weight: 400;
            line-height: 1.65;
        }

        .hero-actions {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1.1rem;
            flex-wrap: wrap;
        }

        .btn-brand-primary {
            background: linear-gradient(135deg, #7b1113 0%, #580a0c 100%);
            border: 1.5px solid #9a1c1f;
            color: #fff;
            padding: 0.82rem 1.8rem;
            font-weight: 700;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(var(--primary-rgb), 0.38);
            transition: all 0.25s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-brand-primary:hover {
            background: linear-gradient(135deg, #580a0c 0%, #400507 100%);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(var(--primary-rgb), 0.5);
        }

        .btn-brand-outline {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(8px);
            border: 1.5px solid rgba(255, 255, 255, 0.35);
            color: #fff;
            padding: 0.82rem 1.8rem;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.25s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-brand-outline:hover {
            background: rgba(255, 255, 255, 0.25);
            border-color: #fff;
            color: #fff;
            transform: translateY(-2px);
        }

        /* Live Statistics Strip */
        .stats-strip {
            margin-top: -3.2rem;
            position: relative;
            z-index: 5;
        }

        .stats-card-container {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(12px);
            border-radius: 18px;
            padding: 1.5rem 1.2rem;
            box-shadow: 0 14px 40px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(123, 17, 19, 0.09);
        }

        .stat-item {
            text-align: center;
            padding: 0.5rem 1rem;
            border-right: 1px solid rgba(0, 0, 0, 0.06);
        }

        .stat-item:last-child {
            border-right: none;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary);
            line-height: 1.1;
            margin-bottom: 0.2rem;
            font-feature-settings: "tnum";
        }

        .stat-label {
            font-size: 0.82rem;
            font-weight: 600;
            color: #635753;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* Highlights Row */
        .highlights-section {
            padding: 4.5rem 0 3rem;
        }

        .highlight-card {
            background: #fff;
            border-radius: 18px;
            padding: 2rem 1.6rem;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.04);
            border: 1px solid var(--border-subtle);
            height: 100%;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .highlight-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary) 0%, var(--gold) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .highlight-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 16px 40px rgba(123, 17, 19, 0.1);
            border-color: rgba(123, 17, 19, 0.2);
        }

        .highlight-card:hover::before {
            opacity: 1;
        }

        .highlight-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: rgba(var(--primary-rgb), 0.08);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            margin-bottom: 1.25rem;
            transition: all 0.3s ease;
        }

        .highlight-card:hover .highlight-icon {
            background: var(--primary);
            color: #fff;
            transform: scale(1.08);
        }

        /* Section Cards */
        .section-header-title {
            color: var(--primary);
            font-weight: 800;
            font-size: 2.1rem;
            letter-spacing: -0.01em;
        }

        .section-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid var(--border-subtle);
            padding: 1.5rem;
            transition: all 0.25s ease;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
        }

        .section-card:hover {
            border-color: var(--primary);
            transform: translateY(-4px);
            box-shadow: 0 14px 32px rgba(123, 17, 19, 0.1);
        }

        .section-badge {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--primary);
            color: #fff;
            font-weight: 800;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Pricing Table */
        .pricing-card {
            background: #fff;
            border-radius: 20px;
            border: 1px solid var(--border-subtle);
            padding: 2.4rem 2rem;
            position: relative;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.04);
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.3s ease;
        }

        .pricing-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.08);
        }

        .pricing-card.featured {
            border: 2px solid var(--primary);
            box-shadow: 0 16px 45px rgba(123, 17, 19, 0.14);
        }

        .pricing-featured-badge {
            position: absolute;
            top: -14px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-dark) 100%);
            color: #fff;
            padding: 0.3rem 1.2rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            box-shadow: 0 4px 12px rgba(197, 155, 39, 0.3);
        }

        .price-val {
            font-size: 2.6rem;
            font-weight: 800;
            color: var(--primary);
            line-height: 1.1;
        }

        /* Map Embed Card */
        .map-preview-card {
            background: #fff;
            border-radius: 20px;
            border: 1px solid var(--border-subtle);
            overflow: hidden;
            box-shadow: 0 14px 45px rgba(0, 0, 0, 0.07);
        }

        .map-preview-inner {
            position: relative;
            background: #1f1b19;
            text-align: center;
            padding: 2.5rem 1.5rem;
        }

        .map-preview-img {
            max-width: 100%;
            height: auto;
            border-radius: 12px;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.4);
            max-height: 500px;
            object-fit: contain;
            transition: transform 0.3s ease;
        }

        .map-preview-inner:hover .map-preview-img {
            transform: scale(1.01);
        }

        /* Footer */
        .site-footer {
            background: #14100f;
            color: #a8a09b;
            padding: 3.5rem 1.25rem 2rem;
            border-top: 1px solid #26201e;
            overflow-x: hidden;
            word-break: break-word;
            overflow-wrap: break-word;
        }

        .site-footer a {
            color: #d6cfc7;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .site-footer a:hover {
            color: #fff;
        }

        .site-footer ul li {
            word-break: break-word;
            overflow-wrap: break-word;
            line-height: 1.5;
        }

        @media (max-width: 768px) {
            .hero-section {
                padding: 4rem 1rem 5rem;
            }
            .stat-item {
                border-right: none;
                border-bottom: 1px solid rgba(0, 0, 0, 0.06);
                padding: 0.8rem 0;
            }
            .stat-item:last-child {
                border-bottom: none;
            }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg site-navbar">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <img src="cmis_logo.png" alt="Garden of Remembrance Logo">
                <span>Garden of Remembrance</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNav" aria-controls="publicNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="publicNav">
                <ul class="navbar-nav me-auto ms-lg-4 mb-2 mb-lg-0 site-nav-list" id="mainNavList">
                    <li class="nav-item"><a class="nav-link active" href="#hero" data-nav="hero">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="#sections" data-nav="sections">Sections</a></li>
                    <li class="nav-item"><a class="nav-link" href="#pricing" data-nav="pricing">Services & Pricing</a></li>
                    <li class="nav-item"><a class="nav-link" href="#about" data-nav="about">About</a></li>
                    <div class="nav-active-indicator" id="navActiveIndicator" aria-hidden="true"></div>

                    <?php if (!$showPublicAuthNav && $isLoggedIn && $isUser): ?>
                        <li class="nav-item d-lg-none border-top pt-2 mt-2">
                            <a class="nav-link fw-semibold" href="profile.php">
                                <i class="fas fa-user me-2"></i>Profile
                            </a>
                        </li>
                        <li class="nav-item d-lg-none">
                            <a class="nav-link fw-semibold text-danger" href="user_dashboard.php">
                                <i class="fas fa-calendar-check me-2"></i>My Books
                            </a>
                        </li>
                        <li class="nav-item d-lg-none">
                            <a class="nav-link fw-semibold text-muted" href="logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i>Logout
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item d-lg-none border-top pt-2 mt-2">
                            <a class="nav-link fw-semibold text-danger" href="login.php">
                                <i class="fas fa-sign-in-alt me-2"></i>Sign In
                            </a>
                        </li>
                        <li class="nav-item d-lg-none">
                            <a class="nav-link fw-semibold" href="login.php?register=1">
                                <i class="fas fa-user-plus me-2"></i>Register
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>

                <div class="d-none d-lg-flex align-items-center gap-2 flex-wrap">
                    <?php if (!$showPublicAuthNav && $isLoggedIn && $isUser): ?>
                        <a href="profile.php" class="btn btn-sm btn-outline-secondary px-3"><i class="fas fa-user me-1"></i>Profile</a>
                        <a href="user_dashboard.php" class="btn btn-sm btn-outline-danger px-3"><i class="fas fa-calendar-check me-1"></i>My Books</a>
                        <a href="logout.php" class="btn btn-sm btn-outline-secondary px-3">Logout</a>
                    <?php else: ?>
                        <a href="login.php" class="btn-auth-header btn-auth-signin">Sign In</a>
                        <a href="login.php?register=1" class="btn-auth-header btn-auth-register">Register</a>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </nav>

    <header class="hero-section" id="hero">
        <div class="hero-content">
            <div class="hero-badge">
                <i class="fas fa-monument text-warning"></i> Memorial Grounds & Digital Mapping System
            </div>
            <h1 class="hero-title">
                A Sanctuary of <span class="hero-title-accent">Honor, Dignity & Peace</span>
            </h1>
            <p class="hero-subtitle">
                Welcome to Garden of Remembrance. Explore our cemetery grounds, verify live plot locations and availability in real time, and review transparent burial services with zero login barriers.
            </p>
            <div class="hero-actions">
                <a href="map.php?from=index" class="btn-brand-primary btn-lg">
                    <i class="fas fa-map-location-dot"></i>Explore Cemetery Map
                </a>
                <a href="#sections" class="btn-brand-outline btn-lg">
                    <i class="fas fa-th"></i>View Cemetery Sectors
                </a>
            </div>
        </div>
    </header>

    <section class="container stats-strip">
        <div class="stats-card-container">
            <div class="row g-0 align-items-center">
                <div class="col-md-3 col-6 stat-item">
                    <div class="stat-value"><?= number_format($stats['total_plots']) ?>+</div>
                    <div class="stat-label">Total Memorial Plots</div>
                </div>
                <div class="col-md-3 col-6 stat-item">
                    <div class="stat-value text-success"><?= number_format($stats['available_plots']) ?>+</div>
                    <div class="stat-label">Available for Book</div>
                </div>
                <div class="col-md-3 col-6 stat-item">
                    <div class="stat-value" style="color: var(--gold);"><?= (int)$stats['sections_count'] ?></div>
                    <div class="stat-label">Active Cemetery Sectors</div>
                </div>
                <div class="col-md-3 col-6 stat-item">
                    <div class="stat-value">24/7</div>
                    <div class="stat-label">Digital Map & Records</div>
                </div>
            </div>
        </div>
    </section>

    <section class="container highlights-section">
        <div class="row g-4">
            <div class="col-md-4">
                <div class="highlight-card">
                    <div class="highlight-icon">
                        <i class="fas fa-map-marked-alt"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Digital Map</h5>
                    <p class="text-muted small mb-0">
                        View all 10 cemetery sections, zoom in on lot grids, and check real-time availability for Available, Reserved, and Occupied burial plots.
                    </p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="highlight-card">
                    <div class="highlight-icon">
                        <i class="fas fa-magnifying-glass-location"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Locate Loved Ones</h5>
                    <p class="text-muted small mb-0">
                        Easily search deceased family members and receive walking directions directly to their resting place with turn-by-turn guidance.
                    </p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="highlight-card">
                    <div class="highlight-icon">
                        <i class="fas fa-hand-holding-heart"></i>
                    </div>
                    <h5 class="fw-bold mb-2">Transparent Booking & Care</h5>
                    <p class="text-muted small mb-0">
                        Published prices for regular and corner plots with flexible 12-month amortizations and perpetual grounds maintenance.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section class="container py-5 mt-2" id="map-section">
        <div class="text-center mb-4">
            <span class="badge px-3 py-2 rounded-pill mb-2" style="background: rgba(123, 17, 19, 0.08); color: var(--primary); font-weight: 700;">VISITOR EXPLORATION</span>
            <h2 class="section-header-title">Live Cemetery Grounds Map</h2>
            <p class="text-muted mx-auto" style="max-width: 640px;">
                Visitors can freely explore cemetery sections, examine individual plot coordinates, and check plot pricing without creating an account.
            </p>
        </div>

        <div class="map-preview-card p-3 p-md-4">
            <div class="map-preview-inner rounded-4">
                <a href="map.php?from=index" title="Click to launch full digital map">
                    <img src="cmi_map.png" alt="Cemetery Grounds Map" class="map-preview-img">
                </a>
                <div class="mt-4">
                    <div class="d-flex justify-content-center gap-3 flex-wrap mb-3 text-white small">
                        <span><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:#4caf50;margin-right:6px;"></span>0-20 Partially Occupied</span>
                        <span><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:#ff9800;margin-right:6px;"></span>21–50 Slightly Full</span>
                        <span><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:#da3941;margin-right:6px;"></span>51–100 Full (Red)</span>
                    </div>
                    <a href="map.php?from=index" class="btn-brand-primary px-4 py-2">
                        <i class="fas fa-up-right-from-square"></i>Open Full Digital Map
                    </a>
                </div>
            </div>
        </div>
    </section>

    <section class="container py-5" id="sections">
        <div class="text-center mb-5">
            <span class="badge px-3 py-2 rounded-pill mb-2" style="background: rgba(123, 17, 19, 0.08); color: var(--primary); font-weight: 700;">CEMETERY SECTORS</span>
            <h2 class="section-header-title">Sections & Plot Availability</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">
                Click any section below to inspect available burial plots, pricing, and deceased details in public demo mode.
            </p>
        </div>

        <div class="row g-4">
            <?php foreach ($sections as $s): ?>
            <?php $isAvail = !in_array($s['code'], ['G', 'H', 'I', 'J'], true); ?>
            <div class="col-lg-3 col-md-4 col-sm-6">
                <div class="section-card">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="section-badge" style="<?= !$isAvail ? 'background:#827a76;' : '' ?>"><?= $s['code'] ?></div>
                            <?php if ($isAvail): ?>
                                <span class="badge bg-success bg-opacity-10 text-success px-2 py-1 small fw-semibold">Available</span>
                            <?php else: ?>
                                <span class="badge bg-secondary bg-opacity-25 text-secondary px-2 py-1 small fw-semibold">Unavailable</span>
                            <?php endif; ?>
                        </div>
                        <h5 class="fw-bold mb-1 text-dark"><?= $s['title'] ?></h5>
                        <p class="small fw-semibold mb-2" style="color: var(--primary);"><?= $s['type'] ?></p>
                        <p class="small text-muted mb-3"><?= $s['desc'] ?></p>
                    </div>
                    <div>
                        <?php if ($isAvail): ?>
                            <a href="sections/popup.php?section=<?= $s['code'] ?>&from=index_direct" class="btn btn-outline-danger w-100 btn-sm fw-semibold" style="border-radius: 9px;">
                                <i class="fas fa-eye me-1"></i>Explore Section <?= $s['code'] ?>
                            </a>
                        <?php else: ?>
                            <a href="sections/popup.php?section=<?= $s['code'] ?>&from=index_direct" class="btn btn-outline-secondary w-100 btn-sm fw-semibold" style="border-radius: 9px; opacity: 0.85;">
                                <i class="fas fa-ban me-1"></i>Section Unavailable
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="container py-5" id="pricing">
        <div class="text-center mb-5">
            <span class="badge px-3 py-2 rounded-pill mb-2" style="background: rgba(123, 17, 19, 0.08); color: var(--primary); font-weight: 700;">TRANSPARENT PRICING</span>
            <h2 class="section-header-title">Burial Plots & Memorial Services</h2>
            <p class="text-muted mx-auto" style="max-width: 600px;">
                Clear, upfront pricing with no hidden charges. Flexible payment options and permanent perpetual care.
            </p>
        </div>

        <div class="row g-4 justify-content-center">

            <div class="col-lg-4 col-md-6">
                <div class="pricing-card">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase mb-2">Standard Lawn Plot</div>
                        <h3 class="fw-bold text-dark mb-1">Standard Lot</h3>
                        <p class="small text-muted mb-3">Lawn-level memorial plots inside quiet interior avenues.</p>
                        <div class="price-val mb-1">₱85,000</div>
                        <div class="small text-secondary mb-4">or <strong>₱7,226.00/mo</strong> for 12 months</div>
                        <ul class="list-unstyled small text-muted mb-4 d-grid gap-2">
                            <li><i class="fas fa-check text-success me-2"></i>Single / Double Depth Vault Capacity</li>
                            <li><i class="fas fa-check text-success me-2"></i>Up to 4 Memorial Deceased Names</li>
                            <li><i class="fas fa-check text-success me-2"></i>Perpetual Care & Maintenance Included</li>
                            <li><i class="fas fa-check text-success me-2"></i>Full Access to Digital Map</li>
                        </ul>
                    </div>
                    <div>
                        <a href="map.php?from=index" class="btn-brand-primary w-100 py-2 fw-semibold" style="border-radius:10px;justify-content:center;">
                            <i class="fas fa-bookmark me-1"></i>Reserve Now
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="pricing-card featured">
                    <span class="pricing-featured-badge">MOST POPULAR</span>
                    <div>
                        <div class="small fw-bold text-uppercase mb-2" style="color: var(--primary);">Walkway Frontage</div>
                        <h3 class="fw-bold text-dark mb-1">Corner Lawn Plot</h3>
                        <p class="small text-muted mb-3">Prime corner plots with wide walkway accessibility and prominence.</p>
                        <div class="price-val mb-1">₱89,000</div>
                        <div class="small text-secondary mb-4">or <strong>₱7,565.00/mo</strong> for 12 months</div>
                        <ul class="list-unstyled small text-muted mb-4 d-grid gap-2">
                            <li><i class="fas fa-check text-success me-2"></i>Corner Walkway Advantage & Clear View</li>
                            <li><i class="fas fa-check text-success me-2"></i>Single / Double Depth Vault Capacity</li>
                            <li><i class="fas fa-check text-success me-2"></i>Up to 4 Memorial Deceased Names</li>
                            <li><i class="fas fa-check text-success me-2"></i>Priority Grounds & Floral Care</li>
                            <li><i class="fas fa-check text-success me-2"></i>Perpetual Landscaping Included</li>
                        </ul>
                    </div>
                    <div>
                        <a href="map.php?from=index" class="btn-brand-primary w-100 py-2 fw-semibold" style="border-radius:10px;justify-content:center;">
                            <i class="fas fa-bookmark me-1"></i>Reserve Now
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6">
                <div class="pricing-card">
                    <div>
                        <div class="text-muted small fw-bold text-uppercase mb-2">Interment Package</div>
                        <h3 class="fw-bold text-dark mb-1">Burial & Service Fee</h3>
                        <p class="small text-muted mb-3">Complete ceremonial and logistical preparation on interment day.</p>
                        <div class="price-val mb-1">₱38,000</div>
                        <div class="small text-secondary mb-4">Standard Interment Package</div>
                        <ul class="list-unstyled small text-muted mb-4 d-grid gap-2">
                            <li><i class="fas fa-check text-success me-2"></i>Grave Excavation & Reinforced Crypt Vault</li>
                            <li><i class="fas fa-check text-success me-2"></i>Canopy Tent & Family Seating Setup</li>
                            <li><i class="fas fa-check text-success me-2"></i>Mechanical Lowering Device Operation</li>
                            <li><i class="fas fa-check text-success me-2"></i>Full Commemoration Coordination</li>
                            <li><i class="fas fa-check text-success me-2"></i>Official Records Archiving</li>
                        </ul>
                    </div>
                    <div>
                        <a href="map.php?from=index" class="btn-brand-primary w-100 py-2 fw-semibold" style="border-radius:10px;justify-content:center;">
                            <i class="fas fa-bookmark me-1"></i>Reserve Now
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="container py-5" id="about">
        <a id="assistance" style="display:block; position:relative; top:-80px; visibility:hidden;"></a>
        <div class="card border-0 p-4 p-md-5 rounded-4 shadow-sm" style="background: linear-gradient(135deg, #fbf7f4 0%, #f4eee9 100%); border: 1px solid var(--border-subtle) !important;">
            <div class="row align-items-center g-4">
                <div class="col-12">
                    <span class="badge px-3 py-2 rounded-pill mb-2" style="background: rgba(123, 17, 19, 0.08); color: var(--primary); font-weight: 700;">ABOUT &amp; ASSISTANCE</span>
                    <h3 class="fw-bold text-dark mb-2">About Garden of Remembrance Memorial Park</h3>
                    <p class="text-muted mb-4">
                        Dedicated to providing a serene sanctuary of honor, dignity, and peace. Whether you are planning ahead or inquiring about existing memorial records, our administration office is open daily to provide compassionate guidance.
                    </p>
                    <div class="row g-4 text-dark mt-1">
                        <div class="col-lg-4 col-md-6">
                            <div class="d-flex align-items-start gap-2">
                                <i class="fas fa-map-marker-alt text-danger mt-1 fs-5"></i>
                                <div>
                                    <strong class="d-block text-dark mb-1">Memorial Park Location:</strong>
                                    <div class="small text-secondary" style="line-height: 1.55;">
                                        <strong>Garden of Remembrance Memorial Park</strong><br>
                                        De Guzman Street, Barangay Palamis,<br>
                                        Alaminos City, Pangasinan 2404,<br>
                                        Philippines
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-6">
                            <div class="d-flex align-items-start gap-2">
                                <i class="fas fa-phone-alt text-danger mt-1 fs-5"></i>
                                <div>
                                    <strong class="d-block text-dark mb-1">Office Hotline:</strong>
                                    <div class="small fw-semibold" style="color: #7b1113; font-size: 0.95rem;">
                                        0960-529-5663 / 0928-864-2520
                                    </div>
                                    <div class="small text-muted mt-1">Available during office hours</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-12">
                            <div class="d-flex align-items-start gap-2">
                                <i class="fas fa-clock text-danger mt-1 fs-5"></i>
                                <div class="w-100">
                                    <strong class="d-block text-dark mb-1">Operating Hours:</strong>
                                    <div class="small text-secondary" style="line-height: 1.6;">
                                        <div class="d-flex justify-content-between gap-3">
                                            <span><strong>Monday – Friday:</strong></span>
                                            <span class="fw-semibold text-dark">8:00 AM – 5:00 PM</span>
                                        </div>
                                        <div class="d-flex justify-content-between gap-3">
                                            <span><strong>Saturday:</strong></span>
                                            <span class="fw-semibold text-dark">8:00 AM – 3:00 PM</span>
                                        </div>
                                        <div class="d-flex justify-content-between gap-3 align-items-center">
                                            <span><strong>Sunday:</strong></span>
                                            <span class="badge bg-danger text-white fw-bold px-2 py-0" style="font-size: 0.72rem;">CLOSED</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="text-center mt-4 pt-3 border-top border-secondary border-opacity-10">
                        <a href="aboutus.php" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold">
                            <i class="fas fa-info-circle me-1"></i> Read Full Story &amp; Location Details
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <footer class="site-footer">
        <div class="container">
            <div class="row g-4 pb-4 border-bottom border-secondary border-opacity-20">
                <div class="col-lg-6">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <img src="cmis_logo.png" alt="Garden of Remembrance Logo" style="height: 38px; width: auto;">
                        <span class="fw-bold text-white fs-5">Garden of Remembrance</span>
                    </div>
                    <p class="small text-light mb-0" style="max-width: 400px; color: #b0a7a0 !important;">
                        Providing dignified memorial services, tranquil resting grounds, and modern digital cemetery mapping for families and visitors.
                    </p>
                </div>
                <div class="col-lg-3 col-sm-6 col-12">
                    <h6 class="text-white fw-semibold mb-3 small text-uppercase">Navigation</h6>
                    <ul class="list-unstyled small d-grid gap-2">
                        <li><a href="index.php">Home</a></li>
                        <li><a href="map.php?from=index">Cemetery Map</a></li>
                        <li><a href="#sections">Sections</a></li>
                        <li><a href="#pricing">Pricing</a></li>
                        <li><a href="aboutus.php">About Us</a></li>
                    </ul>
                </div>
                <div class="col-lg-3 col-sm-6 col-12">
                    <h6 class="text-light fw-semibold mb-3 small text-uppercase">Contact</h6>
                    <ul class="list-unstyled small text-light d-grid gap-2" style="color: #b0a7a0 !important;">
                        <li><i class="fas fa-phone me-2 text-danger"></i>0960-529-5663 / 0928-864-2520</li>
                        <li><i class="fas fa-map-marker-alt me-2 text-danger"></i>De Guzman St., Brgy. Palamis, Alaminos City, Pangasinan 2404</li>
                        <li><i class="fas fa-clock me-2 text-danger"></i>Mon–Fri: 8AM–5PM | Sat: 8AM–3PM | Sun: Closed</li>
                        <li><i class="fas fa-envelope me-2 text-danger"></i>support@gardenofremembrance.com</li>
                    </ul>
                </div>
            </div>
            <div class="pt-3 text-center small" style="color: #8c827b;">
                &copy; <?= date('Y') ?> Garden of Remembrance Memorial Park. All rights reserved. Unified Cemetery Management Information System.
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Global escapeHtml helper
    function escapeHtml(value) {
        if (value === null || value === undefined) return '';
        return String(value).replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }
    window.escapeHtml = escapeHtml;

    // Hidden administrator entry point for desktop: Ctrl + B opens admin login page
    document.addEventListener('keydown', function(event) {
        var isCtrl = event.ctrlKey || event.metaKey;
        var isKeyB = (event.key === 'b' || event.key === 'B' || event.code === 'KeyB' || event.keyCode === 66);
        if (isCtrl && isKeyB) {
            var target = event.target;
            var isEditing = target && (
                target.tagName === 'INPUT' ||
                target.tagName === 'TEXTAREA' ||
                target.isContentEditable ||
                (target.getAttribute && target.getAttribute('contenteditable') === 'true')
            );
            if (!isEditing) {
                event.preventDefault();
                event.stopPropagation();
                window.location.href = 'admin_login.php';
            }
        }
    }, true);

    // Discreet mobile gesture: Triple-tap or Long-press on brand logo
    (function() {
        var brand = document.querySelector('.navbar-brand');
        if (!brand) return;

        var tapCount = 0;
        var tapTimer = null;
        var pressTimer = null;

        brand.addEventListener('touchend', function(e) {
            tapCount++;
            if (tapCount === 1) {
                tapTimer = setTimeout(function() {
                    tapCount = 0;
                }, 1200);
            } else if (tapCount >= 3) {
                clearTimeout(tapTimer);
                tapCount = 0;
                e.preventDefault();
                window.location.href = 'admin_login.php';
            }
        });

        brand.addEventListener('touchstart', function() {
            pressTimer = setTimeout(function() {
                window.location.href = 'admin_login.php';
            }, 3000);
        }, { passive: true });

        brand.addEventListener('touchmove', function() {
            clearTimeout(pressTimer);
        });

        brand.addEventListener('touchend', function() {
            clearTimeout(pressTimer);
        });

        brand.addEventListener('touchcancel', function() {
            clearTimeout(pressTimer);
        });
    })();

    // Auto-close public mobile navbar when clicking any in-page anchor link
    document.querySelectorAll('#publicNav a[href^="#"]').forEach(function(link) {
        link.addEventListener('click', function() {
            var navCollapse = document.getElementById('publicNav');
            if (navCollapse && navCollapse.classList.contains('show')) {
                var bsCollapse = bootstrap.Collapse.getInstance(navCollapse);
                if (bsCollapse) {
                    bsCollapse.hide();
                } else {
                    navCollapse.classList.remove('show');
                }
            }
        });
    });

    // ==========================================
    // Guest Index Navigation Active Indicator
    // ==========================================
    (function() {
        const navList = document.getElementById('mainNavList');
        const indicator = document.getElementById('navActiveIndicator');
        if (!navList || !indicator) return;

        const navLinks = Array.from(navList.querySelectorAll('.nav-link[data-nav]'));
        let currentActiveNav = 'hero';
        let isManualClickScrolling = false;
        let scrollLockTimer = null;
        let ticking = false;

        function updateIndicator(activeLink) {
            if (!activeLink) return;
            if (window.innerWidth < 992) {
                indicator.style.opacity = '0';
                return;
            }

            const listRect = navList.getBoundingClientRect();
            const linkRect = activeLink.getBoundingClientRect();

            // Calculate exact position relative to navList
            const left = linkRect.left - listRect.left;
            const width = linkRect.width;

            if (width > 0) {
                indicator.style.opacity = '1';
                indicator.style.left = left + 'px';
                indicator.style.width = width + 'px';
            }
        }

        function setActiveNav(navKey, triggerIndicator) {
            if (typeof triggerIndicator === 'undefined') triggerIndicator = true;
            if (!navKey) return;
            currentActiveNav = navKey;
            let activeLink = null;

            navLinks.forEach(function(link) {
                if (link.getAttribute('data-nav') === navKey) {
                    link.classList.add('active');
                    activeLink = link;
                } else {
                    link.classList.remove('active');
                }
            });

            if (triggerIndicator && activeLink) {
                updateIndicator(activeLink);
            }
        }

        function getActiveSection() {
            const scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
            const docHeight = document.documentElement.scrollHeight;
            const winHeight = window.innerHeight;

            // 1. Bottom of page threshold -> About
            if (scrollY + winHeight >= docHeight - 70) {
                return 'about';
            }

            // 2. Near top threshold -> Home
            if (scrollY < 180) {
                return 'hero';
            }

            const navEl = document.querySelector('.site-navbar');
            const navHeight = navEl ? navEl.offsetHeight : 70;
            const checkY = scrollY + navHeight + 80;

            const aboutEl = document.getElementById('about');
            const pricingEl = document.getElementById('pricing');
            const sectionsEl = document.getElementById('sections');

            // Check sections from bottom to top
            if (aboutEl && checkY >= aboutEl.offsetTop) {
                return 'about';
            }
            if (pricingEl && checkY >= pricingEl.offsetTop) {
                return 'pricing';
            }
            if (sectionsEl && checkY >= sectionsEl.offsetTop) {
                return 'sections';
            }
            return 'hero';
        }

        function onScroll() {
            if (isManualClickScrolling) return;
            if (!ticking) {
                window.requestAnimationFrame(function() {
                    const activeKey = getActiveSection();
                    if (activeKey !== currentActiveNav) {
                        setActiveNav(activeKey, true);
                    }
                    ticking = false;
                });
                ticking = true;
            }
        }

        // Click handler for smooth scrolling and immediate indicator update
        navLinks.forEach(function(link) {
            link.addEventListener('click', function(e) {
                const targetKey = this.getAttribute('data-nav');
                const targetHref = this.getAttribute('href');
                if (!targetKey || !targetHref || !targetHref.startsWith('#')) return;

                const targetId = targetHref.substring(1);
                const targetEl = document.getElementById(targetId);
                if (!targetEl && targetId !== 'hero') return;

                e.preventDefault();

                // Lock scroll listener to prevent intermediate items from flickering
                isManualClickScrolling = true;
                clearTimeout(scrollLockTimer);

                setActiveNav(targetKey, true);

                const navEl = document.querySelector('.site-navbar');
                const navHeight = navEl ? navEl.offsetHeight : 70;
                let targetY = 0;
                if (targetId === 'hero' || !targetEl) {
                    targetY = 0;
                } else {
                    targetY = targetEl.getBoundingClientRect().top + window.pageYOffset - navHeight + 4;
                }

                window.scrollTo({
                    top: Math.max(0, targetY),
                    behavior: 'smooth'
                });

                if (history.pushState) {
                    history.pushState(null, null, targetHref);
                }

                scrollLockTimer = setTimeout(function() {
                    isManualClickScrolling = false;
                    const finalKey = getActiveSection();
                    setActiveNav(finalKey, true);
                }, 850);
            });
        });

        window.addEventListener('scroll', onScroll, { passive: true });

        // Recalculate on window resize, zoom, orientation change
        window.addEventListener('resize', function() {
            const activeLink = navList.querySelector('.nav-link.active');
            if (activeLink) {
                updateIndicator(activeLink);
            }
        });

        // Initialize on DOM load
        function initNav() {
            let initialKey = 'hero';
            const hash = window.location.hash ? window.location.hash.substring(1) : '';
            if (hash === 'sections') initialKey = 'sections';
            else if (hash === 'pricing' || hash === 'services') initialKey = 'pricing';
            else if (hash === 'about' || hash === 'assistance') initialKey = 'about';
            else {
                initialKey = getActiveSection();
            }

            setActiveNav(initialKey, true);
            setTimeout(function() {
                const currentActiveLink = navList.querySelector('.nav-link.active');
                if (currentActiveLink) updateIndicator(currentActiveLink);
            }, 100);
            setTimeout(function() {
                const currentActiveLink = navList.querySelector('.nav-link.active');
                if (currentActiveLink) updateIndicator(currentActiveLink);
            }, 400);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initNav);
        } else {
            initNav();
        }
        window.addEventListener('load', function() {
            const currentActiveLink = navList.querySelector('.nav-link.active');
            if (currentActiveLink) updateIndicator(currentActiveLink);
        });
    })();
    </script>
<script src="includes/session_tab_guard.js"></script>
<script>
// Tab-aware session guard — this tab is the public index
if (window.GorSessionGuard) {
    GorSessionGuard.init('index');
}
</script>
</body>
</html>
