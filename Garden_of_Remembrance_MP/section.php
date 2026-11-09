<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Section A - Garden of Remembrance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #e0f4ff; font-family: 'Segoe UI', Arial, sans-serif; }
        .section-map-container {
            position: relative;
            display: inline-block;
        }
        .section-map-img {
            max-width: 100%;
            border-radius: 16px;
            box-shadow: 0 2px 16px rgba(33,150,243,0.10);
            border:2px solid #1976d2;
        }
        .plot-btn {
            position: absolute;
            top: 40px; /* Example position, adjust as needed */
            left: 40px; /* Example position, adjust as needed */
            width: 40px;
            height: 40px;
            background: #fff;
            border: 2px solid #1976d2;
            border-radius: 8px;
            color: #1976d2;
            font-weight: bold;
            cursor: pointer;
            opacity: 0.85;
            transition: box-shadow 0.2s;
            z-index:10;
        }
        .plot-btn:hover {
            box-shadow: 0 0 8px #1976d2;
            background: #e3f2fd;
        }

        /* Mobile Responsive Styles */
        @media (max-width: 768px) {
            body {
                font-size: 14px;
            }
            
            .container {
                padding: 1rem;
            }
            
            h2 {
                font-size: 1.25rem;
                margin-bottom: 1rem;
            }
            
            .section-map-container {
                width: 100%;
                margin-bottom: 1rem;
            }
            
            .section-map-img {
                border-radius: 12px;
                border-width: 1.5px;
            }
            
            .plot-btn {
                width: 30px;
                height: 30px;
                font-size: 0.7rem;
                border-width: 1.5px;
                top: 30px;
                left: 30px;
            }
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 0.5rem;
            }
            
            h2 {
                font-size: 1.1rem;
            }
            
            .section-map-img {
                border-radius: 8px;
                border-width: 1px;
            }
            
            .plot-btn {
                width: 25px;
                height: 25px;
                font-size: 0.6rem;
                border-width: 1px;
                top: 25px;
                left: 25px;
            }
        }
    </style>
</head>
<body>
<div class="container py-4">
    <h2 class="mb-4 text-center" style="color:#1976d2; font-weight:bold;">Garden of Remembrance - Section A</h2>
    <div class="section-map-container">
        <img src="sections/section/images/section_a.png.jpg" alt="Section A Map" class="section-map-img">
        <!-- Example plot button at (1,1) -->
        <button class="plot-btn" title="Plot 1,1" onclick="alert('Deceased info for Plot 1,1 (replace with real data)')">1,1</button>
    </div>
</div>
</body>
</html>
