<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/connection.php';

$fromParam = strtolower(trim((string)($_GET['from'] ?? '')));
$isForcedPublic = isset($_GET['public']) && $_GET['public'] === '1';

if ($fromParam === 'admin' || $fromParam === 'admin_login') {
    header("Location: admin_about.php" . ($fromParam === 'admin_login' ? '?from=admin_login' : ''));
    exit;
}
if ($fromParam === 'user_dashboard' || $fromParam === 'user') {
    header("Location: user_about.php");
    exit;
}


$exitUrl = 'index.php';
$exitLabel = 'Home';
$active_nav = 'about';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us - Garden of Remembrance Memorial Park</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bs-primary: #7b1113;
            --primary: #7b1113;
            --primary-dark: #610d10;
        }
        body {
            background-color: #f7f5f2;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: #2b211f;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            margin: 0;
            padding: 0;
        }
        /* Header styled with exact admin header maroon color */
        .site-navbar {
            background: linear-gradient(135deg, #7b1113 0%, #8e1518 50%, #5d0c0e 100%) !important;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            position: sticky;
            top: 0;
            z-index: 1040;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.16), inset 0 -1px 0 rgba(255, 255, 255, 0.1);
            padding: 0.5rem 1.25rem;
        }
        .site-navbar .navbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 700;
            color: #ffffff !important;
            font-size: 1.12rem;
            text-decoration: none;
            letter-spacing: 0.01em;
        }
        .site-navbar .navbar-brand img {
            height: 38px;
            width: auto;
            filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.18));
        }
        .site-navbar .nav-link {
            color: rgba(255, 255, 255, 0.9) !important;
            font-weight: 600;
            padding: 0.45rem 0.95rem !important;
            border-radius: 999px;
            transition: all 0.2s ease;
            font-size: 0.92rem;
        }
        .site-navbar .nav-link:hover {
            color: #ffffff !important;
            background: rgba(255, 255, 255, 0.15);
        }
        .site-navbar .nav-link.active {
            color: #ffffff !important;
            background: rgba(255, 255, 255, 0.22);
            font-weight: 700;
        }
        .site-navbar .navbar-toggler {
            border-color: rgba(255, 255, 255, 0.4);
            filter: invert(1);
        }
        .btn-header-back {
            background: rgba(255, 255, 255, 0.14);
            border: 1.5px solid rgba(255, 255, 255, 0.65);
            color: #ffffff !important;
            font-weight: 600;
            border-radius: 999px;
            padding: 0.4rem 1.25rem;
            font-size: 0.88rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }
        .btn-header-back:hover {
            background: #ffffff;
            color: #7b1113 !important;
            border-color: #ffffff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        @media (max-width: 991.98px) {
            .site-navbar .navbar-collapse {
                background: linear-gradient(135deg, #7b1113 0%, #610d10 100%);
                padding: 1rem;
                border-radius: 12px;
                margin-top: 0.75rem;
                box-shadow: 0 10px 25px rgba(0,0,0,0.25);
                border: 1px solid rgba(255,255,255,0.15);
            }
            .site-navbar .nav-link {
                padding: 0.65rem 1rem !important;
                margin-bottom: 0.25rem;
            }
            .site-navbar .btn-header-back {
                width: 100%;
                justify-content: center;
                margin-top: 0.5rem;
            }
        }

        /* About Page Content */
        .about-page-wrapper {
            flex: 1;
            padding: 2.5rem 1rem 3.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .about-card {
            background: #ffffff;
            border-radius: 1.5rem;
            box-shadow: 0 12px 36px rgba(43, 33, 31, 0.08);
            border: 1px solid rgba(140, 91, 45, 0.12);
            padding: 2.75rem 2.25rem;
            max-width: 940px;
            width: 100%;
        }
        .about-title {
            color: #7b1113;
            font-weight: 700;
            font-size: 1.85rem;
            letter-spacing: -0.02em;
            margin-bottom: 0.35rem;
            text-align: center;
        }
        .about-subtitle {
            color: #8c5b2d;
            font-weight: 600;
            font-size: 1rem;
            text-align: center;
            margin-bottom: 2rem;
        }
        .about-icon-badge {
            width: 88px;
            height: 88px;
            border-radius: 1.25rem;
            background: #ffffff;
            color: #7b1113;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            box-shadow: 0 6px 20px rgba(123, 17, 19, 0.12);
            border: 1px solid rgba(123, 17, 19, 0.15);
            padding: 8px;
        }
        .about-icon-badge img {
            max-height: 100%;
            max-width: 100%;
            object-fit: contain;
        }
        .about-text {
            font-size: 1.05rem;
            line-height: 1.85;
            color: #4a403d;
            margin-bottom: 2rem;
            text-align: justify;
        }
        .info-panel {
            background: #fdfbf9;
            border: 1px solid rgba(140, 91, 45, 0.14);
            border-radius: 1rem;
            padding: 1.25rem 1.5rem;
            height: 100%;
        }
        .info-panel h5 {
            color: #7b1113;
            font-weight: 700;
            font-size: 1.05rem;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .info-panel p {
            margin: 0;
            color: #554944;
            font-size: 0.95rem;
            line-height: 1.6;
        }
        .map-frame-wrapper {
            position: relative;
            width: 100%;
            height: 100%;
            min-height: 310px;
            border-radius: 0 0 1rem 1rem;
            overflow: hidden;
            background: #e5e7eb;
        }
        .map-frame-wrapper iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: 0;
        }
        .about-footer-tag {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(140, 91, 45, 0.12);
            color: #8c7e75;
            font-size: 0.88rem;
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg site-navbar">
        <div class="container">
            <a class="navbar-brand" href="index.php" title="Return to Home">
                <img src="cmis_logo.png" alt="Garden of Remembrance Logo">
                <span>Garden of Remembrance</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNav" aria-controls="publicNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="publicNav">
                <ul class="navbar-nav me-auto ms-lg-4 mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="fas fa-home me-1"></i> Home
                        </a>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="index.php#sections">Sections</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php#pricing">Services &amp; Pricing</a></li>
                    <li class="nav-item"><a class="nav-link active" href="aboutus.php">About</a></li>
                </ul>
                <div class="d-flex align-items-center gap-2">
                    <a href="index.php" class="btn-header-back" title="Back to Home">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>
        </div>
    </nav>

<div class="about-page-wrapper">
    <div class="about-card">
        <div class="mb-3">
            <a href="index.php" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold">
                <i class="fas fa-arrow-left me-1"></i> Back to Home
            </a>
        </div>
        <div class="about-icon-badge">
            <img src="cmis_logo.png" alt="Garden of Remembrance Logo">
        </div>
        <h1 class="about-title">Garden of Remembrance Memorial Park</h1>
        <div class="about-subtitle">Honoring memories with care and dignity</div>

        <p class="about-text">
            This system is designed to provide a simple, secure, and respectful way to locate, manage, and preserve records of our dearly departed. Through a digital map and an organized records database, families can easily find their loved ones, review memorial records, and submit reservation or plot transfer requests, while administrators can efficiently manage burial information and cemetery operations.
        </p>

        <div class="row g-3">
            <div class="col-lg-5 col-12">
                <div class="info-panel d-flex flex-column justify-content-between">
                    <div>
                        <h5><i class="fas fa-map-marker-alt text-danger"></i> Memorial Park Location</h5>

                        <div class="mb-3">
                            <span class="d-block text-muted small fw-bold text-uppercase mb-1" style="letter-spacing: 0.05em;">Full Address</span>
                            <div class="fw-medium text-dark" style="line-height: 1.55;">
                                <strong>Garden of Remembrance Memorial Park</strong><br>
                                De Guzman Street, Barangay Palamis,<br>
                                Alaminos City, Pangasinan 2404,<br>
                                Philippines
                            </div>
                        </div>

                        <div class="mb-3">
                            <span class="d-block text-muted small fw-bold text-uppercase mb-1" style="letter-spacing: 0.05em;">Office Hotline</span>
                            <div class="fw-semibold" style="color: #7b1113;">
                                <i class="fas fa-phone-alt me-1 text-muted"></i> 0960-529-5663 / 0928-864-2520
                            </div>
                        </div>

                        <div class="mb-3">
                            <span class="d-block text-muted small fw-bold text-uppercase mb-1" style="letter-spacing: 0.05em;">Operating Hours</span>
                            <div class="text-secondary" style="font-size: 0.92rem; line-height: 1.6;">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <span><i class="fas fa-calendar-day me-1 text-muted"></i> <strong>Monday – Friday:</strong></span>
                                    <span class="fw-semibold text-dark">8:00 AM – 5:00 PM</span>
                                </div>
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <span><i class="fas fa-calendar-day me-1 text-muted"></i> <strong>Saturday:</strong></span>
                                    <span class="fw-semibold text-dark">8:00 AM – 3:00 PM</span>
                                </div>
                                <div class="d-flex align-items-center justify-content-between">
                                    <span><i class="fas fa-calendar-times me-1 text-danger"></i> <strong>Sunday:</strong></span>
                                    <span class="badge bg-danger text-white fw-bold px-2 py-1" style="font-size: 0.75rem;">CLOSED</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-2 pt-3 border-top d-flex flex-column gap-2">
                        <a href="https://www.google.com/maps/dir/?api=1&destination=16.1504006,119.9823707" target="_blank" rel="noopener noreferrer" class="btn btn-danger w-100 fw-semibold d-flex align-items-center justify-content-center gap-2" style="background: linear-gradient(135deg, #7b1113 0%, #94171a 100%); border: none; border-radius: 10px; padding: 0.65rem 1rem; box-shadow: 0 4px 12px rgba(123,17,19,0.2);">
                            <i class="fas fa-directions"></i> Get Directions on Google Maps
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-lg-7 col-12">
                <div class="info-panel p-0 overflow-hidden d-flex flex-column" style="border: 1px solid rgba(140, 91, 45, 0.18);">
                    <div class="p-3 border-bottom d-flex align-items-center justify-content-between" style="background: #ffffff;">
                        <h5 class="m-0" style="font-size: 1rem;"><i class="fas fa-map-marked-alt text-danger"></i> Google Maps View</h5>
                        <a href="https://www.google.com/maps/search/?api=1&query=16.1504006,119.9823707" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary" style="border-radius: 8px; font-size: 0.8rem; font-weight: 500;">
                            <i class="fas fa-external-link-alt me-1"></i> Open Full Map
                        </a>
                    </div>
                    <div class="map-frame-wrapper">
                        <iframe 
                            src="https://maps.google.com/maps?q=16.1504006,119.9823707&t=&z=17&ie=UTF8&iwloc=&output=embed" 
                            allowfullscreen="" 
                            loading="lazy" 
                            referrerpolicy="no-referrer-when-downgrade"
                            title="Garden of Remembrance Memorial Park Google Maps Location">
                        </iframe>
                    </div>
                </div>
            </div>
        </div>

        <div class="about-footer-tag">
            Garden of Remembrance Memorial Park Cemetery Information Management System &copy; <?php echo date('Y'); ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('#publicNav a').forEach(function(link) {
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
</script>
</body>
</html>
