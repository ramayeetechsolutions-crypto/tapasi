<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/simplexlsx-master/src/SimpleXLSX.php';
use Shuchkin\SimpleXLSX;

require 'db.php'; // Includes $mysqli connection
?>

<!DOCTYPE html>
<html>
<head>
    <title>Import Excel (2 Fields Only)</title>
</head>
<body>
    <h3>Import Excel File (Only Name & Username)</h3>
    <form method="POST" enctype="multipart/form-data">
        <input type="file" name="excelFile" accept=".xlsx" required>
        <button type="submit" name="import">Import</button>
    </form>

<?php
if (isset($_POST['import'])) {
    if (is_uploaded_file($_FILES['excelFile']['tmp_name'])) {
        $filePath = $_FILES['excelFile']['tmp_name'];

        if ($xlsx = SimpleXLSX::parse($filePath)) {
            $rows = $xlsx->rows();
            
            // Skip header row (start from 2nd row)
            for ($i = 1; $i < count($rows); $i++) {
                $username      = trim($rows[$i][0]); // Column A - Name
                $name  = trim($rows[$i][1]); // Column B - Username

                if ($name != '' && $username != '') {
                    // Generate password as username@2025 and hash it
                    $plain_password = $username . '@2025';
            //        $hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);
              //      $password_hash = password_hash($plain_password, PASSWORD_DEFAULT);
//echo $name."**************".$username."**************".$plain_password."**************".$hashed_password."**************".$password_hash."<br><br>";  
                    // Insert into database
                    $stmt = $mysqli->prepare("INSERT INTO employee_master (employee_name, employee_username, password_hash) VALUES (?, ?, ?)");
                    $stmt->bind_param("sss", $name, $username, $plain_password);
                    $stmt->execute();
                }
            }

            echo "<p style='color:green;'>✅ Data imported successfully with password as username@2025!</p>";
        } else {
            echo '<p style="color:red;">❌ Error: ' . SimpleXLSX::parseError() . '</p>';
        }
    }
}
?>
</body>
</html>
