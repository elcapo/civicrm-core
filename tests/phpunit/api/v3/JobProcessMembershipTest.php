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
 * Specific tests for the `process_membership` job.
 *
 * @link https://github.com/civicrm/civicrm-core/pull/16298
 * @package CiviCRM_APIv3
 * @subpackage API_Job
 *
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

/**
 * Class api_v3_JobProcessMembershipTest
 * @group headless
 */
class api_v3_JobProcessMembershipTest extends CiviUnitTestCase {
  protected $_apiversion = 3;

  public $DBResetRequired = FALSE;
  public $_entity = 'Job';

  // Caches membership status names in a key, value array
  public $_statuses;

  // Caches membership types in a key, value array
  public $_types;

  // Caches some reference dates
  public $_yesterday;
  public $_today;
  public $_tomorrow;

  public function setUp() {
    parent::setUp();
    $this->loadReferenceDates();
    $this->loadMembershipStatuses();
    $this->loadMembershipTypes();
  }

  public function loadMembershipStatuses() {
    $statuses = civicrm_api3('MembershipStatus', 'get', ['options' => ['limit' => 0]])['values'];
    $this->_statuses = array_map(function($status) { return $status['name']; }, $statuses);
  }

  public function loadMembershipTypes() {
    $this->membershipTypeCreate(['name' => 'General']);
    $this->membershipTypeCreate(['name' => 'Old']);
    $types = civicrm_api3('MembershipType', 'get', ['options' => ['limit' => 0]])['values'];
    $this->_types = array_map(function($type) { return $type['name']; }, $types);
  }

  public function loadReferenceDates() {
    $this->_yesterday = date('Y-m-d', time() - 60 * 60 * 24);
    $this->_today = date('Y-m-d');
    $this->_tomorrow = date('Y-m-d', time() + 60 * 60 * 24);
  }

  public function tearDown() {
    parent::tearDown();

    // For each case, the `old` membershipt type must start as
    // active, so we can assign it (we'll disabled it after
    // assigning it)
    $this->callAPISuccess('MembershipType', 'create', [
      'id' => array_search('Old', $this->_types),
      'is_active' => TRUE,
    ]);  
  }

  /**
   * Creates a membership that is expired but that should be ignored
   * by the process as it is in `deceased` status.
   */
  public function createDeceasedMembershipThatShouldBeExpired() {
      $contactId = $this->individualCreate(['is_deceased' => FALSE]);
      $membershipId = $this->contactMembershipCreate([
        'contact_id' => $contactId,
        'start_date' => $this->_yesterday,
        'end_date' => $this->_yesterday,
      ]);

      $this->callAPISuccess('Membership', 'create', [
        'id' => $membershipId,
        'status_id' => array_search('Deceased', $this->_statuses),
      ]);

      return $membershipId;
  }

  /**
   * Creates a test membership that should be in `grace` status
   * but that won't be updated when the process is executed with
   * the default parameters.
   */
  public function createTestMembershipThatShouldBeGrace() {
    $contactId = $this->individualCreate();
    $membershipId = $this->contactMembershipCreate([
      'contact_id' => $contactId,
      'start_date' => $this->_yesterday,
      'end_date' => $this->_yesterday,
      'is_test' => TRUE,
    ]);

    $this->callAPISuccess('Membership', 'create', [
      'id' => $membershipId,
      'status_id' => array_search('Current', $this->_statuses),
    ]);

    return $membershipId;
  }

  /**
   * Creates a grace membership that should be in `current` status
   * that should be fixed even when the process is executed with
   * the default parameters.
   */
  public function createGraceMembershipThatShouldBeCurrent() {
    $contactId = $this->individualCreate();
    $membershipId = $this->contactMembershipCreate([
      'contact_id' => $contactId,
      'start_date' => $this->_yesterday,
      'end_date' => $this->_tomorrow,
    ]);

    $this->callAPISuccess('Membership', 'create', [
      'id' => $membershipId,
      'status_id' => array_search('Grace', $this->_statuses),
    ]);

    return $membershipId;
  }

  /**
   * Creates a pending membership that should be in `current` status
   * that won't be fixed unless the process is executed
   * with an explicit `exclude_membership_status_ids` list that
   * doesn't include it.
   */
  public function createPendingMembershipThatShouldBeCurrent() {
    $contactId = $this->individualCreate();
    $membershipId = $this->contactMembershipCreate([
      'contact_id' => $contactId,
      'start_date' => $this->_yesterday,
      'end_date' => $this->_tomorrow,
    ]);

    $this->callAPISuccess('Membership', 'create', [
      'id' => $membershipId,
      'status_id' => array_search('Pending', $this->_statuses),
    ]);

    return $membershipId;
  }

  /**
   * Creates a membership that uses an inactive membership type
   * and should be in `current` status.
   */
  public function createOldMembershipThatShouldBeCurrent() {
    $contactId = $this->individualCreate();
    $membershipId = $this->contactMembershipCreate([
      'contact_id' => $contactId,
      'start_date' => $this->_yesterday,
      'end_date' => $this->_tomorrow,
      'membership_type_id' => array_search('Old', $this->_types),
    ]);

    $this->callAPISuccess('Membership', 'create', [
      'id' => $membershipId,
      'status_id' => array_search('Grace', $this->_statuses),
    ]);

    $this->callAPISuccess('MembershipType', 'create', [
      'id' => array_search('Old', $this->_types),
      'is_active' => FALSE,
    ]);

    return $membershipId;
  }

  /**
   * Returns the name of the status of a membership given its id.
   */
  public function getMembershipStatus($membershipId) {
    $membership = $this->callAPISuccess('Membership', 'getsingle', ['id' => $membershipId]);
    $statusId = $membership['status_id'];
    return $this->_statuses[$statusId];
  }

  /**
   * Tests the process defaults:
   * 
   * - exclude_test_memberships:
   *     exclude test memberships from calculations (default = TRUE)
   * - only_active_membership_types:
   *     exclude disabled membership types from calculations (default = TRUE)
   * - exclude_membership_status_ids:
   *     exclude Pending, Cancelled, Expired. Deceased will always be excluded
   */
  public function testTheDefaults() {
    $deceasedId = $this->createDeceasedMembershipThatShouldBeExpired();
    $testId = $this->createTestMembershipThatShouldBeGrace();
    $oldId = $this->createOldMembershipThatShouldBeCurrent();
    $graceId = $this->createGraceMembershipThatShouldBeCurrent();
    $pendingId = $this->createPendingMembershipThatShouldBeCurrent();

    $this->callAPISuccess('job', 'process_membership', []);

    // The deceased and test memberships shouldn't be changed
    $this->assertEquals('Deceased', $this->getMembershipStatus($deceasedId));
    $this->assertEquals('Current', $this->getMembershipStatus($testId));

    // The membership of an inactive type shouldn't be changed
    $this->assertEquals('Grace', $this->getMembershipStatus($oldId));

    // The grace membership should should be changed
    $this->assertEquals('Current', $this->getMembershipStatus($graceId));

    // The pending membership shouldn't be changed
    $this->assertEquals('Pending', $this->getMembershipStatus($pendingId));
  }

  /**
   * Tests including the test memberships:
   * 
   * - exclude_test_memberships:
   *     exclude test memberships from calculations
   */
  public function testIncludingTestMemberships() {
    $deceasedId = $this->createDeceasedMembershipThatShouldBeExpired();
    $testId = $this->createTestMembershipThatShouldBeGrace();
    $oldId = $this->createOldMembershipThatShouldBeCurrent();
    $graceId = $this->createGraceMembershipThatShouldBeCurrent();
    $pendingId = $this->createPendingMembershipThatShouldBeCurrent();

    $this->callAPISuccess('job', 'process_membership', [
      'exclude_test_memberships' => FALSE,
    ]);

    // The deceased membership shouldn't be changed
    $this->assertEquals('Deceased', $this->getMembershipStatus($deceasedId));

    // The test membership should should be changed
    $this->assertEquals('Grace', $this->getMembershipStatus($testId));

    // The membership of an inactive type shouldn't be changed
    $this->assertEquals('Grace', $this->getMembershipStatus($oldId));

    // The grace membership should should be changed
    $this->assertEquals('Current', $this->getMembershipStatus($graceId));

    // The pending membership shouldn't be changed
    $this->assertEquals('Pending', $this->getMembershipStatus($pendingId));
  }

  /**
   * Tests including inactive membership types:
   * 
   * - only_active_membership_types:
   *     exclude disabled membership types from calculations (default = TRUE)
   */
  public function testIncludingInactiveMembershipTypes() {
    $deceasedId = $this->createDeceasedMembershipThatShouldBeExpired();
    $testId = $this->createTestMembershipThatShouldBeGrace();
    $oldId = $this->createOldMembershipThatShouldBeCurrent();
    $graceId = $this->createGraceMembershipThatShouldBeCurrent();
    $pendingId = $this->createPendingMembershipThatShouldBeCurrent();

    $this->callAPISuccess('job', 'process_membership', [
      'only_active_membership_types' => FALSE,
    ]);

    // The deceased and test memberships shouldn't be changed
    $this->assertEquals('Deceased', $this->getMembershipStatus($deceasedId));
    $this->assertEquals('Current', $this->getMembershipStatus($testId));

    // The membership of an inactive type should be changed
    $this->assertEquals('Current', $this->getMembershipStatus($oldId));

    // The grace membership should have been reviewed
    $this->assertEquals('Current', $this->getMembershipStatus($graceId));

    // The pending membership shouldn't be changed
    $this->assertEquals('Pending', $this->getMembershipStatus($pendingId));
  }

  /**
   * Tests explicitly setting the status ids to exclude.
   * 
   * - exclude_membership_status_ids:
   *     exclude Pending, Cancelled, Expired. Deceased will always be excluded
   */
  public function testSpecifyingTheStatusIdsToExclude() {
    $deceasedId = $this->createDeceasedMembershipThatShouldBeExpired();
    $testId = $this->createTestMembershipThatShouldBeGrace();
    $oldId = $this->createOldMembershipThatShouldBeCurrent();
    $graceId = $this->createGraceMembershipThatShouldBeCurrent();
    $pendingId = $this->createPendingMembershipThatShouldBeCurrent();

    $this->callAPISuccess('job', 'process_membership', [
      'exclude_membership_status_ids' => [
        array_search('Cancelled', $this->_statuses),
      ]
    ]);

    // The deceased and test memberships shouldn't be changed
    $this->assertEquals('Deceased', $this->getMembershipStatus($deceasedId));
    $this->assertEquals('Current', $this->getMembershipStatus($testId));

    // The membership of an inactive type shouldn't be changed
    $this->assertEquals('Grace', $this->getMembershipStatus($oldId));

    // The grace membership should should be changed
    $this->assertEquals('Current', $this->getMembershipStatus($graceId));

    // The pending membership should be changed
    $this->assertEquals('Current', $this->getMembershipStatus($pendingId));
  }

}
