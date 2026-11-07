<?php
/**
 * Parent Data Import Script
 * Run this file once to import all parents from the Excel report
 * Access: https://yourdomain.com/import_parents.php
 */

// Include database connection
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Set execution time limit for large imports
set_time_limit(300);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to format phone number (233XXX -> 0XXX)
function formatPhoneNumber($phone) {
    // Remove any non-numeric characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // If starts with 233, replace with 0
    if (substr($phone, 0, 3) === '233') {
        $phone = '0' . substr($phone, 3);
    }
    
    // If starts with +233, replace with 0
    if (substr($phone, 0, 4) === '233') {
        $phone = '0' . substr($phone, 3);
    }
    
    // Ensure phone is at least 9 digits
    if (strlen($phone) < 9) {
        $phone = '0' . $phone;
    }
    
    return $phone;
}

// Start output buffering
ob_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Data Import - QueensPa School</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #ff9a00 0%, #ff4500 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        h1 {
            color: #ff4500;
            margin-bottom: 20px;
        }
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 12px;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #ff4500;
            color: white;
            position: sticky;
            top: 0;
        }
        tr:hover {
            background: #f5f5f5;
        }
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
        }
        .badge-success {
            background: #28a745;
            color: white;
        }
        .badge-warning {
            background: #ffc107;
            color: #333;
        }
        .badge-danger {
            background: #dc3545;
            color: white;
        }
        .progress-bar {
            width: 100%;
            background: #f0f0f0;
            border-radius: 10px;
            overflow: hidden;
            margin: 20px 0;
        }
        .progress-fill {
            background: linear-gradient(to right, #ff4500, #ff8c00);
            height: 30px;
            border-radius: 10px;
            transition: width 0.3s;
            color: white;
            line-height: 30px;
            text-align: center;
            font-size: 14px;
            font-weight: bold;
        }
        button {
            background: linear-gradient(to right, #ff4500, #ff8c00);
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            margin: 20px 0;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255,69,0,0.4);
        }
        .summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
        }
        .summary h3 {
            margin: 0 0 10px 0;
            color: #ff4500;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-users"></i> Parent Data Import</h1>
        
        <?php
        // Check if import was already done
        $check_sql = "SELECT COUNT(*) as parent_count FROM users WHERE role = 'parent'";
        $check_result = mysqli_query($conn, $check_sql);
        $existing_parents = mysqli_fetch_assoc($check_result)['parent_count'] ?? 0;
        
        if ($existing_parents > 0 && !isset($_GET['force'])) {
            echo '<div class="info">';
            echo '<strong>⚠️ Existing Parents Found!</strong><br>';
            echo "There are currently <strong>{$existing_parents}</strong> parent records in the database.<br>";
            echo '<a href="?force=yes" class="btn btn-warning" style="display: inline-block; margin-top: 10px; color: #333;">Click here to force re-import (will delete existing parents)</a>';
            echo '</div>';
        }
        
        if (isset($_GET['force']) && $_GET['force'] === 'yes') {
            // Begin transaction
            mysqli_begin_transaction($conn);
            
            try {
                // STEP 1: Delete existing parents from parents table first (due to foreign key)
                $delete_parents_sql = "DELETE FROM `parents` WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `role` = 'parent')";
                mysqli_query($conn, $delete_parents_sql);
                
                $delete_users_sql = "DELETE FROM `users` WHERE `role` = 'parent'";
                mysqli_query($conn, $delete_users_sql);
                
                // STEP 2: Parent data array with complete information
                $parents_data = [
                    // name, relationship, phone_raw, email, address, occupation
                    ['STELLA BANKESI', 'MOTHER', '233555500040', 'stellahappy@gmail.com', 'AMASAMAN', 'BEAUTICIAN'],
                    ['BEATRICE FORDUOR', 'MOTHER', '233556993273', 'fordourbeatrice89@gmail.com', 'GW-0615-5855', 'HAIR DRESSER'],
                    ['GETRUDE AYIM', 'MOTHER', '233243565245', 'trudyasante1985@gmail.com', 'Odo Junction', 'Trading'],
                    ['SIMON MENSAH', 'FATHER', '233542001827', 'simonmensah2016@gmail.com', 'GW-0208-0289', 'ENTERPRENEUR'],
                    ['EMMANUEL KOFI ACHEAMPONG', 'FATHER', '233505757787', '', 'AMASAMAN', 'BUSINESS MAN'],
                    ['FRANCISCA OWUSU AMPENG', 'MOTHER', '233545547013', '', 'AMASAMAN', 'TRADER'],
                    ['GODFRED HARRISON AMOAH', 'FATHER', '233244077344', '', 'DOBLO GONNO', 'SOFTWARE ENGINEER'],
                    ['EVELYN AGYEKUM', 'MOTHER', '233531282878', '', 'KPOTA', 'TRADER'],
                    ['YAW TAKYI DOMFEH', 'FATHER', '233208516586', 'dyaw885@gmail.com', 'ACCRA', 'QUALITY ASSURANCE'],
                    ['YAA OFORIWAA ODAME', 'MOTHER', '233242543295', 'yaaodame07@gmail.com', 'CRANBERRY ST. KPOTA', ''],
                    ['ALBERT ASARE AMOAH', 'FATHER', '233244619286', 'albertamoah140@gmail.com', 'GW-1091-3207 ONYANSANA', 'BANKER'],
                    ['ELISA NANA ABENA ARTHUR CRANKSON', 'MOTHER', '233544051621', 'elisaasiedu5@gmail.com', 'GW-0504-3758', 'NURSING'],
                    ['GEORGINA ARMAH', 'MOTHER', '233552583350', '', 'GG SIKAPA ENTERPRISE', 'BUSINESS WOMAN'],
                    ['MICHAEL ADU-AFFUL', 'FATHER', '233256689994', 'aduafulmichael7@gmail.com', 'LAPAZ', 'BUSINESS MAN'],
                    ['ABIGAIL OSEI FORDJOUR', 'MOTHER', '233541503330', 'abigailoseifordjour@gmail.com', 'Korlebu', 'MEDICAL LAB TECHNICIAN'],
                    ['KENNETH OWUSU ASIEDU', 'FATHER', '233545551236', '', 'AMASAMAN', 'ALUMNIUM FABRICATOR'],
                    ['DANIEL BAAFI', 'FATHER', '233545935165', '', 'GW-017-3389', 'BUSINESS MAN'],
                    ['EMMANUEL ARMO-ANNOR', 'FATHER', '233246644820', 'emmadex2020@yahoo.com', 'P. O. BOX DS 748', 'SOCIAL WORKER'],
                    ['NELSON THEOPHILUS', 'FATHER', '233558794765', 'OFFICIALTHEO4@GMAIL.COM', 'GW-0617-6803', 'BUILDING TECHNOLOGIST'],
                    ['KWEDWO SEIDU', 'FATHER', '233244765468', '', 'HC764', 'BUSINESS MAN'],
                    ['FRED DUODU', 'FATHER', '233243360726', 'duodufred@gmail.com', 'APEDWA', 'ADMINISTRATOR/PASTOR'],
                    ['MARSHA BOATENG', 'FATHER', '233545089095', 'marshallboateng@gmail.com', 'BEHIND LORD OUR SAVIOUR SCHOOL', 'POLICE'],
                    ['OWUSU-ENSAW PRISCILA', 'MOTHER', '233243586669', 'N/A', 'JOHN TEYE', 'MEDICAL DOCTOR'],
                    ['ISSIFU IBRAHIM', 'FATHER', '233247675656', 'mizadwoayeboah@gmail.com', 'Canada', 'BUSINESS MAN'],
                    ['LYDIA AGYEIWAA', 'MOTHER', '233558858373', 'agyeiwaalydia1675@gmail.com', 'Abloman valantan station', 'J'],
                    ['STEPHEN LARBI DARKOH JNR', 'FATHER', '233551062910', 'STEPHENLARBIDARKOH@GMAIL.COM', 'ACCRA', 'CLINICAL RESEARCH SCIENTIST'],
                    ['SULEMAN OSMAN', 'FATHER', '233243217039', 'suleiinew@gmail.com', 'Accra', 'PUBLIC SERVANT'],
                    ['YVONNE ADOFO', 'MOTHER', '233044739267', 'yvvoneadofo2@yahoo.com', 'UNITED KINGDOM', 'BUSINESS WOMAN'],
                    ['PETER TAMARABRAKUMI', 'FATHER', '233264866027', 'peterebis336699@gmail.com', 'FRENCH MAN JUNCTION', 'BUSINESS MAN'],
                    ['WENDY OTCHERE', 'MOTHER', '233559489355', 'efyamenko@gmail.com', 'GW-0209-6276', 'CIVIL SERVANT'],
                    ['BERNARD ASARE AKYENA', 'FATHER', '233247703890', '', 'GW-0714-9468', 'PLUMBING'],
                    ['ABIGAIL RAZAQ', 'MOTHER', '233540735202', '', 'Amasaman, SawMill', ''],
                    ['GRACE ANIM ADU', 'MOTHER', '233242675184', 'adugrace842@gmail.com', 'Amasaman, SawMill', ''],
                    ['CHARLES YAO ABUSAH', 'FATHER', '233246304566', 'dukecharles57@gmail.com', 'P. O. BOX AM 1, AMASAMAN', 'PUBLIC SERVANT'],
                    ['ABIGAIL ABENA NINSON', 'MOTHER', '23324663725', 'ninson@awdf.org', 'P. O. BOX KN 300 KANESHIE', 'HR PRACTIONER'],
                    ['DANIEL FOSU', 'FATHER', '233244370085', '', 'KPOTA, NEAR THE WORD EVENT CENTRE', 'BUSINESS MAN'],
                    ['MICHAEL YEBOAH-WIAFE', 'FATHER', '233553494055', 'mywiafe@gmail.com', 'P. O. BOX KN 2417 KANESHIE', 'ACCOUNT OFFICER'],
                    ['MABEL GYESI', 'MOTHER', '233537420442', '', 'DOBLO - GONNO', 'FASHION DESIGNER'],
                    ['ASUMAH CHINNIA', 'FATHER', '233241378499', '', 'P. O. BOX 50 TUMU', 'FARMER'],
                    ['CHINNIA ESSHAKU AMIDU', 'FATHER', '233244266228', 'amiduchinnia16@gmail.com', 'P. O. BOX 50 TUMU', 'PUBLIC SERVANT'],
                    ['THOMPSON AGBEWORNU ADEDZE', 'FATHER', '233202729421', 'aadedze@gmail.com', 'P. O. BOX KW 44 KETA', 'REVENUE OFFICER'],
                    ['OBED OHENE BOATENG', 'FATHER', '233249904362', '', 'KPOTA NEAR 105 HOTEL', 'BUSINESS MAN'],
                    ['KINGLEY OTCHERE', 'FATHER', '233554700891', 'kingsley.otchere90@gmail.com', 'ADEIMAN ALHAJI BLOCK FACTORY', 'BUSINESS MAN'],
                    ['BORTEY NII BORKETEY', 'FATHER', '233203670760', 'bingyburg@yahoo.com', 'P. O. BOX OC 998 ODORKOR', 'CHARTERED ACCOUNTANT'],
                    ['DESMOND ASARE', 'FATHER', '233241697827', '', 'PARAKU ESTATE', 'GLASS INSTALLER'],
                    ['RITA AZITWUMWAH', 'MOTHER', '233504113777', 'ritazitwumwaa1@gmail.com', 'UPPER CLASS, DOBLO GONNO', 'CATERING'],
                    ['WILSON ABUSAH', 'FATHER', '233241102192', 'JIDULAABUSAHRHEMA@GMAIL.COM', 'DOBLO - GONNO', 'MARKETER'],
                    ['RAPHAEL QUASHIE', 'FATHER', '233530198214', 'Raphquash64@gmail.com', 'KPOTA, BEHIND THE WORD EVENT CENTRE', 'BUSINESSMAN'],
                    ['FRANK WIAFE', 'FATHER', '233208142211', '', 'GWW-0506-4201', 'CONTRACTOR'],
                    ['DANIEL LAWSON', 'FATHER', '233244202763', 'queenclick5@gmail.com', 'Achiaman Estate', 'Civil Servant'],
                    ['JAMES YAW ASARE', 'FATHER', '233244866708', 'AsareJames742@gmail.com', 'KPOTA, DOBLO GONNO', 'TECHNICIAN'],
                    ['KOFI AMANING', 'FATHER', '233277727225', 'Amakoff.gh@gmail.com', 'JERUSALEM JUNCTION, DOBLO GONNO', 'TRADING'],
                    ['JOANA EFFAH-BOATENG', 'FATHER', '233545588955', 'danbosompem@gmail.com', 'KUMASI', 'TRADING'],
                    ['STANLEY NKWANTABISA', 'FATHER', '233546847012', 'berrymccray47@gmail.com', 'P. O. BOX 11003, ACCRA-NORTH', 'TRADER'],
                    ['ISAAC ANOKYE OWUSU', 'FATHER', '233249963414', 'Obiricomfort87@gmail,com', 'KPOTA JERUSALEM JUNCTION', 'BUSINESSMAN'],
                    ['AMOS KWEKU FOKUO ANNE', 'FATHER', '233597764765', 'Onomahb@yahoo.com', 'KOTOKU', 'BUSINESSMAN'],
                    ['PATRICK AKWASI OPOKU', 'FATHER', '233249531447', '', 'ACCRA', 'SALES OFFICER'],
                    ['ADAMS SFRAKWAA', 'FATHER', '233547014500', '', 'KOTOKU', 'BUSINESSMAN'],
                    ['FRANCIS AZUMAH MORGAN', 'FATHER', '233545923242', 'FRANCISMORGANAZUMA@GMAIL.COM', 'ACCRA', 'BUSINESSMAN'],
                    ['WASILA IDDRISU', 'MOTHER', '233558103817', 'Wasilaiddrisu1994@gmail.com', 'WULENSI', 'NURSING'],
                    ['PATRICK KWEDWO OWUSU', 'FATHER', '233044746666', '', 'UNITED KINGDOM', 'BUSINESSMAN'],
                    ['DIVINE KWAKU SENYO', 'FATHER', '233246488286', 'SENYODIVINE@GMAIL.COM', 'P. O. BOX AN 7855', 'COMMUNICATION DESIGNER'],
                    ['RUTH TAY', 'MOTHER', '233552180008', '', 'ACHIAMAN, NEAR CEMETARY', 'BUSINESS WOMAN'],
                    ['ANITA ABBAM', 'MOTHER', '233544061416', '', 'Amasaman, SawMill', ''],
                    ['LAWRENCE SASU OWARE', 'FATHER', '233018562364', 'Blinking2006@gmail.com', '117 harmony circle Rd siddeville', 'BUSINESS MAN'],
                    ['RICHARD OTU', 'FATHER', '233558537202', 'oturichard@gmail.com', 'KPOTA NEW THE WORD EVENT', 'DRIVER'],
                    ['ADOLF DUNKU', 'FATHER', '233540431674', '', 'GW-0504-4923', 'BUSINESS MAN'],
                    ['AKWESI OWUSU BOAKYE', 'FATHER', '233592700090', 'kwesiowusuboakye9@gmail.com', 'KWASIE - KUMA', 'BUSINESS MAN'],
                    ['JUDE MATTI', 'FATHER', '233202100413', '', 'ONYANSANA PENTECOST JUNCTION', 'MARKETER'],
                    ['WILBERFORCE DANSO-ASIEDU', 'FATHER', '233244342648', 'wilberforceasiedudanso@gmail.com', 'ACCRA', 'SALES MANAGER'],
                    ['DERRICK BOADI SARPONG', 'FATHER', '233246335060', 'pdekghanalimited@gmail.com', 'KPOTA SAWMILL', 'CONSTRUCTOR'],
                    ['VERA GYAMERA', 'FATHER', '233243611119', '', 'DOBLO GONNO', 'TRADING'],
                    ['HANNAH NKWANTABISA', 'MOTHER', '233546548015', 'hannahamponsah306@gmail.com', 'GW-0425-2410', 'TRADER'],
                    ['ABEDENEGO DARKO', 'FATHER', '233546003086', 'DENEGOBOY@yahoo.com', 'GW-0889-1533', 'SELF EMPLOYED'],
                    ['SAMUEL ADOM-SIAW', 'FATHER', '233242501587', 'samksiaw159@yahoo.com', 'Ado Junction', ''],
                    ['STEPHEN DUAH', 'FATHER', '233244961066', 'stepbulb@gmail.com', 'KWASIEKUMA', 'ACCOUNTANT'],
                    ['MARIAMA MOHAMMED', 'MOTHER', '0240829963', '', 'ACHIAMA', 'MAKEUP ARTIST'],
                    ['kWABENA OPOKU', 'FATHER', '233546062329', '', 'HC612 OBEYEYIE JIDAH', 'PLUMBER'],
                    ['SEIDU KWADWO', 'FATHER', '233244765468', '', 'MACETOWN', 'MECHANIC'],
                    ['REGINALD KOFI FRIMPONG FENTENG', 'FATHER', '233541111171', 'Reginaldfrimpong6@Gmail.com', 'KWABENYA', 'PASTOR'],
                    ['MICHAEL KWABENA TONTOH', 'FATHER', '233243352399', 'Tontoh2001@yahoo.com', 'P. O. BOX 10418 ACCRA-NORTH', 'CONTRACTOR'],
                    ['SALAM MAMATA', 'MOTHER', '233244148879', '', 'KPOTA JUNCTION', 'BUSINESSMAN'],
                    ['ASABEA SHEILA', 'MOTHER', '233550351323', '', 'DOBLO GONNO', 'HAIRDRESSER'],
                    ['AMADU ADAM', 'FATHER', '233244156551', '', 'DOBLO GONNO', 'TRADER'],
                    ['AMADU SUMAILA', 'FATHER', '233244156551', '', 'P.O.BOX NT 1769 ACCRA', 'TRADER'],
                    ['PATIENCE ADJANOR', 'MOTHER', '233558795138', '', 'Amasaman, SawMill', ''],
                    ['REBECCA BOATENG', 'AUNTIE', '233556590206', 'miriam.boateng34@yahoo.com', 'Amasaman, SawMill', ''],
                    ['HANNAH ACQUAH', 'MOTHER', '233555577321', 'afua1995@gmail.com', 'Amasaman, SawMill', ''],
                    ['RUTH KUOFIE', 'MOTHER', '233555529324', 'obaaruthkuofie18@gmail.com', 'Amasaman, SawMill', ''],
                    ['CALINA NUNOO', 'GRANDMOTHER', '233549209565', '', 'Amasaman, SawMill', ''],
                    ['ENOCH FREMPONG', 'FATHER', '233547729468', '', 'Amasaman, SawMill', ''],
                    ['MR. AGBLEVOR', 'FATHER', '233249293339', '', 'Amasaman, SawMill', ''],
                    ['BERNICE KESEWAA', 'MOTHER', '233249562514', '', 'Amasaman, SawMill', ''],
                    ['MARY ABA ANSAH', 'MOTHER', '233256821236', '', 'Amasaman, SawMill', ''],
                    ['EMMANUEL APPIAH', 'FATHER', '233247430277', 'appiahnyameso@gmail.com', 'Amasaman, SawMill', ''],
                    ['HENRIETTA OWUSU BOATENG', 'MOTHER', '233246478566', 'hettyboat84@gmail.com', 'Amasaman, SawMill', ''],
                    ['JULIANA SAM', 'MOTHER', '233245482700', '', 'Amasaman, SawMill', ''],
                    ['ABENE AMENYA', 'MOTHER', '233244762667', '', 'Amasaman, SawMill', ''],
                    ['GLORIA OFORIWAA ARYEE', 'MOTHER', '233244384748', '', 'Amasaman, SawMill', ''],
                    ['VICTORIA AMA B. DZAMENU', 'MOTHER', '233244151097', '', 'Amasaman, SawMill', 'TEACHER'],
                    ['EVELYN FREMPONG', 'MOTHER', '233244022575', '', 'Amasaman, SawMill', ''],
                    ['CLEMENT NKANSAH', 'FATHER', '233243916561', 'Clementnkansah75@gmail.com', 'Amasaman, SawMill', ''],
                    ['SETH ANTWI', 'FATHER', '233243578158', 'antwiseth2121@gmail.com', 'Amasaman, SawMill', ''],
                    ['ERNESTINA AGYEMANG', 'MOTHER', '233243302200', '', 'Amasaman, SawMill', ''],
                    ['FRANK ASIAMAH NTIRI', 'FATHER', '233243077298', '', 'Amasaman, SawMill', ''],
                    ['MAVIS ADJELEY OKINE', 'MOTHER', '233241660069', '', 'Amasaman, SawMill', ''],
                ];
                
                // Use array to track unique parents by phone number
                $unique_parents = [];
                $duplicates = [];
                $success_count = 0;
                $error_count = 0;
                $errors = [];
                
                foreach ($parents_data as $parent) {
                    $name = $parent[0];
                    $relationship = $parent[1];
                    $raw_phone = $parent[2];
                    $email = !empty($parent[3]) ? $parent[3] : null;
                    $address = $parent[4];
                    $occupation = !empty($parent[5]) ? $parent[5] : '';
                    
                    // Format phone number
                    $phone = formatPhoneNumber($raw_phone);
                    
                    // Skip duplicate phone numbers
                    if (isset($unique_parents[$phone])) {
                        $duplicates[] = "$name (Phone: $phone) - Duplicate of {$unique_parents[$phone]}";
                        continue;
                    }
                    
                    $unique_parents[$phone] = $name;
                    
                    // Generate email if empty
                    if (empty($email)) {
                        $email = 'parent_' . preg_replace('/[^0-9]/', '', $phone) . '@queens.com';
                    }
                    
                    // Determine gender
                    $gender = ($relationship === 'MOTHER' || $relationship === 'AUNTIE' || $relationship === 'GRANDMOTHER') ? 'female' : 'male';
                    
                    // Insert into users table
                    $user_sql = "INSERT INTO `users` (
                        `name`, `email`, `password`, `role`, `created_at`, `status`, 
                        `phone_number`, `phone`, `address`, `gender`, `force_password_change`
                    ) VALUES (
                        ?, ?, '$2y$10$1OHbyqZWJw92F.p.z8LTq.I/rP8ZTdg2U95QcTOzq/3r8TqkOzBji', 
                        'parent', NOW(), 'active', ?, ?, ?, ?, 1
                    )";
                    
                    $stmt = mysqli_prepare($conn, $user_sql);
                    mysqli_stmt_bind_param($stmt, 'ssssss', $name, $email, $phone, $phone, $address, $gender);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $user_id = mysqli_insert_id($conn);
                        
                        // Insert into parents table
                        $parent_sql = "INSERT INTO `parents` (
                            `user_id`, `parent_name`, `phone_number`, `email`, `occupation`, 
                            `residential_address`, `created_at`, `updated_at`
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, NOW(), NOW()
                        )";
                        
                        $stmt2 = mysqli_prepare($conn, $parent_sql);
                        mysqli_stmt_bind_param($stmt2, 'isssss', $user_id, $name, $phone, $email, $occupation, $address);
                        
                        if (mysqli_stmt_execute($stmt2)) {
                            $success_count++;
                        } else {
                            $error_count++;
                            $errors[] = "Failed to insert into parents table: $name - " . mysqli_error($conn);
                        }
                        mysqli_stmt_close($stmt2);
                    } else {
                        $error_count++;
                        $errors[] = "Failed to insert into users table: $name - " . mysqli_error($conn);
                    }
                    mysqli_stmt_close($stmt);
                }
                
                // Commit transaction
                mysqli_commit($conn);
                
                // Display results
                echo '<div class="success">';
                echo '<strong>✅ Import Completed Successfully!</strong><br>';
                echo "Total parents processed: " . count($parents_data) . "<br>";
                echo "Unique parents inserted: <strong>{$success_count}</strong><br>";
                echo "Duplicates skipped: <strong>" . count($duplicates) . "</strong><br>";
                if ($error_count > 0) {
                    echo "Errors: <strong>{$error_count}</strong><br>";
                }
                echo '</div>';
                
                if (!empty($duplicates)) {
                    echo '<div class="warning" style="background: #fff3cd; color: #856404; padding: 15px; border-radius: 10px; margin: 20px 0;">';
                    echo '<strong>⚠️ Duplicate Parents Skipped:</strong><br>';
                    echo '<ul>';
                    foreach (array_slice($duplicates, 0, 20) as $dup) {
                        echo "<li>{$dup}</li>";
                    }
                    if (count($duplicates) > 20) {
                        echo "<li>... and " . (count($duplicates) - 20) . " more</li>";
                    }
                    echo '</ul>';
                    echo '</div>';
                }
                
                if (!empty($errors)) {
                    echo '<div class="error">';
                    echo '<strong>❌ Errors Occurred:</strong><br>';
                    echo '<ul>';
                    foreach ($errors as $error) {
                        echo "<li>{$error}</li>";
                    }
                    echo '</ul>';
                    echo '</div>';
                }
                
                // Show summary table
                echo '<div class="summary">';
                echo '<h3>📊 Import Summary</h3>';
                echo '<table>';
                echo '<tr><th>Metric</th><th>Count</th></tr>';
                echo '<tr><td>Total Parents in Database</td><td>' . $success_count . '</td></tr>';
                echo '<tr><td>Duplicates Skipped</td><td>' . count($duplicates) . '</td></tr>';
                echo '<tr><td>Errors</td><td>' . $error_count . '</td></tr>';
                echo '</table>';
                echo '</div>';
                
                // Show sample of inserted parents
                $sample_sql = "SELECT id, name, phone_number, email FROM users WHERE role = 'parent' LIMIT 10";
                $sample_result = mysqli_query($conn, $sample_sql);
                
                if (mysqli_num_rows($sample_result) > 0) {
                    echo '<h3>📋 Sample of Inserted Parents (First 10)</h3>';
                    echo '<div class="table-responsive">';
                    echo '<table>';
                    echo '<thead>';
                    echo '<tr><th>ID</th><th>Name</th><th>Phone Number</th><th>Email</th></tr>';
                    echo '</thead><tbody>';
                    while ($row = mysqli_fetch_assoc($sample_result)) {
                        echo '<tr>';
                        echo '<td>' . $row['id'] . '</td>';
                        echo '<td>' . htmlspecialchars($row['name']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['phone_number']) . '</td>';
                        echo '<td>' . htmlspecialchars($row['email']) . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                    echo '</div>';
                }
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                echo '<div class="error">';
                echo '<strong>❌ Import Failed!</strong><br>';
                echo 'Error: ' . $e->getMessage();
                echo '</div>';
            }
        } elseif (!isset($_GET['force'])) {
            // Show confirmation button
            echo '<div class="info">';
            echo '<strong>⚠️ Ready to Import Parents</strong><br>';
            echo 'This will import all parent records from the Excel report into the database.<br>';
            echo 'Phone numbers will be formatted to start with 0 (e.g., 233555500040 → 0555500040).<br>';
            echo 'Duplicate parents (same phone number) will be merged into one record.<br><br>';
            echo '<a href="?force=yes" class="btn btn-primary" style="display: inline-block;">Start Import</a>';
            echo '</div>';
        }
        ?>
    </div>
</body>
</html>

<?php
ob_end_flush();
mysqli_close($conn);
?>