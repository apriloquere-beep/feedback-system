<?php
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found — Health Facility Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary: #10b981;
            --primary-light: #dcfce7;
            --secondary: #065f46;
            --dark: #1e293b;
            --text: #475569;
            --bg: #f8fafc;
            --error-yellow: #f59e0b;
            --error-light: #fef3c7;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            overflow: hidden;
            text-align: center;
        }

        .bg-blur {
            position: absolute;
            width: 400px;
            height: 400px;
            border-radius: 50%;
            filter: blur(120px);
            opacity: 0.4;
            z-index: -1;
            animation: float 15s infinite ease-in-out;
        }
        .blur1 { background: var(--error-light); top: -10%; left: -10%; }
        .blur2 { background: var(--primary-light); bottom: -10%; right: -10%; }

        @keyframes float {
            0%, 100% { transform: translate(0, 0); }
            50% { transform: translate(30px, -30px); }
        }

        .error-card {
            background: rgba(255, 255, 255, 0.9);
            padding: 60px 50px;
            border-radius: 30px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.05);
            max-width: 500px;
            width: 90%;
            border-top: 5px solid var(--error-yellow);
            position: relative;
            z-index: 1;
        }

        .error-icon {
            font-size: 4rem;
            color: var(--error-yellow);
            margin-bottom: 20px;
            display: inline-block;
        }

        h1 {
            color: var(--dark);
            font-size: 2rem;
            margin-bottom: 15px;
            font-weight: 700;
            letter-spacing: -1px;
        }

        p {
            font-size: 1.1rem;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .actions {
            display: flex;
            gap: 15px;
            justify-content: center;
        }

        .btn {
            padding: 12px 25px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: 2px solid transparent;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.2);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(16, 185, 129, 0.3);
        }

        .btn-outline {
            background: white;
            color: var(--text);
            border-color: #e2e8f0;
        }

        .btn-outline:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
    </style>
</head>
<body>
    <div class="bg-blur blur1"></div>
    <div class="bg-blur blur2"></div>
    
    <div class="error-card">
        <div class="error-icon">
            <i class="fas fa-map-signs"></i>
        </div>
        <h1>Page Not Found</h1>
        <p>The page you are looking for might have been removed, had its name changed, or is temporarily unavailable.</p>
        <div class="actions">
            <a href="home_page.php" class="btn btn-primary"><i class="fas fa-home"></i> Go to Homepage</a>
            <a href="admin/dashboard.php" class="btn btn-outline"><i class="fas fa-chart-line"></i> Dashboard</a>
        </div>
    </div>
</body>
</html>
