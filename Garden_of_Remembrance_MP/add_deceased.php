

<?php
include 'connection.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = strtoupper(trim($_POST['first_name']));
    $middle_name = strtoupper(trim($_POST['middle_name']));
    $last_name = strtoupper(trim($_POST['last_name']));
    $birthday = $_POST['birthday'];
    $born_in = strtoupper(trim($_POST['born_in']));
    $contact_no = trim($_POST['contact_no']);
    $clean_contact = preg_replace('/\D+/', '', $contact_no);
    if (!preg_match('/^09\d{9}$/', $clean_contact)) {
        echo "<script>alert('Contact number must be an 11-digit mobile number starting with 09'); window.history.back();</script>";
        exit;
    }
    $contact_no = $clean_contact;
    $date_died = $_POST['date_died'];
    $section = strtoupper(trim($_POST['section']));

    $full_name = $first_name . ' ' . $middle_name . ' ' . $last_name;

    $sql = "INSERT INTO tbl_deceased (full_name, birth_date, death_date, section, remarks) 
            VALUES ('$full_name', '$birthday', '$date_died', '$section', 'Born in: $born_in | Contact: $contact_no')";

    if ($conn->query($sql)) {
        echo "<script>alert('Deceased person added successfully.'); window.location.href='map.php';</script>";
    } else {
        echo "<script>alert('Error: " . $conn->error . "');</script>";
    }
}
?>

<!DOCTYPE html> 
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Deceased Person</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #e0f4ff;
            font-family: 'Segoe UI', Arial, sans-serif;
        }
        .container {
            max-width: 400px;
            margin: 60px auto;
            background: #fff;
            border-radius: 1rem;
            box-shadow: 0 0 20px rgba(0, 123, 255, 0.08);
            padding: 2rem 1.5rem;
        }
        h2 {
            color: #2196f3;
            margin-bottom: 1.5rem;
            font-weight: 600;
            text-align: center;
        }
        .btn-primary {
            border-radius: 20px;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Add Deceased Person</h2>
    <form method="POST" action="">
        <div class="mb-3">
            <label for="first_name" class="form-label">First Name</label>
            <input type="text" name="first_name" id="first_name" class="form-control" required>
        </div>
        <div class="mb-3">
            <label for="middle_name" class="form-label">Middle Name</label>
            <input type="text" name="middle_name" id="middle_name" class="form-control">
        </div>
        <div class="mb-3">
            <label for="last_name" class="form-label">Last Name</label>
            <input type="text" name="last_name" id="last_name" class="form-control" required>
        </div>
        <div class="mb-3">
            <label for="birthday" class="form-label">Birthday</label>
            <input type="date" name="birthday" id="birthday" class="form-control" required>
        </div>
        <div class="mb-3">
            <label for="born_in" class="form-label">Born In</label>
            <input type="text" name="born_in" id="born_in" class="form-control" required>
        </div>
        <div class="mb-3">
            <label for="contact_no" class="form-label">Contact No. of Family</label>
            <input type="tel" name="contact_no" id="contact_no" class="form-control" placeholder="09XXXXXXXXX" pattern="09[0-9]{9}" maxlength="11" minlength="11" required>
        </div>
        <div class="mb-3">
            <label for="date_died" class="form-label">Date Died</label>
            <input type="date" name="date_died" id="date_died" class="form-control" required>
        </div>
        <div class="mb-3">
            <label for="section" class="form-label">Section</label>
            <input type="text" name="section" id="section" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Add Deceased</button>
    </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var cInput = document.getElementById('contact_no');
    if (cInput) {
        function sanitize(val) {
            var d = String(val || '').replace(/\D/g, '');
            if (!d) return '';
            if (d.startsWith('639')) d = '09' + d.substring(3);
            else if (d.startsWith('9')) d = '09' + d.substring(1);
            else if (d.startsWith('0') && d.length >= 2 && d.charAt(1) !== '9') d = '09' + d.substring(2);
            else if (!d.startsWith('09')) d = '09' + d;
            return d.slice(0, 11);
        }
        cInput.addEventListener('focus', function() {
            if (!this.value || this.value.trim() === '') this.value = '09';
            else this.value = sanitize(this.value);
        });
        cInput.addEventListener('input', function() {
            var d = this.value.replace(/\D/g, '');
            if (!d) { this.value = '09'; return; }
            this.value = sanitize(this.value);
        });
        cInput.addEventListener('keydown', function(e) {
            if ((e.key === 'Backspace' || e.key === 'Delete') && (this.selectionStart <= 2 && this.selectionEnd <= 2)) {
                e.preventDefault();
                this.setSelectionRange(2, 2);
            }
        });
        cInput.addEventListener('blur', function() {
            if (this.value === '09' && !this.hasAttribute('required')) this.value = '';
            else if (this.value) this.value = sanitize(this.value);
        });
        if (cInput.value) cInput.value = sanitize(cInput.value);
    }
    document.querySelector('form').addEventListener('submit', function(e) {
        var val = (document.getElementById('contact_no') ? document.getElementById('contact_no').value : '').replace(/\D/g, '');
        if (!/^09\d{9}$/.test(val)) {
            e.preventDefault();
            alert('Contact number must be an 11-digit mobile number starting with 09 (e.g. 09XXXXXXXXX)');
            document.getElementById('contact_no').focus();
            return false;
        }
    });
});
</script>
</body>
</html>
