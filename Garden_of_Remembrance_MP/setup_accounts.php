<?php
include 'connection.php';

echo "<h2>Setup Admin and User Accounts</h2>";

$admin_email = "jasper@gmail.com";
$admin_password = "admin123"; 
$admin_name = "Jasper";
$admin_hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);

$user_email = "user@garden.com";
$user_password = "User1";
$user_name = "User";
$user_hashed_password = password_hash($user_password, PASSWORD_DEFAULT);

echo "<h3>👑 Setting Up Admin Account</h3>";

$check_admin = $conn->query("SELECT user_id FROM tbl_user WHERE email = '$admin_email'");
if ($check_admin && $check_admin->num_rows > 0) {
    echo "<p style='color: orange;'>⚠️ Admin account already exists. Updating...</p>";

    $update_admin = "UPDATE tbl_user SET 
                     first_name = '$admin_name', 
                     password = '$admin_hashed_password', 
                     role = 'ADMIN'
                     WHERE email = '$admin_email'";

    if ($conn->query($update_admin)) {
        echo "<p style='color: green;'>✅ Admin account updated successfully!</p>";
    } else {
        echo "<p style='color: red;'>❌ Error updating admin: " . $conn->error . "</p>";
    }
} else {

    $insert_admin = "INSERT INTO tbl_user (first_name, email, password, role) VALUES ('$admin_name', '$admin_email', '$admin_hashed_password', 'ADMIN')";

    if ($conn->query($insert_admin)) {
        echo "<p style='color: green;'>✅ Admin account created successfully!</p>";
    } else {
        echo "<p style='color: red;'>❌ Error creating admin: " . $conn->error . "</p>";
    }
}

echo "<h3>👤 Setting Up User Account</h3>";

$check_user = $conn->query("SELECT user_id FROM tbl_user WHERE email = '$user_email'");
if ($check_user && $check_user->num_rows > 0) {
    echo "<p style='color: orange;'>⚠️ User account already exists. Updating...</p>";

    $update_user = "UPDATE tbl_user SET 
                    first_name = '$user_name', 
                    password = '$user_hashed_password', 
                    role = 'USER'
                    WHERE email = '$user_email'";

    if ($conn->query($update_user)) {
        echo "<p style='color: green;'>✅ User account updated successfully!</p>";
    } else {
        echo "<p style='color: red;'>❌ Error updating user: " . $conn->error . "</p>";
    }
} else {

    $insert_user = "INSERT INTO tbl_user (first_name, email, password, role) VALUES ('$user_name', '$user_email', '$user_hashed_password', 'USER')";

    if ($conn->query($insert_user)) {
        echo "<p style='color: green;'>✅ User account created successfully!</p>";
    } else {
        echo "<p style='color: red;'>❌ Error creating user: " . $conn->error . "</p>";
    }
}

echo "<div style='display: flex; gap: 20px; margin: 30px 0;'>";
echo "<div style='background: #dc3545; color: white; padding: 20px; border-radius: 10px; flex: 1;'>";
echo "<h3>👑 ADMIN ACCOUNT</h3>";
echo "<p><strong>Email:</strong> $admin_email</p>";
echo "<p><strong>Password:</strong> $admin_password</p>";
echo "<p><strong>Role:</strong> ADMIN</p>";
echo "<p><strong>Redirects to:</strong> dashboard.php</p>";
echo "</div>";

echo "<div style='background: #28a745; color: white; padding: 20px; border-radius: 10px; flex: 1;'>";
echo "<h3>👤 USER ACCOUNT</h3>";
echo "<p><strong>Email:</strong> $user_email</p>";
echo "<p><strong>Password:</strong> $user_password</p>";
echo "<p><strong>Role:</strong> USER</p>";
echo "<p><strong>Redirects to:</strong> user_dashboard.php</p>";
echo "</div>";
echo "</div>";

echo "<h3>🔍 Verification</h3>";
$verify_query = $conn->query("SELECT user_id, first_name, email, role FROM tbl_user WHERE email IN ('$admin_email', '$user_email') ORDER BY role DESC");

if ($verify_query && $verify_query->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse; margin: 20px 0;'>";
    echo "<tr><th>User ID</th><th>Name</th><th>Email</th><th>Role</th><th>Destination</th></tr>";

    while ($user = $verify_query->fetch_assoc()) {
        $destination = ($user['role'] === 'ADMIN') ? 'dashboard.php' : 'user_dashboard.php';
        $role_color = ($user['role'] === 'ADMIN') ? 'red' : 'green';

        echo "<tr>";
        echo "<td>" . $user['user_id'] . "</td>";
        echo "<td>" . htmlspecialchars($user['first_name']) . "</td>";
        echo "<td>" . htmlspecialchars($user['email']) . "</td>";
        echo "<td style='color: $role_color; font-weight: bold;'>" . htmlspecialchars($user['role']) . "</td>";
        echo "<td>" . $destination . "</td>";
        echo "</tr>";
    }
    echo "</table>";

    echo "<h4>🔐 Password Verification Tests</h4>";

    $admin_data = $conn->query("SELECT password FROM tbl_user WHERE email = '$admin_email'")->fetch_assoc();
    if ($admin_data && password_verify($admin_password, $admin_data['password'])) {
        echo "<p style='color: green;'>✅ Admin password verification: PASSED</p>";
    } else {
        echo "<p style='color: red;'>❌ Admin password verification: FAILED</p>";
    }

    $user_data = $conn->query("SELECT password FROM tbl_user WHERE email = '$user_email'")->fetch_assoc();
    if ($user_data && password_verify($user_password, $user_data['password'])) {
        echo "<p style='color: green;'>✅ User password verification: PASSED</p>";
    } else {
        echo "<p style='color: red;'>❌ User password verification: FAILED</p>";
    }
} else {
    echo "<p style='color: red;'>❌ Accounts not found in database!</p>";
}

echo "<div style='margin-top: 30px; text-align: center;'>";
echo "<a href='index.php' style='background: #7b1113; color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; font-size: 16px;'>🚪 Go to Login Page</a>";
echo "</div>";

echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;'>";
echo "<h4>📋 How It Works:</h4>";
echo "<ul>";
echo "<li><strong>Admin Login:</strong> jasper@gmail.com → dashboard.php (full admin access)</li>";
echo "<li><strong>User Login:</strong> user@garden.com → user_dashboard.php (limited user access)</li>";
echo "<li>The system automatically redirects based on the user's role</li>";
echo "</ul>";
echo "</div>";
?>
