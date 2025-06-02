<?php
// Activation de l'affichage des erreurs pour le debug (À désactiver en production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'Début du traitement.'];

// --- Configuration des domaines de travail autorisés ---
// IMPORTANT: Configurez ici vos domaines de travail réels.
$allowed_work_domains = ["example.com", "votreentreprise.com", "kanbanize.com", "exfo.com"]; // À CONFIGURER

// --- Mapping des services vers les emails destinataires (côté serveur) ---
// Assurez-vous que cela correspond à votre logique de distribution.
$categoryMappingServer = [
    'helpdesk' => [
        'email' => "itsupport_exfo@kanbanize.com",
    ],
    'tsp' => [
        'email' => "tspqc_exfo@kanbanize.com",
    ],
    'npi' => [
        'email' => "npiqc_exfo@kanbanize.com",
    ],
    'master' => [
        'email' => "masterdata_group@example.com", // Email pour les Données Maîtres - À CONFIGURER
    ],
    'vmo' => [ // Nouvelle entrée pour VMO
        'email' => "vmo_exfo@kanbanize.com",
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Récupération et nettoyage basique des données ---
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $user_email_from_form = isset($_POST['email']) ? trim($_POST['email']) : '';
    $employee_title = isset($_POST['employee_title']) ? trim($_POST['employee_title']) : '';
    $request_subject_from_form = isset($_POST['request_subject']) ? trim($_POST['request_subject']) : '';
    $service_key = isset($_POST['service']) ? trim($_POST['service']) : '';
    $service_text = isset($_POST['service_text']) ? trim($_POST['service_text']) : $service_key; // Fallback au cas où
    $subCategory = isset($_POST['subCategory']) ? trim($_POST['subCategory']) : '';
    $system_impacted = isset($_POST['system_impacted']) ? trim($_POST['system_impacted']) : '';
    $observed_issue = isset($_POST['observed_issue']) ? trim($_POST['observed_issue']) : '';
    $urgent = isset($_POST['urgent']) ? trim($_POST['urgent']) : 'no';
    $urgent_details = isset($_POST['urgent_details']) ? trim($_POST['urgent_details']) : '';

    // --- Validation des champs obligatoires de base ---
    if (empty($name) || empty($user_email_from_form) || empty($employee_title) || empty($request_subject_from_form) || empty($service_key)) {
        $response['message'] = "Veuillez remplir tous les champs obligatoires (Nom, Courriel, Titre, Objet, Service).";
        echo json_encode($response);
        exit;
    }

    // --- Validation de l'adresse courriel (format et domaine) ---
    if (!filter_var($user_email_from_form, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = "Format d'adresse courriel invalide.";
        echo json_encode($response);
        exit;
    }

    $email_domain_from_user = strtolower(substr(strrchr($user_email_from_form, "@"), 1));
    if (!in_array($email_domain_from_user, $allowed_work_domains)) {
        $response['message'] = "Veuillez utiliser une adresse courriel de travail valide. Le domaine '" . htmlspecialchars($email_domain_from_user) . "' n'est pas autorisé.";
        echo json_encode($response);
        exit;
    }

    // --- Détermination du destinataire ---
    if (!isset($categoryMappingServer[$service_key]) || empty($categoryMappingServer[$service_key]['email'])) {
        $response['message'] = "Configuration de service invalide ou destinataire non défini pour le service '" . htmlspecialchars($service_text) . "'."; // Utilise service_text pour un message plus clair
        error_log("Destinataire non trouvé pour service (clé): " . $service_key . ", Texte: " . $service_text); // Log pour admin
        echo json_encode($response);
        exit;
    }
    $recipient_email = $categoryMappingServer[$service_key]['email'];

    // --- Construction du sujet et du corps du courriel ---
    $subject_line = $name . ": " . $request_subject_from_form;

    $bodyContent = "=================================\n";
    $bodyContent .= "       Portail des demandes        \n";
    $bodyContent .= "=================================\n\n";
    $bodyContent .= "Nom de l'employé: " . $name . "\n";
    $bodyContent .= "Adresse courriel: " . $user_email_from_form . "\n";
    $bodyContent .= "Titre d’emploi: " . $employee_title . "\n";
    $bodyContent .= "Objet de la demande: " . $request_subject_from_form . "\n\n";
    $bodyContent .= "-------------------------------\n";
    $bodyContent .= "Service: " . $service_text . "\n";
    if (!empty($subCategory)) {
        $bodyContent .= "Type de demande: " . $subCategory . "\n";
    }

    // Inclure les champs additionnels s'ils ont été soumis et ne sont pas vides.
    // Le JS contrôle leur visibilité; s'ils sont soumis, ils sont pertinents pour ce type de demande.
    if (!empty($system_impacted)) {
        $bodyContent .= "Système impacté: " . $system_impacted . "\n";
    }
    if (!empty($observed_issue)) {
        $bodyContent .= "Problème observé:\n" . $observed_issue . "\n";
    }
    
    $bodyContent .= "\n"; // Espace avant la section urgence

    if ($urgent === "yes") {
        $bodyContent .= "***** DEMANDE URGENTE *****\n";
        if (!empty($urgent_details)) {
            $bodyContent .= "Détails urgence:\n" . $urgent_details . "\n";
        }
        $bodyContent .= "***************************\n";
    } else {
        $bodyContent .= "Demande urgente: Non\n";
    }

    // --- En-têtes du courriel ---
    // Il est CRUCIAL que votre serveur soit configuré pour envoyer des emails de cette manière.
    // L'utilisation de l'email de l'utilisateur dans "From" peut entraîner des problèmes de délivrabilité (spam, rejet).
    // Idéalement, utilisez une adresse d'expédition de votre domaine (ex: noreply@votredomaine.com)
    // et mettez l'email de l'utilisateur dans "Reply-To".
    $headers = "From: Portail Demandes <noreply@votredomaine.com>" . "\r\n"; // À CONFIGURER (utiliser une adresse de votre domaine)
    $headers .= "Reply-To: " . $name . " <" . $user_email_from_form . ">" . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n"; // Bonne pratique
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n"; // Spécifier l'encodage

    // --- Envoi du courriel ---
    // La fonction mail() de PHP est basique. Pour une meilleure fiabilité et plus de fonctionnalités (SMTP, HTML emails),
    // envisagez d'utiliser une bibliothèque comme PHPMailer.
    if (mail($recipient_email, "=?UTF-8?B?".base64_encode($subject_line)."?=", $bodyContent, $headers)) { // Encodage du sujet pour les caractères spéciaux
        $response['success'] = true;
        $response['message'] = "Demande envoyée avec succès à " . $recipient_email . ".";
    } else {
        $response['message'] = "Échec de l'envoi du courriel. Le serveur n'a pas pu envoyer le message. Contactez l'administrateur.";
        // Logguer l'erreur pour diagnostic côté serveur
        error_log("Échec de mail() pour : " . $recipient_email . " | Sujet: " . $subject_line . " | Headers: " . $headers);
    }

} else {
    $response['message'] = "Méthode de requête non autorisée.";
}

echo json_encode($response);
exit;
?>
