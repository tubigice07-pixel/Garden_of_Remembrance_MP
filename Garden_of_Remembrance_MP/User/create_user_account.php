<?php
require_once __DIR__ . '/../connection.php';

echo "<h2>Create User Account</h2>";

$username = "User";
$password = "User1";
$email = "user@gmail.com";
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

$check_query = $conn->query("SELECT user_id FROM tbl_user WHERE email = '$email' OR first_name = '$username'");
if ($check_query && $check_query->num_rows > 0) {
    echo "<p style='color: orange;'>⚠️ User already exists. Checking details...</p>";

    $existing_user = $check_query->fetch_assoc();
    echo "<p>Existing User ID: " . $existing_user['user_id'] . "</p>";

    $update_query = "UPDATE tbl_user SET 
                     first_name = '$username', 
                     password = '$hashed_password', 
                     role = 'USER',
                     email = '$email'
                     WHERE user_id = " . $existing_user['user_id'];

    if ($conn->query($update_query)) {
        echo "<p style='color: green;'>✅ User account updated successfully!</p>";
    } else {
        echo "<p style='color: red;'>❌ Error updating user: " . $conn->error . "</p>";
    }
} else {

    $insert_query = "INSERT INTO tbl_user (first_name, email, password, role) VALUES ('$username', '$email', '$hashed_password', 'USER')";

    if ($conn->query($insert_query)) {
        echo "<p style='color: green;'>✅ User account created successfully!</p>";
        echo "<p>User ID: " . $conn->insert_id . "</p>";
    } else {
        echo "<p style='color: red;'>❌ Error creating user: " . $conn->error . "</p>";
    }
}

echo "<div style='background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;'>";
echo "<h3>🔐 Login Credentials</h3>";
echo "<p><strong>Username/Email:</strong> $email</p>";
echo "<p><strong>Password:</strong> $password</p>";
echo "<p><strong>Role:</strong> USER</p>";
echo "</div>";

echo "<h3>Verification:</h3>";
$verify_query = $conn->query("SELECT user_id, first_name, email, role FROM tbl_user WHERE email = '$email'");
if ($verify_query && $verify_query->num_rows > 0) {
    $user = $verify_query->fetch_assoc();
    echo "<table border='1' style='border-collapse: collapse; margin: 20px 0;'>";
    echo "<tr><th>User ID</th><th>Name</th><th>Email</th><th>Role</th></tr>";
    echo "<tr>";
    echo "<td>" . $user['user_id'] . "</td>";
    echo "<td>" . htmlspecialchars($user['first_name']) . "</td>";
    echo "<td>" . htmlspecialchars($user['email']) . "</td>";
    echo "<td>" . htmlspecialchars($user['role']) . "</td>";
    echo "</tr>";
    echo "</table>";

    if (password_verify($password, $user['password'] ?? '')) {
        echo "<p style='color: green;'>✅ Password verification test passed!</p>";
    } else {
        echo "<p style='color: red;'>❌ Password verification test failed!</p>";
    }
} else {
    echo "<p style='color: red;'>❌ User not found in database!</p>";
}

echo "<div style='margin-top: 30px;'>";
echo "<a href='index.php' style='background: #7b1113; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🚪 Go to Login Page</a>";
echo "</div>";
?>
