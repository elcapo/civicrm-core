<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 * Class CRM_Core_Payment_ProcessorFormTest
 * @group headless
 */
class CRM_Core_Payment_ProcessorFormTest extends CiviUnitTestCase {
  public function setUp(): void {
    parent::setUp();

    $this->standardProfile = $this->createStandardBillingProfile();
    $this->customProfile = $this->createCustomBillingProfile();

    $this->standardProcessorType = $this->paymentProcessorTypeCreate([
      'class_name' => 'PaymentProcessorWithStandardBillingRequirements',
      'name' => 'StandardBillingType',
    ]);

    $this->customProcessorType = $this->paymentProcessorTypeCreate([
      'class_name' => 'PaymentProcessorWithCustomBillingRequirements',
      'name' => 'CustomBillingType',
    ]);

    $this->standardProcessor = $this->paymentProcessorCreate([
      'name' => 'StandardBilling',
      'class_name' => 'PaymentProcessorWithStandardBillingRequirements',
      'payment_processor_type_id' => $this->standardProcessorType,
      'is_test' => 0,
    ]);

    $this->customProcessor = $this->paymentProcessorCreate([
      'name' => 'CustomBilling',
      'class_name' => 'PaymentProcessorWithCustomBillingRequirements',
      'payment_processor_type_id' => $this->customProcessorType,
      'is_test' => 0,
    ]);
  }

  public function tearDown(): void {
    $this->callAPISuccess('PaymentProcessor', 'delete', [
      'id' => $this->standardProcessor,
    ]);

    $this->callAPISuccess('PaymentProcessor', 'delete', [
      'id' => $this->customProcessor,
    ]);

    $this->callAPISuccess('PaymentProcessorType', 'delete', [
      'id' => $this->standardProcessorType,
    ]);

    $this->callAPISuccess('PaymentProcessorType', 'delete', [
      'id' => $this->customProcessorType,
    ]);

    $this->quickCleanUpFinancialEntities();
    $this->quickCleanup(['civicrm_uf_group', 'civicrm_uf_field']);

    parent::tearDown();
  }

  public function createStandardBillingProfile() {
    return $this->createTestableBillingProfile('standard', TRUE);
  }

  public function createCustomBillingProfile() {
    return $this->createTestableBillingProfile('custom', FALSE);
  }


  public function createTestableBillingProfile($name, $withState) {
    $billingId = CRM_Core_BAO_LocationType::getBilling();

    $profile = $this->callAPISuccess('UFGroup', 'create', [
      'group_type' => 'Contact',
      'title' => "Billing fields: $name",
      'name' => "${name}_billing",
    ]);

    $this->callAPISuccess('UFField', 'create', [
      'uf_group_id' => $profile['id'],
      'field_name' => 'first_name',
      'is_required' => TRUE,
    ]);

    $this->callAPISuccess('UFField', 'create', [
      'uf_group_id' => $profile['id'],
      'field_name' => 'last_name',
      'is_required' => TRUE,
    ]);

    $this->callAPISuccess('UFField', 'create', [
      'uf_group_id' => $profile['id'],
      'field_name' => 'street_address',
      'is_required' => TRUE,
    ]);

    $this->callAPISuccess('UFField', 'create', [
      'uf_group_id' => $profile['id'],
      'field_name' => 'city',
      'location_type_id' => $billingId,
      'is_required' => TRUE,
    ]);

    if ($withState) {
      $this->callAPISuccess('UFField', 'create', [
        'uf_group_id' => $profile['id'],
        'field_name' => 'state_province',
        'location_type_id' => $billingId,
        'is_required' => TRUE,
      ]);  
    }

    $this->callAPISuccess('UFField', 'create', [
      'uf_group_id' => $profile['id'],
      'field_name' => 'postal_code',
      'location_type_id' => $billingId,
      'is_required' => TRUE,
    ]);

    $this->callAPISuccess('UFField', 'create', [
      'uf_group_id' => $profile['id'],
      'field_name' => 'country',
      'location_type_id' => $billingId,
      'is_required' => TRUE,
    ]);

    return $profile;
  }

  /**
   * Checks that, if a payment processor declares the standard
   * billing fields as needed, they must be considered mandatory.
   */
  public function testPaymentProcessorWithStandardBillingRequirements() {
    $processor = new PaymentProcessorWithStandardBillingRequirements();
    $processor->id = $this->standardProcessor;

    $missingBillingFields = [];

    $fields = array_column(
      $this->callAPISuccess('UFField', 'get', ['uf_group_id' => $this->standardProfile['id']])['values'],
      'field_name'
    );

    $fields = array_map(function($field) {
      if (!isset($field['location_type_id'])) {
        return "$field";
      }
      return $field . "-" . $field['location_type_id'];
    }, $fields);

    $canBeHidden = FALSE;
    foreach ((array) $fields as $field) {
      $canBeHidden = CRM_Core_BAO_UFField::assignAddressField(
        $field,
        $missingBillingFields,
        ['uf_group_id' => $this->standardProfile['id']],
        array_keys($processor->getBillingAddressFields())
      );

      if (!$canBeHidden) { break; }
    }

    $this->assertEquals(TRUE, $canBeHidden);
  }

  /**
   * Checks that, if the payment processor doesn't declare a field
   * as needed, the field shouldn't be considered mandatory.
   */
  public function testPaymentProcessorWithCustomRequirements() {
    $processor = new PaymentProcessorWithCustomBillingRequirements();
    $processor->id = $this->customProcessor;

    $missingBillingFields = [];

    $fields = array_column(
      $this->callAPISuccess('UFField', 'get', ['uf_group_id' => $this->customProfile['id']])['values'],
      'field_name'
    );

    $fields = array_map(function($field) {
      if (!isset($field['location_type_id'])) {
        return "$field";
      }
      return $field . "-" . $field['location_type_id'];
    }, $fields);

    $canBeHidden = FALSE;
    foreach ((array) $fields as $field) {
      $canBeHidden = CRM_Core_BAO_UFField::assignAddressField(
        $field,
        $missingBillingFields,
        ['uf_group_id' => $this->customProfile['id']],
        array_keys($processor->getBillingAddressFields())
      );

      if (!$canBeHidden) { break; }
    }

    $this->assertEquals(TRUE, $canBeHidden);
  }
}

class PaymentProcessorWithStandardBillingRequirements extends CRM_Core_Payment
{
  // `checkConfig` is abstract in CRM_Core_Payment
  // so we are forced to implement it
  public function checkConfig() {
  }
}

class PaymentProcessorWithCustomBillingRequirements extends CRM_Core_Payment
{
  // again, `checkConfig` is abstract in CRM_Core_Payment
  // so we are forced to implement it
  public function checkConfig() {
  }

  public function getBillingAddressFields($billingLocationID = NULL) {
    // Note that it intentionally misses the state_province field
    return [
      'first_name' => 'billing_first_name',
      'middle_name' => 'billing_middle_name',
      'last_name' => 'billing_last_name',
      'street_address' => "billing_street_address-{$billingLocationID}",
      'city' => "billing_city-{$billingLocationID}",
      'country' => "billing_country_id-{$billingLocationID}",
      'postal_code' => "billing_postal_code-{$billingLocationID}",
    ];
  }
}
