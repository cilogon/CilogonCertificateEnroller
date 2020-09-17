<?php
/**
 * COmanage Registry Cilogon Certificate Enroller
 *
 * @since         COmanage Registry 3.3.0
 * @version       1.0
 */

App::uses('CoPetitionsController', 'Controller');

class CilogonCertificateEnrollerCoPetitionsController extends CoPetitionsController {
  // Class name, used by Cake
  public $name = "CilogonCertificateEnrollerCoPetitions";
  public $uses = array(
    'CoPetition',
    'CertificateAuthenticator.CertificateAuthenticator',
    'CertificateAuthenticator.Certificate');


  protected function execute_plugin_finalize($id, $onFinish) {
    $logPrefix = "CilogonCertificateEnrollerCoPetitionsController execute_plugin_finalize ";
    $errorFlashText = "There was an error processing the enrollment petition.";

    $this->log($logPrefix . "Called with petition id $id");

    // Use the petition id to find the petition.
    $args = array();
    $args['conditions']['CoPetition.id'] = $id;
    $args['contain'] = false;
    $coPetition = $this->CoPetition->find('first', $args);
    if (empty($coPetition)) {
      $this->log($logPrefix . "Could not find petition with id $id");
      $this->Flash->set($errorFlashText, array('key' => 'error'));
      $this->redirect("/auth/logout");
      return;
    }

    $this->log($logPrefix . "Found petition " . print_r($coPetition, true));

    // XXX Only run if enrollment flow ID on petition is the "right" one...
    
    $requiredEnrollmentFlowId = Configure::read('CilogonCertificateEnroller.enrollment_flow_id');
    $this->log($logPrefix . "Required enrollment flow ID is " . print_r($requiredEnrollmentFlowId, true));

    // Use the petition to find the CoPerson Id.
    if (isset($coPetition['CoPetition']['enrollee_co_person_id'])) {
      $coPersonId = $coPetition['CoPetition']['enrollee_co_person_id'];
      $this->log($logPrefix . "CoPerson id is $coPersonId");
    } else {
      $this->log($logPrefix . "Could not find CoPerson from petition with id $id");
      $this->Flash->set($errorFlashText, array('key' => 'error'));
      $this->redirect("/auth/logout");
      return;
    }

    // Find the certifificate DN from the environment.
    $dnGridFormat = env('OIDC_CLAIM_cert_subject_dn');

    if (empty($dnGridFormat)) {
      $this->log($logPrefix . "Could not find OIDC_CLAIM_cert_subject_dn in enviroment");
      $this->Flash->set($errorFlashText, array('key' => 'error'));
      $this->redirect("/auth/logout");
      return;
    }
    $this->log($logPrefix . "Found grid format certificate subject DN $dnGridFormat");

    $dn = substr(implode(',', array_reverse(explode('/', $dnGridFormat))), 0, -1);
    $this->log($logPrefix . "Certificate subject DN is $dn");

    // Find all existing certificates for the CoPerson. For now assume there is
    // only one type of certificate authenticator so there is no condition
    // for certificate_authenticator_id.
    $args = array();
    $args['conditions']['Certificate.co_person_id'] = $coPersonId;

    $existingCertificates = $this->Certificate->find('all', $args);
    if($existingCertificates) {
      $this->log($logPrefix . "Found existing certificates " . print_r($existingCertificates, true));
    }

    // If a certificate with the CILogon subject already exists then we just continue.
    foreach($existingCertificates as $cert) {
      if($cert['Certificate']['subject_dn'] == $dn) {
        $this->log($logPrefix . "Certificate with DN $dn already exists, no new certificate will be added");
        $this->redirect($onFinish);
      }
    }

    // Find the CertificateAuthenticator 
    $certDescription = Configure::read('CilogonCertificateEnroller.certificate_authenticator_description');
    $certAuthId = null;
    $args = array();
    $authenticators = $this->CertificateAuthenticator->find('all', $args);
    foreach($authenticators as $a) {
      if($a['Authenticator']['description'] == $certDescription) {
        $certAuthId = $a['CertificateAuthenticator']['id'];
        break;
      }
    }

    if(is_null($certAuthId)) {
      $this->log($logPrefix . "Could not find CertificateAuthenticator with description $certDescription");
      $this->Flash->set($errorFlashText, array('key' => 'error'));
      $this->redirect("/auth/logout");
    }

    $this->log($logPrefix . "Found authenticators " . print_r($authenticators, true));

    // Create a new certificate.
    $newCert = array();
    $newCert['Certificate']['certificate_authenticator_id'] = $certAuthId;
    $newCert['Certificate']['co_person_id'] = $coPersonId;
    $newCert['Certificate']['description'] = $certDescription;
    $newCert['Certificate']['subject_dn'] = $dn;

    if(!($this->Certificate->save($newCert))) {
      $this->log($logPrefix . "Error saving certificate with DN $dn for CoPerson with Id $coPersonId");
      $this->Flash->set($errorFlashText, array('key' => 'error'));
      $this->redirect("/auth/logout");
    }

    $this->log($logPrefix . "Saved certificate " . print_r($newCert, true));

    $this->redirect($onFinish);
  }
}
