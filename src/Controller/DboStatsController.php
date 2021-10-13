<?php

namespace Drupal\dbo_stats\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Class DboStatsController.
 */
class DboStatsController extends ControllerBase {

  /**
   * Stats content.
   * Curretly either 'popular' or 'hitsbyrole'.
   *
   * @return string
   *   Return JSON response data.
   */
  public function statsContent($type, $date, $role, $limit, $sort) {

    if (!preg_match('/^popular|hitsbyrole$/', $type)) {
      $data = ['incorrect type'] + ['error' => TRUE];
      return $this->_jsonResponse($data);
    }
    // If the date is not set, use today's date for the filter.
    if (!$date) {
      $time = \Drupal::time()->getRequestTime();
      $date = \Drupal::service('date.formatter')->format($time, 'custom', 'ymd');
    }

    try {
      $db = \Drupal::database();
      $query = $db->select('dbo_stats_counter', 'a');
      $query->fields('a');
      $query->condition('dmy', $date, '=');
      if ($role) {
        $query->condition('role', $role, '=');
      }

      //
      $query->join('node_field_data', 'nfd', 'a.nid = nfd.nid');
      $query->fields('nfd', ['title', 'type', 'status', 'created', 'changed']);

      // $query->condition('dmy', ['200419', '200609'], 'BETWEEN');
      $limit = $limit > 0 ? $limit : 10;
      $query->range(0, $limit);
      $query->orderBy('count', 'DESC');
      $results = $query->execute()->fetchAll();

      if (!empty($results)) {
        $lines = [];
        foreach ($results as $result) {
          switch($type) {
            case 'popular':
              @$lines[$result->nid]['count'] += $result->count;
              @$lines[$result->nid]['nid'] = (int)$result->nid;
              @$lines[$result->nid]['title'] = $result->title;
              @$lines[$result->nid]['type'] = $result->type;
              @$lines[$result->nid]['status'] = $result->status;
              @$lines[$result->nid]['created'] = $result->created;
              @$lines[$result->nid]['changed'] = $result->changed;
              break;
            case 'hitsbyrole':
              @$lines[$result->role]['count'] += $result->count;
              @$lines[$result->role]['role_machine_name'] = $result->role;
              @$lines[$result->role]['role_name'] = $this->_getUserRoles()[$result->role];
              break;
          }

        }

        ($sort == 'asc')? asort($lines) : arsort($lines);

        $data = [];
        foreach ($lines as $k => $v) {
          switch($type) {
            case 'popular':
              $data[] = [
                'node' => $v,
              ];
              break;
            case 'hitsbyrole':
              $data[] = [
                'role' => $v,
              ];
              break;
          }
        }
      }
      else {
        $data = ['no data'];
      }
    }
    catch(\Exception $e) {
      \Drupal::logger('dbo_stats')->warning($e->getMessage());
      $data = [$e->getMessage()] + ['error' => TRUE];
    }

    return $this->_jsonResponse($data);

  }

  /**
   * Returns a JSON response list of user roles, machine names and role names.
   *
   * @return string
   *  The JSON response data.
   */
  public function getRoles() {
    return $this->_jsonResponse($this->_getUserRoles());
  }

  /**
   * Returns user roles and role names.
   *
   * @return array
   *  Associative array of role machine names and role names.
   */
  protected function _getUserRoles() {
    return user_role_names(TRUE);
  }

  /**
   * Returns a new JSON response.
   *
   * @param array $data
   *  Data for the response.
   * @return string
   *  The JSON response data.
   */
  protected function _jsonResponse($data) {
    return new JsonResponse([
      'data' => $data,
      'method' => 'GET',
    ]);
  }

}
