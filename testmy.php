<?php
require_once 'config_session.inc.php';
require_once 'db.inc.php';
require_once 'signup_model.inc.php';
require_once 'signup_controller.inc.php';

function upload_file($file, $subdirectory) {
    $target_dir = "../../uploads/" . $subdirectory . "/";

    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $file_extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($file_extension, $allowed_extensions)) {
        error_log("upload_file: rejected extension = " . $file_extension);
        return null;
    }

    $new_filename = uniqid() . '_' . time() . '.' . $file_extension;
    $target_file = $target_dir . $new_filename;

    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return "uploads/" . $subdirectory . "/" . $new_filename;
    }

    error_log("upload_file: move_uploaded_file failed | tmp=" . $file["tmp_name"] . " | target=" . $target_file);
    return null;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../index.php");
    die();
}

// ─── DEBUG: see exactly what PHP received ───────────────────────────────────
error_log("=== FILES RECEIVED ===");
error_log(print_r($_FILES, true));
error_log("=== POST RECEIVED ===");
error_log(print_r($_POST, true));
// ────────────────────────────────────────────────────────────────────────────

// Get role
$role = isset($_POST["user_role_final"]) && $_POST["user_role_final"] !== ''
    ? $_POST["user_role_final"]
    : (isset($_POST["user_role"]) ? $_POST["user_role"] : 'caregiver');

error_log("Final role: " . $role);

// Experience & price only for caregivers
if ($role === 'customer') {
    $experience = null;
    $price      = null;
} else {
    $experience = isset($_POST["experience"]) ? trim($_POST["experience"]) : null;
    $price      = isset($_POST["price"])      ? trim($_POST["price"])      : null;
}

// Common fields
$username    = trim($_POST["Username"]  ?? '');
$nationalID  = trim($_POST["nationalID"] ?? '');
$phone       = trim($_POST["phone"]     ?? '');
$email       = trim($_POST["email"]     ?? '');
$confirmPass =      $_POST["confirmPass"] ?? '';
$password    =      $_POST["password"]    ?? '';
$location    = trim($_POST["location"]  ?? '');
$bio         = trim($_POST["bio"]       ?? '');

// ─── File uploads ────────────────────────────────────────────────────────────
$profile_pic_path = null;
$id_photo_path    = null;
$id_photo_uploaded = false;

// Profile picture (optional)
if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    $profile_pic_path = upload_file($_FILES['profile_picture'], 'profile_pics');
}

// National ID photo (required)
if (!isset($_FILES['id_photo'])) {
    error_log("id_photo: NOT in \$_FILES at all");
} else {
    error_log("id_photo error code: " . $_FILES['id_photo']['error']);
}

if (isset($_FILES['id_photo']) && $_FILES['id_photo']['error'] === UPLOAD_ERR_OK) {
    $id_photo_path = upload_file($_FILES['id_photo'], 'id_photos');
    if ($id_photo_path) {
        $id_photo_uploaded = true;
        error_log("id_photo uploaded successfully: " . $id_photo_path);
    } else {
        error_log("id_photo: upload_file() returned null");
    }
} else {
    $upload_error = $_FILES['id_photo']['error'] ?? 'NOT SET';
    error_log("id_photo condition failed. Error code: " . $upload_error);
}
// ─────────────────────────────────────────────────────────────────────────────

try {
    $errors = [];

    // Common validation
    if (is_input_empty_common($username, $nationalID, $phone, $email, $confirmPass, $password, $location)) {
        $errors["empty_input"] = "Please fill in all fields.";
    }

    if (is_email_invalid($email)) {
        $errors["invalid_email"] = "Please enter a valid email address.";
    }

    if (!preg_match('/^\d{14}$/', $nationalID)) {
        $errors["invalid_national_id"] = "National ID must be exactly 14 digits.";
    }

    if ($password !== $confirmPass) {
        $errors["password_mismatch"] = "Passwords do not match.";
    }

    if (!$id_photo_uploaded || $id_photo_path === null) {
        $errors["id_photo_required"] = "Please upload a clear photo of your National ID. This is required for verification purposes.";
    }

    // Role-based validation
    if ($role === 'caregiver') {
        if (is_username_taken_CG($pdo, $username))         $errors["username_taken"]            = "Username already taken by a caregiver.";
        if (is_email_registered_CG($pdo, $email))          $errors["email_registered"]          = "Email already registered as a caregiver.";
        if (is_national_id_taken_CG($pdo, $nationalID))    $errors["national_id_taken"]         = "National ID already registered as a caregiver.";
        if (is_username_taken_Customer($pdo, $username))   $errors["username_taken_customer"]   = "Username already taken by a care seeker.";
        if (is_email_registered_Customer($pdo, $email))    $errors["email_registered_customer"] = "Email already registered as a care seeker.";
        if (is_national_id_taken_Customer($pdo,$nationalID)) $errors["national_id_taken_customer"] = "National ID already registered as a care seeker.";

        if ($experience === null || $price === null || empty($experience) || empty($price)) {
            $errors["provider_fields_empty"] = "Please fill in experience and price per hour.";
        } else {
            if (!is_numeric($experience) || $experience < 0) $errors["invalid_experience"] = "Enter a valid number for experience.";
            if (!is_numeric($price)      || $price < 0)      $errors["invalid_price"]      = "Enter a valid price per hour.";
        }

    } elseif ($role === 'customer') {
        if (is_username_taken_Customer($pdo, $username))    $errors["username_taken"]            = "Username already taken by a care seeker.";
        if (is_email_registered_Customer($pdo, $email))     $errors["email_registered"]          = "Email already registered as a care seeker.";
        if (is_national_id_taken_Customer($pdo,$nationalID)) $errors["national_id_taken"]        = "National ID already registered as a care seeker.";
        if (is_username_taken_CG($pdo, $username))          $errors["username_taken_caregiver"]  = "Username already taken by a care provider.";
        if (is_email_registered_CG($pdo, $email))           $errors["email_registered_caregiver"]= "Email already registered as a care provider.";
        if (is_national_id_taken_CG($pdo, $nationalID))     $errors["national_id_taken_caregiver"]= "National ID already registered as a care provider.";
    }

    if ($errors) {
        $_SESSION["errors_Signup"] = $errors;
        header("Location: ../index.php");
        die();
    }

    // Create user
    $location_id     = handle_location($pdo, $location);
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    if ($role === 'caregiver') {
        $caregiver_id = create_caregiver(
            $pdo, $username, $email, $hashed_password, $nationalID,
            $phone, $experience, $price, $bio, $profile_pic_path, $id_photo_path, $location_id
        );
        create_user_entry($pdo, $email, $hashed_password, 'caregiver', null, $caregiver_id);

        $_SESSION['user_id']   = $caregiver_id;
        $_SESSION['user_email']= $email;
        $_SESSION['user_type'] = 'caregiver';
        $_SESSION['username']  = $username;
        $_SESSION['user_bio']  = $bio;

        header("Location: ../caregiver_dashboard.php");
        die();

    } elseif ($role === 'customer') {
        $customer_id = create_customer(
            $pdo, $username, $email, $hashed_password, $nationalID,
            $phone, $bio, $profile_pic_path, $id_photo_path, $location_id
        );
        create_user_entry($pdo, $email, $hashed_password, 'customer', $customer_id, null);

        $_SESSION['user_id']   = $customer_id;
        $_SESSION['user_email']= $email;
        $_SESSION['user_type'] = 'customer';
        $_SESSION['username']  = $username;
        $_SESSION['user_bio']  = $bio;

        header("Location: ../customer_dashboard.php");
        die();
    }

} catch (PDOException $e) {
    error_log("Signup PDO error: " . $e->getMessage());
    $_SESSION["errors_Signup"] = ["database_error" => "Signup failed: " . $e->getMessage()];
    header("Location: ../index.php");
    die();
}
?>