<?php
/**
 * Garden of Remembrance Memorial Park CMIS
 * Scope and Delimitations Downloader (.doc format) - Narrative Paragraph Format (Capstone Friendly)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isCli = (php_sapi_name() === 'cli');
$docTitle = "Garden_of_Remembrance_CMIS_Scope_and_Delimitations.doc";

if (!$isCli) {
    header("Content-Type: application/vnd.ms-word; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"" . $docTitle . "\"");
    header("Cache-Control: private, max-age=0, must-revalidate");
    header("Pragma: public");
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
}

ob_start();
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:w="urn:schemas-microsoft-com:office:word"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<!--[if gte mso 9]>
<xml>
  <w:WordDocument>
    <w:View>Print</w:View>
    <w:Zoom>100</w:Zoom>
    <w:DoNotOptimizeForBrowser/>
  </w:WordDocument>
</xml>
<![endif]-->
<style>
  @page {
    size: 8.5in 11in;
    margin: 1.0in 1.0in 1.0in 1.0in;
    mso-header-margin: 0.5in;
    mso-footer-margin: 0.5in;
  }
  body {
    font-family: 'Times New Roman', Times, serif;
    font-size: 12pt;
    line-height: 1.5;
    color: #000000;
  }
  h1 {
    font-family: 'Times New Roman', Times, serif;
    font-size: 14pt;
    font-weight: bold;
    text-align: center;
    text-transform: uppercase;
    margin-top: 0;
    margin-bottom: 18pt;
  }
  p {
    margin-top: 0;
    margin-bottom: 0;
    text-align: justify;
    text-indent: 0.5in;
    line-height: 2.0;
  }
</style>
</head>
<body>

<h1>Scope and Delimitation</h1>

<p>The study focuses on the development of a Web-Based Cemetery Management and Grave Mapping System Supporting SDG 11 – Sustainable Cities and Communities. The study was conducted at Garden of Remembrance Memorial Park, located at De Guzman Street, Barangay Palamis, Alaminos City, Pangasinan. The study was conducted from the month of July to September 2025 and continued from June to October 2026. The Baseline Process Analysis involved fifty (50) participants, consisting of forty-eight (48) clients, one (1) cemetery administrator, and one (1) staff, while the System Acceptability Evaluation also involved fifty (50) participants, consisting of forty-five (45) clients, one (1) cemetery administrator, one (1) staff, and three (3) technical evaluators. The researchers developed the system to help improve the management of cemetery records, grave plots, reservations, bookings, payments, and cemetery services. The system has two main groups of users: Admin, Staff, and Client. The Admin and Staff have the same management tasks, so the Admin and Staff use one shared dashboard. Through the dashboard, the Admin and Staff can manage deceased records, grave plots, reservations, bookings, payment verifications, user accounts, announcements, notifications, and reports. The Admin and Staff can also manage the cemetery map and check if a grave plot is available, reserved, or occupied. The system also keeps activity logs of important administrative and user transactions to help the Admin and Staff check, track, and audit activities done in the system. Clients can register and log in to use the cemetery services. Clients can search for a deceased person by name and view the grave location on the digital cemetery map. The system also provides GPS-based navigation to help clients find the selected grave inside the memorial park. When a deceased person is searched on the map, the system finds the matching grave plot and highlights the exact plot on the digital map, helping clients locate the grave more easily. Clients can also make reservations and bookings, choose between full payment or flexible installment plans with automated 20% downpayment calculations, request cemetery services, and use supported payment methods such as GCash. The system includes a payment proof upload module where clients submit their GCash reference number, payment date, and transaction receipt screenshot, allowing the Admin and Staff to review and verify the payment before confirming the booking, after which clients can view and print official digital invoices with verification QR codes. Public visitors can also submit guest reservation requests without immediately creating an account, which the Admin and Staff can review and convert into official bookings. The system allows another deceased person to be added to an existing grave and also supports the transfer of remains when allowed by the cemetery's policies and when the required 8-to-10-year interval has been met, when applicable. Email notifications powered by PHPMailer as well as in-app dashboard alerts are also included to give clients updates about bookings, payment approvals, announcements, and other transactions. The system is developed using PHP and MySQL, while HTML, CSS, JavaScript, and Bootstrap are used to create the system's interface. XAMPP is used for local development and testing, while web hosting is used to make the system available online. The system is intended to support SDG 11 – Sustainable Cities and Communities by helping make cemetery information and services more organized, transparent, and easier to access through digital technology. However, this study is limited to the operations of Garden of Remembrance Memorial Park and does not cover the management of other cemeteries. The system is web-based and does not have a separate Android or iOS application, although it can still be opened using any standard mobile web browser. The grave mapping feature is limited to the sections and grave plots recorded in the system. The system currently includes only Sections A to F, which are available and functional in the cemetery map. Sections G to J are still unavailable because they have not yet been opened for use by the management of Garden of Remembrance Memorial Park; therefore, these sections are excluded from the current implementation of the system. The search feature can only highlight a grave based on the information stored in the system. The GPS-based navigation is only meant to help clients find graves inside the memorial park and depends on the accuracy of the device's location services and the availability of an internet connection. The system does not provide full GIS functions, 3D graveyard modeling, drone aerial photogrammetry, or general road and traffic navigation. The activity logs only record activities and transactions that happen inside the system and do not record activities done outside the system. The deceased search feature is also limited to the information stored in the system, and the system does not connect to government or civil registry databases, such as the Philippine Statistics Authority (PSA) or Local Civil Registrar (LCR), to check or verify records. Payment processing is limited to manual cash transactions and GCash payments verified through administrative proof checking; the system does not execute automated bank or e-wallet API webhook fund deductions. Automated communication is limited to email and in-app notifications and does not include an SMS text messaging gateway to send direct text messages to mobile numbers. Email notifications also depend on the availability of the internet connection, mail server reliability, and client spam filters. The system also does not manage off-site mortuary operations, embalming, casket inventory, or chapel wake scheduling. Physical activities such as grave digging, vault construction, burial, interment ceremonies, transfer of remains, and grave maintenance inside the cemetery remain the physical responsibility of authorized cemetery personnel. Finally, the system depends on the accuracy, validity, and completeness of the information entered by the Admin, Staff, and Clients.</p>

</body>
</html>
<?php
$htmlContent = ob_get_clean();

if ($isCli) {
    $outPath = __DIR__ . '/' . $docTitle;
    file_put_contents($outPath, "\xEF\xBB\xBF" . $htmlContent);
    echo "Saved to: " . $outPath . "\n";
} else {
    echo $htmlContent;
    exit;
}
