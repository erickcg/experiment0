<?php

include '../ppsdk_include_path.inc';

require_once 'PayPal.php';
require_once 'PayPal/Profile/Handler/Array.php';
require_once 'PayPal/Profile/API.php';

require_once 'PayPal/Type/GetRecurringPaymentsProfileDetailsRequestType.php';
require_once 'PayPal/Type/GetRecurringPaymentsProfileDetailsResponseType.php';

require_once '../lib/constants.inc.php';
require_once '../SampleLogger.php';

define('UNITED_STATES', 'US');

session_start();

$was_submitted = false;

$logger = new SampleLogger('GetRPProfileDetails.php', PEAR_LOG_DEBUG);
$logger->_log('POST variables: '. print_r($_POST, true));

$profile = $_SESSION['APIProfile'];
// $caller = $_SESSION['caller'];

// Verify that user is logged in
if(! isset($profile)) {
   // Not logged in -- Back to the login page

   $logger->_log('You are not logged in;  return to index.php');
   $location = '../index.php';
   header("Location: $location");
} else {
   $logger->_log('Profile from session: '.print_r($profile, true));
}

// Build our request from $_POST
$grppd_request =& PayPal::getType('GetRecurringPaymentsProfileDetailsRequestType');
if (PayPal::isError($crpp_request)) {
   $logger->_log('Error in request: '. print_r($grppd_request, true));
} else {
   $logger->_log('Create request: '. print_r($grppd_request, true));
}

$logger->_log('Initial request: '. print_r($grppd_request, true));

/**
 * Get posted request values
 */
$profileID = $_POST['profileID'];
$grppd_request->setProfileID($profileID);

$caller =& PayPal::getCallerServices($profile);

// Execute SOAP request
$response = $caller->GetRecurringPaymentsProfileDetails($grppd_request);

$ack = $response->getAck();

$logger->_log('Ack='.$ack);

switch($ack) {
   case ACK_SUCCESS:
   case ACK_SUCCESS_WITH_WARNING:
      // Good to break out;
      break;

   default:
      $_SESSION['response'] =& $response;
      $logger->_log('GetRecurringPaymentsProfileDetails failed: ' . print_r($response, true));
      $location = "../ApiError.php?HomeLink=RecurringPayments/RecurringPayments.php&HomeLinkName=RecurringPaymentsHome";
      header("Location: $location");
}

// Otherwise, load the HTML response

require_once 'pages/GetRPProfileDetails.html.php';

?>
